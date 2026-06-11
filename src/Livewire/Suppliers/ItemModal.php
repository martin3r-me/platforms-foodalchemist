<?php

namespace Platform\FoodAlchemist\Livewire\Suppliers;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistItemAllergen;
use Platform\FoodAlchemist\Models\FoodAlchemistItemDeclaration;
use Platform\FoodAlchemist\Services\PriceService;
use Platform\FoodAlchemist\Services\SupplierItemService;
use Platform\FoodAlchemist\Support\Curate;
use RuntimeException;

/**
 * M2-06/07/08 / P-2+P-6: LA-Editor-Modal — Sektionen Stammdaten · Verpackung ·
 * Eigenschaften · Preise. Lesend für alle (Team-Kette), Edit + Preispflege nur
 * Besitzer-Team (Curate/D1). Schließen ohne Speichern = State-Reset (modal.closed).
 */
class ItemModal extends Component
{
    public ?int $itemId = null;

    public array $stammdaten = [];

    public array $verpackung = [];

    public array $eigenschaften = [];

    public array $preisNeu = ['preis' => '', 'status' => '0'];

    /** M2-10: 14 EU-Allergene (tri-state-Binding, GL-01) */
    public array $allergene = [];

    /** M2-15: 18 LMIV-Deklarationen (ja|nein|unbekannt, GL-09) */
    public array $deklarationen = [];

    public ?string $fehler = null;

    #[On('item-modal.oeffnen')]
    public function oeffnen(int $id): void
    {
        $item = $this->item($id);
        $this->itemId = $item->id;
        $this->fehler = null;
        $this->stammdaten = $item->only(['designation', 'article_number', 'brand', 'manufacturer', 'origin', 'marketing_name']);
        $this->verpackung = $item->only(['qty', 'unit_code', 'packaging_unit', 'ordering_unit', 'qty_ordering_per_packaging']);
        $this->eigenschaften = $item->only(['is_organic', 'is_vegan', 'is_vegetarian', 'is_alcohol']);
        $this->allergene = app(SupplierItemService::class)->getAllergens($item);
        $this->deklarationen = app(SupplierItemService::class)->getDeclarations($item);
        $this->dispatch('modal.open', name: 'item-modal');
    }

    #[On('modal.closed')]
    public function geschlossen(array $payload = []): void
    {
        if (($payload['name'] ?? null) === 'item-modal') {
            $this->reset(); // P-2: kein State-Leak
        }
    }

    public function speichern(): void
    {
        try {
            $item = $this->item($this->itemId);
            if (! Curate::canCurate(Auth::user(), $item)) {
                throw new RuntimeException('Geerbter Katalog-Artikel — Pflege nur durch das Besitzer-Team (D1).');
            }
            $this->validate(
                ['stammdaten.designation' => 'required|string|max:255'],
                ['stammdaten.designation.required' => 'Bezeichnung ist Pflicht.'],
            );

            $item->update([
                ...collect($this->stammdaten)->map(fn ($v) => $v === '' ? null : $v)->all(),
                ...collect($this->verpackung)->only(['qty', 'unit_code', 'packaging_unit', 'ordering_unit', 'qty_ordering_per_packaging'])
                    ->map(fn ($v) => $v === '' ? null : $v)->all(),
                ...collect($this->eigenschaften)->map(fn ($v) => $v === '' || $v === null ? null : (bool) (int) $v)->all(),
            ]);
            $this->fehler = null;
            $this->dispatch('item-gespeichert');
        } catch (RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function allergeneSpeichern(): void
    {
        try {
            app(SupplierItemService::class)->setAllergens($this->team(), $this->item($this->itemId), $this->allergene);
            $this->fehler = null;
            $this->dispatch('item-gespeichert');
        } catch (RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function deklarationenSpeichern(): void
    {
        try {
            app(SupplierItemService::class)->setDeclarations($this->team(), $this->item($this->itemId), $this->deklarationen);
            $this->fehler = null;
            $this->dispatch('item-gespeichert');
        } catch (RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function preisAnlegen(): void
    {
        try {
            $preis = (float) str_replace(',', '.', (string) $this->preisNeu['preis']);
            app(PriceService::class)->createFor($this->team(), $this->item($this->itemId), $preis, $this->preisNeu['status']);
            $this->preisNeu = ['preis' => '', 'status' => '0'];
            $this->fehler = null;
        } catch (RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function preisLoeschen(int $priceId): void
    {
        try {
            app(PriceService::class)->deleteFor($this->team(), $this->item($this->itemId), $priceId);
        } catch (RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function render(PriceService $preise)
    {
        $item = $this->itemId !== null ? $this->item($this->itemId) : null;
        $aktiv = $item !== null ? $preise->activeFor($item->id) : null;

        return view('foodalchemist::livewire.suppliers.item-modal', [
            'item' => $item,
            'darfEdit' => $item !== null && Curate::canCurate(Auth::user(), $item),
            'historie' => $item !== null ? $preise->historyFor($item->id) : collect(),
            'allergenLabels' => FoodAlchemistItemAllergen::ALLERGENE,
            'deklarationLabels' => FoodAlchemistItemDeclaration::STOFFE,
            'deklarationQuelle' => $item?->declarations?->quelle,
            'allergenQuelle' => $item?->allergens?->quelle,
            'aktiverPreis' => $aktiv,
            'vergleichspreis' => $item !== null && $aktiv !== null
                ? $preise->vergleichspreis($item, (float) $aktiv->price)
                : null,
        ]);
    }

    private function item(?int $id): FoodAlchemistSupplierItem
    {
        return FoodAlchemistSupplierItem::visibleToTeam($this->team())
            ->with(['supplier:id,name', 'structure.gp:id,name'])
            ->findOrFail($id);
    }

    private function team()
    {
        return Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
    }
}
