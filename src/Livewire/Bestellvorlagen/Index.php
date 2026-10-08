<?php

namespace Platform\FoodAlchemist\Livewire\Bestellvorlagen;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistOrderTemplate;
use Platform\FoodAlchemist\Services\OrderTemplateService;

/**
 * Spec 68/73 · Einkauf → Bestellvorlagen. Vorne die Liste, nach Kategorien gruppiert und durchsuchbar;
 * eine Vorlage öffnet im Editor (Bestellvorlagen\Editor) zum Bearbeiten und Bestellen.
 */
class Index extends Component
{
    #[Url(as: 'vorlage')]
    public ?int $vorlageId = null;

    public string $neuName = '';

    public string $neuKategorie = '';

    public string $suche = '';

    public string $kategorieFilter = '';

    public ?string $fehler = null;

    public ?string $hinweis = null;

    public function mount(): void
    {
        if ($this->vorlageId !== null) {
            $this->dispatch('vorlage-editor.oeffnen', id: $this->vorlageId)->to(Editor::class);
        }
    }

    public function oeffnen(int $id): void
    {
        $this->vorlageId = $id;
        $this->hinweis = null;
        $this->dispatch('vorlage-editor.oeffnen', id: $id)->to(Editor::class);
    }

    public function anlegen(OrderTemplateService $svc): void
    {
        $this->fehler = null;
        try {
            $v = $svc->anlegen($this->team(), ['name' => $this->neuName, 'kategorie' => $this->neuKategorie ?: ($this->kategorieFilter ?: null)], Auth::id());
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        $this->neuName = '';
        $this->vorlageId = $v->id;
        $this->dispatch('vorlage-editor.oeffnen', id: $v->id, bearbeiten: true)->to(Editor::class);
    }

    #[On('vorlagen-geaendert')]
    public function geaendert(?string $hinweis = null): void
    {
        if ($hinweis !== null) {
            $this->hinweis = $hinweis;
            $this->vorlageId = null;
        }
    }

    public function render(OrderTemplateService $svc)
    {
        $team = $this->team();
        $alle = $svc->liste($team);
        $q = mb_strtolower(trim($this->suche));
        $liste = $alle->filter(fn (FoodAlchemistOrderTemplate $v) => ($this->kategorieFilter === '' || (string) $v->kategorie === $this->kategorieFilter)
            && ($q === '' || str_contains(mb_strtolower($v->name . ' ' . $v->kategorie . ' ' . $v->note), $q)));

        return view('foodalchemist::livewire.bestellvorlagen.index', [
            'gruppen' => $liste->groupBy(fn ($v) => $v->kategorie ?: 'Ohne Kategorie'),
            'gesamt' => $alle->count(),
            'kategorien' => $svc->kategorien($team),
            'wochentage' => FoodAlchemistOrderTemplate::WOCHENTAGE,
        ])->layout(\Platform\FoodAlchemist\Support\FaShell::layout());
    }

    private function team(): Team
    {
        return Auth::user()?->currentTeamRelation ?? abort(403);
    }
}
