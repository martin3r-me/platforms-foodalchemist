<?php

namespace Platform\FoodAlchemist\Livewire\Speiseplan;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Platform\FoodAlchemist\Services\SpeiseplanService;

/**
 * Speiseplan-Browser (Spec 29 / Editor-Rollout) — schlanke Übersichts-Liste. Das Planen selbst
 * (Wochen-Matrix, Monat, Linien, Kennzahlen) lebt im Fullscreen-Dark-Editor (Speiseplan\Editor),
 * geöffnet per `speiseplan-editor.bearbeiten` {id}. Der Browser hört auf `speiseplan-geaendert`
 * und frischt seine Zähler auf. Herausgezogen aus dem früheren Master-Detail-Vollbild.
 */
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    /** Gewählter Plan → Aushang-Vorschau in der Mitte (Foodbook-Muster). */
    #[Url(as: 'sp')]
    public ?int $selectedId = null;

    /** Mahlzeit für die Aushang-Vorschau. */
    public string $vorschauMahlzeit = 'mittag';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /** Refresh nach einer Änderung im Editor (Zähler + Aushang-Vorschau). */
    #[On('speiseplan-geaendert')]
    public function aktualisieren(): void
    {
        // reines Re-Render — withCount('entries') + dokumentDaten ziehen den neuen Stand.
    }

    public function neu(SpeiseplanService $svc): void
    {
        $sp = $svc->create($this->team(), ['name' => 'Neuer Speiseplan']);
        $this->selectedId = $sp->id;
        // Neuer Plan ist leer → direkt in den Editor (Vorschau käme leer).
        $this->dispatch('speiseplan-editor.bearbeiten', id: $sp->id);
    }

    /** Plan wählen → Aushang-Vorschau. Bearbeiten öffnet erst das Editor-Modal. */
    public function waehle(int $id): void
    {
        $this->selectedId = $id;
    }

    public function bearbeiten(): void
    {
        if ($this->selectedId) {
            $this->dispatch('speiseplan-editor.bearbeiten', id: $this->selectedId);
        }
    }

    /** Tiefe Kopie des gewählten Speiseplans (Linien + Zellen) → auf die Kopie springen. */
    public function duplizieren(SpeiseplanService $svc): void
    {
        if ($this->selectedId === null) {
            return;
        }
        $neu = $svc->dupliziere($this->team(), $this->selectedId);
        $this->waehle($neu->id);
    }

    public function render(SpeiseplanService $svc)
    {
        $team = $this->team();
        $plan = $this->selectedId ? $svc->detail($team, $this->selectedId) : null;
        $vorschau = $plan ? $svc->dokumentDaten($team, $plan, $this->vorschauMahlzeit) : null;

        return view('foodalchemist::livewire.speiseplan.index', [
            'plaene' => $svc->paginateBrowser(['search' => $this->search], $team),
            'plan' => $plan,
            'vorschau' => $vorschau,
        ])->layout('platform::layouts.app');
    }

    private function team()
    {
        return Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
    }
}
