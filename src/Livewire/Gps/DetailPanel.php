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

    /** @var array<string, bool> aufgeklappte Sektionen (allergene|zusatzstoffe|naehrwerte|las) */
    public array $offen = [];

    public string $laSuche = '';

    public ?string $fehler = null;

    public function mount(?int $gpId = null): void
    {
        $this->gpId = $gpId;
    }

    #[On('gp-selected')]
    public function zeige(int $id): void
    {
        $this->gpId = $id;       // $offen bleibt — gleiche Sektionen beim nächsten GP offen
        $this->laSuche = '';
        $this->fehler = null;
    }

    public function toggleSektion(string $sektion): void
    {
        if (in_array($sektion, ['allergene', 'zusatzstoffe', 'naehrwerte', 'las'], true)) {
            $this->offen[$sektion] = ! ($this->offen[$sektion] ?? false);
        }
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

        $lasOffen = $gp !== null && ($this->offen['las'] ?? false);

        return view('foodalchemist::livewire.gps.detail-panel', [
            'gp' => $gp,
            'team' => $team,
            'kannKuratieren' => $gp !== null && Curate::canCurate(Auth::user(), $gp),
            // lazy: nur offene Sektionen rechnen (M3-05-DoD)
            'allergene' => $gp !== null && ($this->offen['allergene'] ?? false) ? $aggregate->allergene($gp) : null,
            'allergenKonfidenz' => $gp !== null && ($this->offen['allergene'] ?? false) ? $aggregate->allergenKonfidenz($gp) : null,
            'zusatzstoffe' => $gp !== null && ($this->offen['zusatzstoffe'] ?? false) ? $aggregate->zusatzstoffe($gp) : null,
            'naehrwerte' => $gp !== null && ($this->offen['naehrwerte'] ?? false) ? $aggregate->naehrwerte($gp) : null,
            'kette' => $lasOffen ? $leads->rangliste($gp, $team) : null,
            'effektiverLeadId' => $lasOffen ? $leads->effektiverLead($gp, $team)?->id : null,
            'verknuepfbare' => $lasOffen && $this->laSuche !== '' ? $leads->sucheVerknuepfbare($team, $this->laSuche) : collect(),
        ]);
    }
}
