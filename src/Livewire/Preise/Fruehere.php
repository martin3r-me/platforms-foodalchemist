<?php

namespace Platform\FoodAlchemist\Livewire\Preise;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\PreisVerlaufService;

/**
 * „Frühere Preise (n)“ unter den aktuellen Preisen eines Lieferantenartikels: die Necta-Historie (price_history) als eingeklappte Liste, damit
 * Liste und Preisverlauf dieselben Stände zeigen. Aktionspreise stehen hier markiert (die Linie zeigt sie nicht). Als eigene Komponente mit Key,
 * die nur auf das Ereignis `preise-geaendert` neu lädt (kein Neuzeichnen des Modals).
 */
class Fruehere extends Component
{
    public int $itemId = 0;

    public function mount(int $itemId = 0): void
    {
        $this->itemId = $itemId;
    }

    #[On('preise-geaendert')]
    public function neuLaden(): void
    {
        // Das Ereignis löst das Neurendern aus; Daten holt render().
    }

    public function render()
    {
        $team = Auth::user()?->currentTeamRelation;
        $item = FoodAlchemistSupplierItem::query()->visibleToTeam($team)->find($this->itemId);

        return view('foodalchemist::livewire.preise.fruehere', [
            'daten' => $item !== null ? app(PreisVerlaufService::class)->fruehere($team, $item) : ['zeilen' => [], 'gesamt' => 0, 'gekappt' => false],
        ]);
    }
}
