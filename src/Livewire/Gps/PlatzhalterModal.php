<?php

namespace Platform\FoodAlchemist\Livewire\Gps;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;
use Platform\FoodAlchemist\Services\GpService;

/**
 * D-5: 📐 Platzhalter verwalten — neutrale Abstrakta für Grundrezept-Templates
 * (Port von create_/rename_/delete_platzhalter_gp). Anlegen + Liste + Inline-
 * Umbenennen + Löschen (blockt bei Verwendung). Dispatcht `gp-gespeichert`, damit
 * der GP-Browser Tabelle/Counts neu rendert.
 */
class PlatzhalterModal extends Component
{
    public string $neuName = '';

    public ?int $editId = null;

    public string $editName = '';

    public ?string $fehler = null;

    #[On('platzhalter-modal.oeffnen')]
    public function oeffnen(): void
    {
        $this->reset('neuName', 'editId', 'editName', 'fehler');
        $this->dispatch('modal.open', name: 'platzhalter-modal');
    }

    public function anlegen(): void
    {
        $this->fehler = null;
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null) {
            return;
        }
        if (trim($this->neuName) === '') {
            $this->fehler = 'Name ist Pflicht.';

            return;
        }
        try {
            app(GpService::class)->createPlatzhalter($team, $this->neuName);
            $this->neuName = '';
            $this->dispatch('gp-gespeichert');
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function startEdit(int $id, string $name): void
    {
        $this->editId = $id;
        $this->editName = trim(preg_replace('/\(\s*neutral\s*\)\s*$/iu', '', $name));
        $this->fehler = null;
    }

    public function speichernEdit(): void
    {
        $this->fehler = null;
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->editId === null) {
            return;
        }
        try {
            app(GpService::class)->renamePlatzhalter($team, $this->editId, $this->editName);
            $this->editId = null;
            $this->editName = '';
            $this->dispatch('gp-gespeichert');
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function abbrechenEdit(): void
    {
        $this->editId = null;
        $this->editName = '';
    }

    public function loeschen(int $id): void
    {
        $this->fehler = null;
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null) {
            return;
        }
        try {
            app(GpService::class)->deletePlatzhalter($team, $id);
            $this->dispatch('gp-gespeichert');
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    #[On('modal.closed')]
    public function beiSchliessen(?string $name = null): void
    {
        if ($name === null || $name === 'platzhalter-modal') {
            $this->reset('neuName', 'editId', 'editName', 'fehler');
        }
    }

    public function render()
    {
        $team = Auth::user()?->currentTeamRelation;

        return view('foodalchemist::livewire.gps.platzhalter-modal', [
            'platzhalter' => $team !== null ? app(GpService::class)->platzhalterListe($team) : collect(),
        ]);
    }
}
