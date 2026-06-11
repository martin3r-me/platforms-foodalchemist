<?php

namespace Platform\FoodAlchemist\Livewire\Suppliers;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Platform\FoodAlchemist\Services\PriceService;
use Platform\FoodAlchemist\Services\SupplierItemService;
use Platform\FoodAlchemist\Services\SupplierService;
use RuntimeException;

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

    #[Url(as: 'zeilen')]
    public int $perPage = 100;

    /** Feedback 2026-06-11: „+ Neuer Lieferant" */
    public array $neuLieferant = ['name' => '', 'city' => '', 'email_order' => ''];

    /** M2-11: „+ Neuer Artikel" */
    public array $neuArtikel = ['designation' => '', 'article_number' => '', 'qty' => '', 'unit_code' => ''];

    public ?string $fehler = null;

    /** M2-12: Anomalien-Report (lazy beim Öffnen) */
    public ?array $anomalien = null;

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

    public function updatedPerPage(): void
    {
        $this->perPage = in_array((int) $this->perPage, [25, 50, 100, 250, 500], true) ? (int) $this->perPage : 100;
        $this->resetPage();
    }

    public function lieferantAnlegen(): void
    {
        try {
            $supplier = app(SupplierService::class)->create(Auth::user()->currentTeamRelation, $this->neuLieferant);
            $this->reset('neuLieferant', 'fehler');
            $this->dispatch('modal.close', name: 'lieferant-neu');
            $this->waehleLieferant($supplier->id);
        } catch (RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function artikelAnlegen(): void
    {
        try {
            $item = app(SupplierItemService::class)->create(
                Auth::user()->currentTeamRelation,
                (int) $this->supplierId,
                $this->neuArtikel,
            );
            $this->reset('neuArtikel', 'fehler');
            $this->dispatch('modal.close', name: 'artikel-neu');
            $this->dispatch('item-modal.oeffnen', id: $item->id);
        } catch (RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function anomalienAnzeigen(): void
    {
        $ergebnis = app(PriceService::class)->detectAnomalies(Auth::user()->currentTeamRelation);
        $this->anomalien = [
            'spruenge' => $ergebnis['spruenge']->take(50)->all(),
            'ausreisser' => $ergebnis['ausreisser']->take(50)
                ->map(fn ($a) => ['bezeichnung' => $a->bezeichnung, 'lieferant' => $a->lieferant,
                    'wg' => $a->wg, 'wert' => $a->wert, 'median' => $a->median, 'faktor' => $a->faktor, 'einheit' => $a->einheit])
                ->all(),
        ];
        $this->dispatch('modal.open', name: 'preis-anomalien');
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
            $globaleSuche => $items->searchGlobal($team, trim($this->q), ['onlyActive' => $this->onlyActive], $this->perPage),
            $this->supplierId !== null => $items->paginateForSupplier($team, $this->supplierId, ['onlyActive' => $this->onlyActive], $this->perPage),
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
