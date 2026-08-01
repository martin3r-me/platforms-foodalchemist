<?php

namespace Platform\FoodAlchemist\Livewire\Speisekarte;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarte;
use Platform\FoodAlchemist\Services\SpeisekarteService;

/**
 * Speisekarte-Editor (Stufe A) — Browser links + Karten-Editor rechts (Rubrik-Baum,
 * Gericht-Picker, Live-Preis). Dritte Ausgabeform neben Foodbook + Speiseplan.
 * Der Branding-Tab (Stufe C), Fix-Menü/Getränk-Picker (Stufe D) und das
 * Leitstelle-Cockpit (Stufe E) docken hier später an.
 */
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'sk')]
    public ?int $karteId = null;

    // Karten-Meta (Editor)
    public string $name = '';
    public string $kartenTyp = 'alacarte';
    public string $status = 'entwurf';

    // Rubrik-Anlage
    public string $neueRubrik = '';

    // Gericht-Picker
    public string $pickerSuche = '';
    public ?int $pickerRubrikId = null;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function neu(SpeisekarteService $svc): void
    {
        $karte = $svc->create($this->team(), ['name' => 'Neue Speisekarte']);
        $this->waehle($karte->id);
    }

    public function waehle(int $id): void
    {
        $karte = FoodAlchemistSpeisekarte::visibleToTeam($this->team())->find($id);
        if (! $karte) {
            return;
        }
        $this->karteId = $id;
        $this->name = $karte->name;
        $this->kartenTyp = $karte->karten_typ;
        $this->status = $karte->status;
        $this->pickerRubrikId = null;
        $this->pickerSuche = '';
    }

    public function schliessen(): void
    {
        $this->karteId = null;
    }

    public function speichern(SpeisekarteService $svc): void
    {
        if (! $this->karteId) {
            return;
        }
        $svc->update($this->team(), $this->karteId, [
            'name' => $this->name,
            'karten_typ' => $this->kartenTyp,
            'status' => $this->status,
        ]);
        $this->dispatch('gespeichert');
    }

    public function loeschen(SpeisekarteService $svc): void
    {
        if (! $this->karteId) {
            return;
        }
        $svc->delete($this->team(), $this->karteId);
        $this->karteId = null;
    }

    public function rubrikNeu(SpeisekarteService $svc, ?int $parentId = null): void
    {
        if (! $this->karteId || trim($this->neueRubrik) === '') {
            return;
        }
        $svc->addRubrik($this->team(), $this->karteId, ['title' => trim($this->neueRubrik)], $parentId);
        $this->neueRubrik = '';
    }

    public function rubrikLoeschen(SpeisekarteService $svc, int $rubrikId): void
    {
        $svc->deleteRubrik($this->team(), $rubrikId);
    }

    /** Gericht-Picker für eine Rubrik öffnen/schließen. */
    public function pickerOeffnen(int $rubrikId): void
    {
        $this->pickerRubrikId = $this->pickerRubrikId === $rubrikId ? null : $rubrikId;
        $this->pickerSuche = '';
    }

    public function positionAusGericht(SpeisekarteService $svc, int $rubrikId, int $recipeId): void
    {
        $svc->addPosition($this->team(), $rubrikId, [
            'type' => 'gericht_ref', 'sales_recipe_id' => $recipeId,
        ]);
        $this->pickerSuche = '';
    }

    public function positionLoeschen(SpeisekarteService $svc, int $positionId): void
    {
        $svc->deletePosition($this->team(), $positionId);
    }

    public function render(SpeisekarteService $svc)
    {
        $team = $this->team();
        $karte = $this->karteId ? $svc->detail($team, $this->karteId) : null;

        // Preis-Map je Position (netto) für die Live-Anzeige.
        $preise = [];
        $baum = [];
        if ($karte) {
            $baum = $svc->rubrikTree($team, $karte->id);
            foreach ($karte->sections as $rubrik) {
                foreach ($rubrik->items as $pos) {
                    $preise[$pos->id] = $svc->positionPreis($pos);
                }
            }
        }

        $pickerErgebnisse = ($this->pickerRubrikId !== null)
            ? $svc->gerichtKandidaten($team, $this->pickerSuche, 15)
            : collect();

        return view('foodalchemist::livewire.speisekarte.index', [
            'karten' => $svc->paginateBrowser(['search' => $this->search], $team),
            'karte' => $karte,
            'baum' => $baum,
            'preise' => $preise,
            'pickerErgebnisse' => $pickerErgebnisse,
        ])->layout('platform::layouts.app');
    }

    private function team()
    {
        return Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
    }
}
