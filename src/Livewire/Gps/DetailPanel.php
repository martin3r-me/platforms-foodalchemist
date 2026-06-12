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
        $this->dispatch('gp-las-geaendert');
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
            'naehrwerte' => $gp !== null ? $aggregate->naehrwerte($gp) : null,
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
