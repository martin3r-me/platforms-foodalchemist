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
 *  - deterministic → execute(): mutiert den betroffenen Satz (scoped über
 *    DataQualityService::betroffene) via die bestehenden Kern-Services, misst die
 *    Metrik neu und schließt das Signal (count 0) bzw. lässt es mit frischem Count offen.
 *    Ehrliche Teil-Fixes: nur Auflösbares wird geheilt, echte Lücken bleiben.
 *  - assist → assist(): EIN LLM-propose()-Call erzeugt einen Entwurf/Vorschlag
 *    (transient, keine Mutation, kein Auto-Close). Läuft über den Core-LLMProviderContract;
 *    ohne Provider/deaktiviert wirft propose() eine RuntimeException (Aufrufer zeigt sie).
 *
 * Spec 21 · Tranche P (Etappe S3b) ergänzt zwei Fähigkeiten am DERSELBEN Pfad:
 *  - **Teilmenge** (§7 Punkt 7): `$ids` an `execute()` schneidet den betroffenen Satz auf
 *    eine Auswahl. Die Auswahl wird IMMER gegen `betroffene()` geschnitten — eine ID, die
 *    das Metrik-Prädikat nicht (mehr) trifft, wird nie angefasst. Damit ist der Fixer auch
 *    aus dem Panel heraus nicht zur beliebigen Schreib-Schnittstelle geworden.
 *  - **Dry-Run** (§7 Punkt 3): `vorschau()` beantwortet „n Objekte, diese Felder, diese
 *    Werte" VOR dem Klick — je Fixer eine rein lesende Spiegelung von `applyFixer`.
 *    Vorsätzlich als Zwilling im selben Service und nicht als eigene Klasse: eine
 *    Vorschau, die anderswo lebt, driftet vom Fixer weg und lügt dann.
 *
 * Keine eigene Regel-/Query-Logik: ruft ausschließlich die bestehenden Services
 * (GpAggregate, LeadLa, Pairing, RecipeRecompute) — eine Regel-Stelle je Domäne.
 */
class SignalFixService
{
    /** Wie viele Objekte der Dry-Run einzeln aufschlüsselt (darüber: ehrlich „… und n weitere"). */
    public const VORSCHAU_LIMIT = 25;

    /** Obergrenze, bis zu der eine Auswahl im betroffenen Satz gesucht wird (= Panel-Liste). */
    private const AUSWAHL_SUCHTIEFE = SignalObjectService::PANEL_LIMIT;

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
     * Deterministischer Fix über den betroffenen Satz — voll (`$ids === null`) oder auf eine
     * Auswahl geschnitten (Teil-Bulk, Spec 21 §7 Punkt 7). Danach Metrik neu messen
     * → Signal schließen (count 0) oder Count/Titel aktualisieren.
     *
     * Der Auto-Close hängt bewusst am frisch gemessenen Count und NICHT am Scope: eine
     * Teilmenge, die zufällig den letzten offenen Fall heilt, darf das Signal schließen —
     * andernfalls bliebe ein Signal mit 0 Treffern als Phantom stehen (vgl. V-011).
     *
     * @param  list<int>|null  $ids  Auswahl von Objekt-IDs; wird gegen betroffene() geschnitten
     * @return array{ok:bool,kind:string,scope:string,angefragt:int,fixed:int,remaining:int,closed:bool}
     */
    public function execute(Team $team, FoodAlchemistSignal $sig, ?array $ids = null): array
    {
        $plan = SignalCockpit::planFor($sig);
        if ($plan === null || $plan['kind'] !== 'deterministic') {
            throw new \RuntimeException('Für dieses Signal gibt es keinen automatischen Fix.');
        }
        $metrik = $plan['metrik'];
        $fixer = $plan['fixer'];

        $satz = $this->satz($team, $metrik, $ids);

        $fixed = 0;
        foreach ($satz as $it) {
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

        return ['ok' => true, 'kind' => 'deterministic', 'scope' => $ids === null ? 'alle' : 'teilmenge',
            'angefragt' => $ids === null ? count($satz) : count($ids), 'fixed' => $fixed,
            'remaining' => $remaining, 'closed' => $closed];
    }

    /**
     * Dry-Run vor dem Klick (Spec 21 §7 Punkt 3): „n Objekte, diese Felder, diese Werte".
     * Mutiert nichts — jeder Zweig spiegelt seinen Fixer rein lesend.
     *
     * `wirkt`/`wirkt_nicht` zählen nur über die AUFGESCHLÜSSELTEN Objekte (bis
     * VORSCHAU_LIMIT), nicht über den ganzen Satz: für jedes Objekt fällt mindestens eine
     * Auflöse-Frage an (Anker-Resolve, Lead-Pick, Kaskaden-BFS) — das über 3.000 Zeilen zu
     * fahren, nur um eine Zahl zu zeigen, wäre so teuer wie der Fix selbst. Die Kappung
     * wird in der Antwort ausgewiesen, damit sie nicht als Vollaussage gelesen wird.
     *
     * @param  list<int>|null  $ids  Auswahl (Teil-Bulk-Vorschau) oder null = ganzer Satz
     * @return array{ok:bool,kind:string,fixer:string,plan:string,scope:string,total:int,gezeigt:int,wirkt:int,wirkt_nicht:int,items:list<array{kind:string,id:int,name:string,is_sales_recipe:bool,wirkt:bool,felder:array<string,string>,hinweis:?string}>}
     */
    public function vorschau(Team $team, FoodAlchemistSignal $sig, ?array $ids = null, int $limit = self::VORSCHAU_LIMIT): array
    {
        $plan = SignalCockpit::planFor($sig);
        if ($plan === null || $plan['kind'] !== 'deterministic') {
            throw new \RuntimeException('Eine Fix-Vorschau gibt es nur für automatische Fixes.');
        }
        $metrik = $plan['metrik'];
        $fixer = $plan['fixer'];

        $satz = $this->satz($team, $metrik, $ids);
        // total: bei Auswahl die Auswahl selbst, sonst live nachzählen (Panel-Kappung
        // darf die Gesamtzahl nicht kleiner erscheinen lassen als sie ist).
        $total = $ids === null ? $this->dq->countFor($team, $metrik) : count($satz);

        $items = [];
        $wirkt = 0;
        foreach (array_slice($satz, 0, max(1, $limit)) as $it) {
            try {
                $d = $this->dryRunFixer($team, $fixer, $it);
            } catch (\Throwable $e) {
                $d = ['wirkt' => false, 'felder' => [], 'hinweis' => 'Vorschau nicht auflösbar: ' . $e->getMessage()];
            }
            $wirkt += $d['wirkt'] ? 1 : 0;
            $items[] = $it + $d;
        }

        return ['ok' => true, 'kind' => 'deterministic', 'fixer' => $fixer, 'plan' => $plan['plan'],
            'scope' => $ids === null ? 'alle' : 'teilmenge', 'total' => $total, 'gezeigt' => count($items),
            'wirkt' => $wirkt, 'wirkt_nicht' => count($items) - $wirkt, 'items' => $items];
    }

    /**
     * Der zu bearbeitende Satz — eine Stelle für Fix und Vorschau (kein Drift).
     * Mit Auswahl wird gegen das Metrik-Prädikat geschnitten: das Panel darf nur
     * anstoßen, was der Detektor auch sieht.
     *
     * @param  list<int>|null  $ids
     * @return list<array{kind:string,id:int,name:string,is_sales_recipe:bool}>
     */
    private function satz(Team $team, string $metrik, ?array $ids): array
    {
        if ($ids === null) {
            return $this->dq->betroffene($team, $metrik, 100000);
        }
        $erlaubt = array_flip(array_map('intval', $ids));

        return array_values(array_filter(
            $this->dq->betroffene($team, $metrik, self::AUSWAHL_SUCHTIEFE),
            fn (array $it) => isset($erlaubt[(int) $it['id']])
        ));
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

    // ── Dry-Run-Zwillinge (rein lesend, spiegeln applyFixer 1:1) ───────────

    /**
     * @param  array{kind:string,id:int,name:string,is_sales_recipe:bool}  $it
     * @return array{wirkt:bool,felder:array<string,string>,hinweis:?string}
     */
    private function dryRunFixer(Team $team, string $fixer, array $it): array
    {
        return match ($fixer) {
            'allergen' => $this->dryAllergen($team, (int) $it['id']),
            'lead_la' => $this->dryLeadLa($team, (int) $it['id']),
            'recipe_anker' => $this->dryRecipeAnker($team, (int) $it['id']),
            'gp_anker' => $this->dryGpAnker($team, (int) $it['id']),
            'recompute' => $this->dryRecompute((int) $it['id']),
            default => ['wirkt' => false, 'felder' => [], 'hinweis' => 'Unbekannter Fixer.'],
        };
    }

    private function dryAllergen(Team $team, int $gpId): array
    {
        $gp = FoodAlchemistGp::visibleToTeam($team)->find($gpId);
        if ($gp === null) {
            return ['wirkt' => false, 'felder' => [], 'hinweis' => 'GP nicht (mehr) sichtbar.'];
        }
        $r = $this->gpAgg->backfillAllergenKonfidenz($gp, false);   // apply=false ⇒ nur rechnen
        if ($r['skipped']) {
            return ['wirkt' => false, 'felder' => [],
                'hinweis' => 'Allergene sind manuell/KI-kuratiert — bleiben unberührt.'];
        }

        return ['wirkt' => true, 'felder' => [
            'allergens_confidence' => (string) $gp->allergens_confidence . ' → ' . $r['confidence'],
            'allergens_source' => ((string) $gp->allergens_source ?: '—') . ' → ' . $r['source'],
        ], 'hinweis' => $r['needs_review'] ? 'Widersprüchliche LA-Angaben → bleibt review-pflichtig.' : null];
    }

    private function dryLeadLa(Team $team, int $gpId): array
    {
        $gp = FoodAlchemistGp::visibleToTeam($team)->find($gpId);
        if ($gp === null) {
            return ['wirkt' => false, 'felder' => [], 'hinweis' => 'GP nicht (mehr) sichtbar.'];
        }
        if ($this->preisLoestAuf($gp->lead_la_supplier_item_id)) {
            return ['wirkt' => false, 'felder' => [], 'hinweis' => 'Lead-LA löst bereits auf — unangetastet.'];
        }
        $neu = $this->leadLa->pickLeadLa($gp, $team);
        if ($neu === null || (int) $neu === (int) $gp->lead_la_supplier_item_id || ! $this->preisLoestAuf($neu)) {
            return ['wirkt' => false, 'felder' => [],
                'hinweis' => 'Kein bepreister Lieferantenartikel wählbar — echte Beschaffungs-Lücke.'];
        }

        return ['wirkt' => true, 'felder' => [
            'lead_la_supplier_item_id' => ((string) $gp->lead_la_supplier_item_id ?: '—') . ' → ' . $neu,
        ], 'hinweis' => 'Danach werden die nutzenden Rezepte neu gerechnet.'];
    }

    private function dryRecipeAnker(Team $team, int $recipeId): array
    {
        $recipe = FoodAlchemistRecipe::visibleToTeam($team)->find($recipeId);
        if ($recipe === null) {
            return ['wirkt' => false, 'felder' => [], 'hinweis' => 'Rezept nicht (mehr) sichtbar.'];
        }
        $quellen = [];
        foreach ($this->pairing->resolveRecipeAnchors($recipe) as $zeile) {
            if (($zeile['kern'] ?? null) !== null) {
                $quellen[(int) $zeile['kern']] = (string) ($zeile['label'] ?? '?');
            }
        }
        if ($quellen === []) {
            return ['wirkt' => false, 'felder' => [],
                'hinweis' => 'Aus Zutaten/Namen löst kein Kern-Anker auf — Vokabular-Lücke.'];
        }

        return ['wirkt' => true, 'felder' => [
            'recipe_anchor_mappings (kern)' => count($quellen) . '× — aus: ' . implode(', ', array_slice($quellen, 0, 4)),
        ], 'hinweis' => null];
    }

    private function dryGpAnker(Team $team, int $gpId): array
    {
        $gp = FoodAlchemistGp::visibleToTeam($team)->find($gpId);
        if ($gp === null) {
            return ['wirkt' => false, 'felder' => [], 'hinweis' => 'GP nicht (mehr) sichtbar.'];
        }
        $ankerId = $this->pairing->resolveByName((string) $gp->name);
        if ($ankerId === null) {
            return ['wirkt' => false, 'felder' => [],
                'hinweis' => 'GP-Name löst auf keinen Anker auf — Vokabular-Lücke.'];
        }

        return ['wirkt' => true, 'felder' => ['gp_anchor_mappings (kern)' => 'Anker #' . $ankerId], 'hinweis' => null];
    }

    /**
     * Recompute ist der einzige Fixer, dessen Zielwerte man ohne Lauf nicht kennt (die
     * Kette rechnet EK/Allergene/Aggregate neu). Ehrlich gezeigt wird darum, was
     * exakt vorhersagbar IST: die Größe der Propagations-Kaskade.
     */
    private function dryRecompute(int $recipeId): array
    {
        $kaskade = count($this->recompute->betroffeneRezepte($recipeId));

        return ['wirkt' => true, 'felder' => [
            'EK-/Aggregat-Felder' => 'werden neu gerechnet (Kaskade: ' . $kaskade . ' Rezept(e))',
        ], 'hinweis' => $kaskade > 1
            ? 'Wirkt auch auf ' . ($kaskade - 1) . ' übergeordnete(s) Rezept(e).'
            : 'Zielwerte stehen erst nach dem Lauf fest — Rezepte ohne Preisbasis bleiben offen.'];
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
