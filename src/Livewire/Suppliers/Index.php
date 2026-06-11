<?php

namespace Platform\FoodAlchemist\Livewire\Suppliers;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Platform\FoodAlchemist\Services\PriceService;
use Platform\FoodAlchemist\Services\SupplierItemService;
use Platform\FoodAlchemist\Services\SupplierService;

/**
 * M2-01/02/03 / P-7: Lieferanten-Browser — Liste links (n Artikel · m gemapped),
 * Artikel-Tabelle Mitte (EK + Vergleichspreis M2-05), lieferantenübergreifende
 * Suche via ?q= (V-17/Kontext-Erhalt: Auswahl + Suche leben in der URL).
 */
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'lieferant')]
    public ?int $supplierId = null;

    #[Url]
    public string $q = '';

    public string $supplierSuche = '';

    public bool $includeInactive = false;

    public bool $onlyActive = true;

    public function waehleLieferant(int $id): void
    {
        $this->supplierId = $id;
        $this->q = '';
        $this->resetPage();
    }

    public function updatedQ(): void
    {
        $this->resetPage();
    }

    public function updatedOnlyActive(): void
    {
        $this->resetPage();
    }

    public function render(SupplierService $suppliers, SupplierItemService $items, PriceService $preise)
    {
        $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');

        $liste = $suppliers->listWithCounts($team, $this->includeInactive, $this->supplierSuche);
        $globaleSuche = trim($this->q) !== '';

        if (! $globaleSuche && $this->supplierId === null) {
            $this->supplierId = $liste->first()?->id;
        }

        $artikel = match (true) {
            $globaleSuche => $items->searchGlobal($team, trim($this->q), ['onlyActive' => $this->onlyActive]),
            $this->supplierId !== null => $items->paginateForSupplier($team, $this->supplierId, ['onlyActive' => $this->onlyActive]),
            default => null,
        };

        // M2-05: Vergleichspreis je Zeile aus EK-Subquery-Wert (eine Regel-Stelle: PriceService)
        $artikel?->getCollection()->each(function ($item) use ($preise) {
            $ek = $item->aktiver_preis !== null ? (float) $item->aktiver_preis : null;
            $item->setAttribute('vergleichspreis', $preise->vergleichspreis($item, $ek));
        });

        return view('foodalchemist::livewire.suppliers.index', [
            'team' => $team,
            'lieferanten' => $liste,
            'artikel' => $artikel,
            'globaleSuche' => $globaleSuche,
            'aktiverLieferant' => $globaleSuche ? null : $liste->firstWhere('id', $this->supplierId),
        ])->layout('platform::layouts.app');
    }
}
