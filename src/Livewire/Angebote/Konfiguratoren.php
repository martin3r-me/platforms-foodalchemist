<?php

namespace Platform\FoodAlchemist\Livewire\Angebote;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistKonfigurator;
use Platform\FoodAlchemist\Services\KonfiguratorService;

/**
 * Spec 94 · Angebote → Konfiguratoren. Liste der Konfiguratoren; ein Klick öffnet den Konfigurator-Editor im Vollbild
 * ({@see KonfiguratorEditor}), aufgebaut wie der Angebots-Editor.
 */
class Konfiguratoren extends Component
{
    #[Url(as: 'q')]
    public string $suche = '';

    #[Url(as: 'status')]
    public string $statusFilter = '';

    #[Url(as: 'sel')]
    public ?int $selectedId = null;

    public ?string $fehler = null;

    public function mount(): void
    {
        if ($this->selectedId !== null) {
            $this->dispatch('konfigurator-editor.oeffnen', id: $this->selectedId)->to(KonfiguratorEditor::class);
        }
    }

    public function waehle(int $id): void
    {
        $this->selectedId = $id;
        $this->dispatch('konfigurator-editor.oeffnen', id: $id)->to(KonfiguratorEditor::class);
    }

    public function neu(KonfiguratorService $svc): void
    {
        $this->fehler = null;
        try {
            $k = $svc->anlegen($this->team(), ['name' => 'Neuer Konfigurator']);
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        $this->waehle($k->id);
    }

    #[On('konfigurator-gespeichert')]
    public function aktualisiere(): void
    {
    }

    #[On('konfigurator-geloescht')]
    public function entfernt(?int $id = null): void
    {
        if ($id === $this->selectedId) {
            $this->selectedId = null;
        }
    }

    public function render()
    {
        $team = $this->team();
        $q = trim($this->suche);
        $liste = FoodAlchemistKonfigurator::visibleToTeam($team)
            ->withCount(['konzepte as haupt_count' => fn ($x) => $x->where('rolle', 'haupt'), 'pakete'])
            ->when($this->statusFilter !== '', fn ($x) => $x->where('status', $this->statusFilter))
            ->when($q !== '', fn ($x) => $x->where(fn ($w) => $w->where('name', 'like', "%{$q}%")->orWhere('kunden_titel', 'like', "%{$q}%")->orWhere('ort', 'like', "%{$q}%")))
            ->orderByDesc('updated_at')->get();

        return view('foodalchemist::livewire.angebote.konfiguratoren', [
            'liste' => $liste,
            'statusWerte' => ['entwurf' => 'Entwurf', 'live' => 'Live', 'archiv' => 'Archiv'],
        ])->layout(\Platform\FoodAlchemist\Support\FaShell::layout());
    }

    private function team(): Team
    {
        return Auth::user()?->currentTeamRelation ?? abort(403);
    }
}
