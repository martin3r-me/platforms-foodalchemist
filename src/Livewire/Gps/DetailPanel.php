<?php

namespace Platform\FoodAlchemist\Livewire\Gps;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\GpAggregateService;
use Platform\FoodAlchemist\Services\LeadLaService;
use Platform\FoodAlchemist\Support\Curate;

/**
 * M3-03/05/07 / P-1: GP-DetailPanel — eigene Livewire-Komponente in der rechten
 * Page-Sidebar (Platzierungs-Entscheid), hört auf `gp-selected` (kein Full-Reload).
 * Sektionen (Allergene/Zusatzstoffe/Nährwerte/LAs) laden LAZY; der Aufklapp-Zustand
 * übersteht den GP-Wechsel (Kontext-Erhalt-Gebot).
 *
 * LA-Aktionen (M3-07): global (Lead setzen, lösen, verknüpfen) nur fürs Kurations-
 * Team (canCurate); team-eigen (Sperre, Pin — V-27-Overlay) für jedes Team.
 */
class DetailPanel extends Component
{
    public ?int $gpId = null;

    public string $laSuche = '';

    public ?string $fehler = null;

    public function mount(?int $gpId = null): void
    {
        $this->gpId = $gpId;
    }

    #[On('gp-selected')]
    public function zeige(int $id): void
    {
        $this->gpId = $id;
        $this->laSuche = '';
        $this->fehler = null;
        $this->kiVorschlag = null;
        $this->laKandidaten = null;
    }

    // ── M3-07: LA-Aktionen ──────────────────────────────────────────────

    public function leadSetzen(int $laId): void
    {
        $this->laAktion(fn ($svc, $gp, $team) => $svc->setLeadLa($team, $gp, $laId), nurKurator: true);
    }

    public function sperreToggle(int $laId, bool $gesperrt): void
    {
        $this->laAktion(fn ($svc, $gp, $team) => $svc->sperren($team, $gp, $laId, $gesperrt));
    }

    public function pinToggle(int $laId, bool $pinnen): void
    {
        $this->laAktion(fn ($svc, $gp, $team) => $svc->pinnen($team, $gp, $pinnen ? $laId : null));
    }

    public function loesen(int $laId): void
    {
        $this->laAktion(fn ($svc, $gp, $team) => $svc->entknuepfen($team, $gp, $laId), nurKurator: true);
        $this->dispatch('gp-las-geaendert'); // Browser-Tabelle (LAs-Spalte) kann reagieren
    }

    public function verknuepfe(int $laId): void
    {
        $this->laAktion(fn ($svc, $gp, $team) => $svc->verknuepfen($team, $gp, $laId), nurKurator: true);
        $this->laSuche = '';
        $this->laKandidaten = null;
        $this->dispatch('gp-las-geaendert');
    }

    // ── R12 (Jarvis): ✨ KI-Vorschlag — unverknüpfte LA-Kandidaten zum GP ──

    /** @var ?array<int, array{id:int, designation:string, supplier:?string, score:float}> */
    public ?array $laKandidaten = null;

    public function laVorschlaege(): void
    {
        $gp = $this->kuratierbaresGp();                            // verknuepfen ist Katalog-Aktion (D1)
        if ($gp === null) {
            return;
        }
        $team = Auth::user()->currentTeamRelation;
        $this->laKandidaten = app(LeadLaService::class)->kandidatenFuerGp($team, $gp)
            ->map(fn ($la) => [
                'id' => (int) $la->id,
                'designation' => (string) $la->designation,
                'supplier' => $la->supplier_name,
                'score' => round((float) $la->match_score, 2),
            ])->all();
        if ($this->laKandidaten === []) {
            $this->fehler = 'Keine unverknüpften Artikel gefunden, die zum GP-Namen passen.';
            $this->laKandidaten = null;
        }
    }

    public function laVorschlaegeVerwerfen(): void
    {
        $this->laKandidaten = null;
    }

    // ── R10 (Ist-Feature): ✨ Allergene/Nährwerte per KI schätzen, wenn keine LA-Daten ──

    /** @var ?array{typ: string, werte: array, confidence: float} Vorschau — NICHTS persistiert (GL-07) */
    public ?array $kiVorschlag = null;

    public function kiAllergene(): void
    {
        $gp = $this->kuratierbaresGp();
        if ($gp === null) {
            return;
        }
        try {
            $v = app(\Platform\FoodAlchemist\Services\Ai\AiGatewayService::class)->propose('gp.allergene', [
                'name' => $gp->name, 'zustand' => $gp->zustand, 'warengruppe' => $gp->warengruppe?->name,
            ]);
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        $werte = [];
        foreach (\Platform\FoodAlchemist\Models\FoodAlchemistGp::ALLERGEN_FIELDS as $feld) {
            $wert = $v->werte['allergene'][$feld] ?? null;
            if (in_array($wert, ['enthalten', 'spuren', 'nicht_enthalten'], true)) {
                $werte[$feld] = $wert;                            // 'unbekannt' ⇒ kein Override (F7.1)
            }
        }
        if ($werte === []) {
            $this->fehler = 'KI lieferte keine verwertbaren Allergen-Werte — echter Provider nötig.';

            return;
        }
        $this->kiVorschlag = ['typ' => 'allergene', 'werte' => $werte, 'confidence' => max(0.0, min(1.0, $v->confidence))];
    }

    public function kiNaehrwerte(): void
    {
        $gp = $this->kuratierbaresGp();
        if ($gp === null) {
            return;
        }
        try {
            $v = app(\Platform\FoodAlchemist\Services\Ai\AiGatewayService::class)->propose('gp.naehrwerte', [
                'name' => $gp->name, 'zustand' => $gp->zustand, 'warengruppe' => $gp->warengruppe?->name,
            ]);
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        $werte = [];
        foreach (['kcal', 'protein_g', 'fat_g', 'carbs_g', 'salt_g'] as $feld) {
            $wert = $v->werte[$feld] ?? null;
            if (is_numeric($wert) && (float) $wert >= 0) {
                $werte[$feld] = round((float) $wert, 2);
            }
        }
        if (! isset($werte['kcal'])) {                              // kcal = Leit-Indikator (GL-08)
            $this->fehler = 'KI lieferte keine verwertbaren Nährwerte (kcal fehlt) — echter Provider nötig.';

            return;
        }
        $this->kiVorschlag = ['typ' => 'naehrwerte', 'werte' => $werte, 'confidence' => max(0.0, min(1.0, $v->confidence))];
    }

    /** Übernehmen = der EINE Schreib-Moment (GL-07): Override-Layer bzw. Fallback-Schicht. */
    public function kiUebernehmen(): void
    {
        $gp = $this->kuratierbaresGp();
        if ($gp === null || $this->kiVorschlag === null) {
            return;
        }
        if ($this->kiVorschlag['typ'] === 'allergene') {
            $update = [];
            foreach ($this->kiVorschlag['werte'] as $feld => $wert) {
                $update["allergen_{$feld}"] = $wert;             // GL-01 Prio 1: Override
            }
            $update['allergene_ki_confidence'] = $this->kiVorschlag['confidence'];
            $gp->update($update);
        } else {
            $w = $this->kiVorschlag['werte'];
            $gp->update([
                'nutri_kcal_per_100g' => $w['kcal'] ?? null,
                'nutri_protein_g_per_100g' => $w['protein_g'] ?? null,
                'nutri_fat_g_per_100g' => $w['fat_g'] ?? null,
                'nutri_carbs_g_per_100g' => $w['carbs_g'] ?? null,
                'nutri_salt_g_per_100g' => $w['salt_g'] ?? null,
                'nutri_quelle' => 'ki',
                'nutri_ai_confidence' => $this->kiVorschlag['confidence'],
            ]);
        }
        $this->kiVorschlag = null;
    }

    public function kiVerwerfen(): void
    {
        $this->kiVorschlag = null;                                 // reject lässt Fachdaten unberührt (GL-07)
    }

    /** Curate-Gate + frisches GP — KI-Schätzung ist eine globale Katalog-Aktion (D1). */
    private function kuratierbaresGp(): ?FoodAlchemistGp
    {
        $this->fehler = null;
        $team = Auth::user()?->currentTeamRelation;
        $gp = $team !== null && $this->gpId !== null
            ? FoodAlchemistGp::visibleToTeam($team)->with('warengruppe')->find($this->gpId)
            : null;
        if ($gp === null) {
            return null;
        }
        if (! Curate::canCurate(Auth::user(), $gp)) {
            $this->fehler = 'Globale Katalog-Aktion — nur fürs Kurations-Team (D1).';

            return null;
        }

        return $gp;
    }

    private function laAktion(\Closure $aktion, bool $nurKurator = false): void
    {
        $this->fehler = null;
        $team = Auth::user()?->currentTeamRelation;
        $gp = $team !== null && $this->gpId !== null
            ? FoodAlchemistGp::visibleToTeam($team)->find($this->gpId)
            : null;
        if ($gp === null) {
            return;
        }
        if ($nurKurator && ! Curate::canCurate(Auth::user(), $gp)) {
            $this->fehler = 'Globale Katalog-Aktion — nur fürs Kurations-Team (D1). Team-eigene Alternative: Sperre/Pin.';

            return;
        }

        try {
            $aktion(app(LeadLaService::class), $gp, $team);
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function render(GpAggregateService $aggregate, LeadLaService $leads)
    {
        $team = Auth::user()?->currentTeamRelation;
        $gp = null;
        if ($this->gpId !== null && $team !== null) {
            $gp = FoodAlchemistGp::visibleToTeam($team)
                ->with(['warengruppe', 'preferredCountUnit', 'leadLa', 'derivatVon'])
                ->find($this->gpId);
        }

        // R9 (Dominique: «Anzeige komplett Bug»): Sektionen IMMER sichtbar — die
        // Lazy-Klapperei der Ist-Abnahme versteckte alle Inhalte und Aktionen.
        // Aggregate sind DB-MAX/Ø-Queries über die LAs (M8-04: Panels 2–16 ms).
        return view('foodalchemist::livewire.gps.detail-panel', [
            'gp' => $gp,
            'team' => $team,
            'kannKuratieren' => $gp !== null && Curate::canCurate(Auth::user(), $gp),
            'allergene' => $gp !== null ? $aggregate->allergene($gp) : null,
            'allergenKonfidenz' => $gp !== null ? $aggregate->allergenKonfidenz($gp) : null,
            'zusatzstoffe' => $gp !== null ? $aggregate->zusatzstoffe($gp) : null,
            'naehrwerte' => $gp !== null ? $aggregate->naehrwerte($gp, mitKiFallback: true) : null,
            'kette' => $gp !== null ? $leads->rangliste($gp, $team) : null,
            'effektiverLeadId' => $gp !== null ? $leads->effektiverLead($gp, $team)?->id : null,
            'verknuepfbare' => $gp !== null && $this->laSuche !== '' ? $leads->sucheVerknuepfbare($team, $this->laSuche) : collect(),
            // M9-05 (GP-Blickwinkel): in welchen Rezepten eingesetzt — klickbar
            'verwendungen' => $gp !== null
                ? \Illuminate\Support\Facades\DB::table('foodalchemist_recipe_ingredients AS ri')
                    ->join('foodalchemist_recipes AS r', 'r.id', '=', 'ri.recipe_id')
                    ->where('ri.gp_id', $gp->id)->whereNull('ri.deleted_at')->whereNull('r.deleted_at')
                    ->whereIn('r.team_id', FoodAlchemistGp::teamAncestryIds($team))
                    ->orderBy('r.name')->distinct()
                    ->limit(30)->get(['r.id', 'r.name', 'r.ist_verkaufsrezept'])
                : collect(),
        ]);
    }
}
