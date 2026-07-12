<?php

namespace Platform\FoodAlchemist\Livewire\Foodbooks;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Platform\FoodAlchemist\Services\FoodbookService;

/**
 * R3.1: Interne, navigierbare Foodbook-Lese-Ansicht (kein Editor, kein Kunden-Dokument).
 * Kapitel-Baum-Navigation + Blättern + interne EK/VK/W%-Sicht mit Live-Resolver-Preisen.
 * Slice 2: kombinierbare dish-level Filter (Volltext · Diät · allergenfrei) + Gericht-Drill-down.
 * Pax überschreibbar (bindet die Gesamt-Rechnung). Konzept-Facetten/Servierform = Folge-Slice.
 */
class Ansicht extends Component
{
    public int $id;

    #[Url(as: 'pax')]
    public ?int $pax = null;

    /** Volltextsuche (server-seitig, über Gericht-/Titel-Namen). */
    #[Url(as: 'q')]
    public string $q = '';

    /** Diät-Filter (dish_class.diet_form), Mehrfachauswahl. */
    #[Url(as: 'diaet')]
    public array $diaet = [];

    /** «ohne»-Allergene (14 EU-Schlüssel), Mehrfachauswahl. */
    #[Url(as: 'ohne')]
    public array $ohne = [];

    /** Servierform-Filter (serving_form-IDs), Mehrfachauswahl. */
    #[Url(as: 'form')]
    public array $formen = [];

    public function mount(int $id): void
    {
        $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
        $fb = app(FoodbookService::class)->detail($team, $id) ?? abort(404);
        $this->id = $id;
        $this->pax ??= $fb->personen;
    }

    public function filterZuruecksetzen(): void
    {
        $this->reset('q', 'diaet', 'ohne', 'formen');
    }

    public function render(FoodbookService $svc)
    {
        $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
        $fb = $svc->detail($team, $this->id) ?? abort(404);

        $data = $svc->ansichtDaten($team, $fb, $this->pax, [
            'q' => $this->q,
            'diaet' => $this->diaet,
            'ohne' => $this->ohne,
            'formen' => $this->formen,
        ]);

        return view('foodalchemist::livewire.foodbooks.ansicht', $data + [
            'dietFormen' => FoodbookService::DIET_FORMS,
            'allergenKeys' => FoodbookService::ALLERGEN_KEYS,
        ])->layout('platform::layouts.app');
    }
}
