<?php

namespace Platform\FoodAlchemist\Livewire\Lager;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistInventoryLocation;
use Platform\FoodAlchemist\Services\InventurService;

/**
 * Spec 66 · Lager (Stufe 1): Bestand, Bewegungen, Inventuren. Periodisches Lager — der Bestand
 * entsteht aus Wareneingang und Inventur; Entnahmen aus der Produktion sind Stufe 2.
 */
class Index extends Component
{
    #[Url(as: 'reiter')]
    public string $reiter = 'bestand';          // bestand | bewegungen | inventur

    #[Url(as: 'ort')]
    public $lagerortId = null;

    public string $suche = '';

    public string $quelle = '';

    #[Url(as: 'inventur')]
    public ?int $inventurId = null;

    public $neuLagerortId = null;

    public string $neuDatum = '';

    public string $positionSuche = '';

    public ?string $fehler = null;

    public ?string $hinweis = null;

    public function mount(): void
    {
        $this->neuDatum = now()->toDateString();
        $this->reiter = in_array($this->reiter, ['bestand', 'bewegungen', 'inventur'], true) ? $this->reiter : 'bestand';
    }

    public function reiterSetzen(string $reiter): void
    {
        $this->reiter = in_array($reiter, ['bestand', 'bewegungen', 'inventur'], true) ? $reiter : 'bestand';
        $this->fehler = null;
    }

    public function inventurAnlegen(InventurService $svc): void
    {
        $this->fehler = null;
        try {
            $count = $svc->anlegen($this->team(), (int) $this->neuLagerortId, $this->neuDatum ?: now()->toDateString());
        } catch (\Throwable $e) {
            $this->fehler = $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException ? 'Bitte einen Lagerort wählen.' : $e->getMessage();

            return;
        }
        $this->reiter = 'inventur';
        $this->inventurId = (int) $count->id;
        $this->hinweis = 'Inventur angelegt — Zählliste ist vorbelegt.';
    }

    public function inventurOeffnen(int $id): void
    {
        $this->inventurId = $id;
        $this->positionSuche = '';
        $this->fehler = null;
        $this->hinweis = null;
    }

    public function inventurSchliessen(): void
    {
        $this->inventurId = null;
    }

    /** Gezählte Menge in kg / l / Stk (leer = nicht gezählt). */
    public function zaehlen(int $lineId, $menge, InventurService $svc): void
    {
        try {
            $svc->zaehlen($this->team(), $lineId, $menge);
            $this->fehler = null;
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function positionHinzu(int $gpId, InventurService $svc): void
    {
        if ($this->inventurId === null) {
            return;
        }
        try {
            $svc->positionHinzu($this->team(), $this->inventurId, $gpId);
            $this->positionSuche = '';
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function buchen(InventurService $svc): void
    {
        if ($this->inventurId === null) {
            return;
        }
        try {
            $c = $svc->buchen($this->team(), $this->inventurId, Auth::id());
            $this->hinweis = 'Inventur gebucht — Bestandswert ' . number_format((float) $c->value_total, 2, ',', '.') . ' €.';
            $this->fehler = null;
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function loeschen(InventurService $svc): void
    {
        if ($this->inventurId === null) {
            return;
        }
        try {
            $svc->loeschen($this->team(), $this->inventurId);
            $this->inventurId = null;
            $this->hinweis = 'Inventur verworfen.';
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function render(InventurService $svc)
    {
        $team = $this->team();
        $orte = FoodAlchemistInventoryLocation::where('team_id', $team->id)->where('is_active', true)
            ->orderByDesc('is_default')->orderBy('name')->get();
        $ortId = $this->lagerortId !== null && $this->lagerortId !== '' ? (int) $this->lagerortId : null;
        $this->neuLagerortId ??= $orte->first()?->id;

        $bestand = $this->reiter === 'bestand' ? $svc->bestand($team, $ortId, trim($this->suche)) : [];
        $inventur = null;
        $summen = null;
        $kandidaten = collect();
        if ($this->reiter === 'inventur' && $this->inventurId !== null) {
            try {
                $inventur = $svc->detail($team, $this->inventurId);
                $summen = $svc->summen($inventur);
                if (! $inventur->istGebucht() && mb_strlen(trim($this->positionSuche)) >= 2) {
                    $kandidaten = FoodAlchemistGp::visibleToTeam($team)
                        ->where('name', 'like', '%' . trim($this->positionSuche) . '%')->orderBy('name')->limit(12)->get(['id', 'name']);
                }
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
                $this->inventurId = null;
            }
        }

        return view('foodalchemist::livewire.lager.index', [
            'orte' => $orte,
            'bestand' => $bestand,
            'bestandWert' => round(array_sum(array_map(fn ($r) => (float) ($r['wert'] ?? 0), $bestand)), 2),
            'ohnePreis' => count(array_filter($bestand, fn ($r) => $r['wert'] === null)),
            'bewegungen' => $this->reiter === 'bewegungen' ? $svc->bewegungen($team, $this->quelle ?: null) : collect(),
            'inventuren' => $this->reiter === 'inventur' ? $svc->liste($team) : collect(),
            'inventur' => $inventur,
            'summen' => $summen,
            'kandidaten' => $kandidaten,
            'svc' => $svc,
        ])->layout(\Platform\FoodAlchemist\Support\FaShell::layout());
    }

    private function team(): Team
    {
        return Auth::user()?->currentTeamRelation ?? abort(403);
    }
}
