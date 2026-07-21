<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistSignal;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\Ai\AiProposal;
use Platform\FoodAlchemist\Support\SignalCockpit;

/**
 * „KI erledigen lassen" — die Ausführung hinter dem Cockpit-Knopf.
 *
 * Zwei Arten (Plan aus SignalCockpit::planFor, metrik-fein):
 *  - deterministic → execute(): mutiert den VOLLEN betroffenen Satz (scoped über
 *    DataQualityService::betroffene) via die bestehenden Kern-Services, misst die
 *    Metrik neu und schließt das Signal (count 0) bzw. lässt es mit frischem Count offen.
 *    Ehrliche Teil-Fixes: nur Auflösbares wird geheilt, echte Lücken bleiben.
 *  - assist → assist(): EIN LLM-propose()-Call erzeugt einen Entwurf/Vorschlag
 *    (transient, keine Mutation, kein Auto-Close). Läuft über den Core-LLMProviderContract;
 *    ohne Provider/deaktiviert wirft propose() eine RuntimeException (Aufrufer zeigt sie).
 *
 * Keine eigene Regel-/Query-Logik: ruft ausschließlich die bestehenden Services
 * (GpAggregate, LeadLa, Pairing, RecipeRecompute) — eine Regel-Stelle je Domäne.
 */
class SignalFixService
{
    public function __construct(
        private DataQualityService $dq,
        private GpAggregateService $gpAgg,
        private LeadLaService $leadLa,
        private PairingService $pairing,
        private RecipeRecomputeService $recompute,
        private PriceService $preise,
        private SignalService $signals,
        private AiGatewayService $ki,
    ) {
    }

    /**
     * Deterministischer Fix über den vollen betroffenen Satz. Danach Metrik neu messen
     * → Signal schließen (count 0) oder Count/Titel aktualisieren.
     *
     * @return array{ok:bool,kind:string,fixed:int,remaining:int,closed:bool}
     */
    public function execute(Team $team, FoodAlchemistSignal $sig): array
    {
        $plan = SignalCockpit::planFor($sig);
        if ($plan === null || $plan['kind'] !== 'deterministic') {
            throw new \RuntimeException('Für dieses Signal gibt es keinen automatischen Fix.');
        }
        $metrik = $plan['metrik'];
        $fixer = $plan['fixer'];

        $fixed = 0;
        foreach ($this->dq->betroffene($team, $metrik, 100000) as $it) {
            try {
                if ($this->applyFixer($team, $fixer, $it)) {
                    $fixed++;
                }
            } catch (\Throwable) {
                // Einzelfehler darf den Lauf nicht reißen (best effort, wie recomputeAndPropagate/I8).
            }
        }

        // Aggregat-Signale (DataQuality): Count/Titel frisch ziehen; danach ggf. schließen.
        try {
            $this->dq->emittiereSignale($team);
        } catch (\Throwable) {
        }
        $remaining = $this->dq->countFor($team, $metrik);
        $closed = false;
        if ($remaining === 0) {
            $this->signals->abschliessen($team, (int) $sig->id);
            $closed = true;
        }

        return ['ok' => true, 'kind' => 'deterministic', 'fixed' => $fixed, 'remaining' => $remaining, 'closed' => $closed];
    }

    /**
     * KI-Assistenz: ein propose()-Call → Entwurf/Vorschlag (transient). Keine Mutation,
     * kein Auto-Close. Wirft RuntimeException, wenn KI deaktiviert/kein Provider.
     *
     * @return array{ok:bool,kind:string,draft:string,confidence:float,reasoning:?string}
     */
    public function assist(Team $team, FoodAlchemistSignal $sig): array
    {
        $plan = SignalCockpit::planFor($sig);
        if ($plan === null || $plan['kind'] !== 'assist') {
            throw new \RuntimeException('Für dieses Signal gibt es keinen KI-Assistenz-Schritt.');
        }

        $pl = is_array($sig->payload) ? $sig->payload : [];
        $context = array_filter([
            'signal_typ' => $sig->type->label(),
            'titel' => $sig->title,
            'beschreibung' => $sig->description,
            'payload' => $pl !== [] ? $pl : null,
        ], fn ($v) => $v !== null);

        $metrik = SignalCockpit::metrik($sig);
        if ($metrik !== null) {
            $context['beispiele'] = array_map(fn ($i) => $i['name'], $this->dq->betroffene($team, $metrik, 15));
        }

        $p = $this->ki->propose($plan['prompt'], $context, []);

        return ['ok' => true, 'kind' => 'assist', 'draft' => $this->extractDraft($p),
            'confidence' => $p->confidence, 'reasoning' => $p->reasoning];
    }

    // ── deterministische Fixer (rufen NUR bestehende Kern-Services) ────────

    /** @param array{kind:string,id:int,name:string,is_sales_recipe:bool} $it */
    private function applyFixer(Team $team, string $fixer, array $it): bool
    {
        return match ($fixer) {
            'allergen' => $this->fixAllergen($team, (int) $it['id']),
            'lead_la' => $this->fixLeadLa($team, (int) $it['id']),
            'recipe_anker' => $this->fixRecipeAnker($team, (int) $it['id']),
            'gp_anker' => $this->fixGpAnker($team, (int) $it['id']),
            'recompute' => $this->fixRecompute((int) $it['id']),
            default => false,
        };
    }

    private function fixAllergen(Team $team, int $gpId): bool
    {
        $gp = FoodAlchemistGp::visibleToTeam($team)->find($gpId);

        return $gp !== null && $this->gpAgg->backfillAllergenKonfidenz($gp, true)['written'];
    }

    /** Lead-LA-Repick chirurgisch (wie LeadLaRepickCommand): nur setzen, wenn neu auf Preis auflöst; + Recompute. */
    private function fixLeadLa(Team $team, int $gpId): bool
    {
        $gp = FoodAlchemistGp::visibleToTeam($team)->find($gpId);
        if ($gp === null || $this->preisLoestAuf($gp->lead_la_supplier_item_id)) {
            return false;   // Lead löst bereits auf → unangetastet (kein Churn)
        }
        $neu = $this->leadLa->pickLeadLa($gp, $team);
        if ($neu === null || (int) $neu === (int) $gp->lead_la_supplier_item_id || ! $this->preisLoestAuf($neu)) {
            return false;   // Park — kein bepreister LA (echte Sourcing-Lücke)
        }
        $this->leadLa->setLeadLa($team, $gp, (int) $neu, 'KI-Signalfix', true);   // + Recompute der Nutzer

        return true;
    }

    private function preisLoestAuf(?int $laId): bool
    {
        if ($laId === null) {
            return false;
        }
        $p = $this->preise->activeFor($laId);

        return $p !== null && (float) $p->price > 0;
    }

    /** Kern-Anker je Rezept aus resolveRecipeAnchors → setRecipeAnker (macht Rezept graph-sichtbar). */
    private function fixRecipeAnker(Team $team, int $recipeId): bool
    {
        $recipe = FoodAlchemistRecipe::visibleToTeam($team)->find($recipeId);
        if ($recipe === null) {
            return false;
        }
        $kerne = [];
        foreach ($this->pairing->resolveRecipeAnchors($recipe) as $zeile) {
            if (($zeile['kern'] ?? null) !== null) {
                $kerne[(int) $zeile['kern']] = true;
            }
        }
        if ($kerne === []) {
            return false;
        }
        $wrote = false;
        foreach (array_keys($kerne) as $ankerId) {
            try {
                $this->pairing->setRecipeAnker($team, $recipeId, (int) $ankerId);
                $wrote = true;
            } catch (\RuntimeException) {
                break;   // CAP_RECIPE erreicht — Rest ignorieren
            }
        }

        return $wrote;
    }

    private function fixGpAnker(Team $team, int $gpId): bool
    {
        $gp = FoodAlchemistGp::visibleToTeam($team)->find($gpId);
        if ($gp === null) {
            return false;
        }
        $ankerId = $this->pairing->resolveByName((string) $gp->name);
        if ($ankerId === null) {
            return false;   // Name löst auf keinen Anker auf (Vokabular-Lücke)
        }
        $this->pairing->setGpAnker($team, $gpId, (int) $ankerId);

        return true;
    }

    private function fixRecompute(int $recipeId): bool
    {
        $this->recompute->recomputeAndPropagate($recipeId);

        return true;
    }

    /** Entwurf aus dem Proposal ziehen — robust gegen unterschiedliche werte-Schemata. */
    private function extractDraft(AiProposal $p): string
    {
        foreach (['text', 'entwurf', 'vorschlag', 'mail', 'draft', 'empfehlung'] as $k) {
            if (isset($p->werte[$k]) && is_string($p->werte[$k]) && trim($p->werte[$k]) !== '') {
                return $p->werte[$k];
            }
        }
        $strings = array_filter($p->werte, fn ($v) => is_string($v) && trim($v) !== '');
        if ($strings !== []) {
            return implode("\n\n", $strings);
        }

        return $p->reasoning ?: (string) json_encode($p->werte, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
