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

    /** Kern-Nährwerte je 100 g (speisen die GP-Aggregation, GL-08) */
    public array $naehrwerte = [];

    public ?string $fehler = null;

    #[On('item-modal.oeffnen')]
    public function oeffnen(int $id): void
    {
        $item = $this->item($id);
        $this->itemId = $item->id;
        $this->fehler = null;
        $this->stammdaten = $item->only(['designation', 'article_number', 'brand', 'manufacturer', 'origin', 'marketing_name', 'zusatztext']);
        $this->verpackung = $item->only(['qty', 'unit_code', 'packaging_unit', 'ordering_unit', 'qty_ordering_per_packaging', 'ean_packaging', 'ean_ordering']);
        $this->eigenschaften = $item->only(['is_organic', 'is_vegan', 'is_vegetarian', 'is_alcohol', 'is_halal', 'is_gmo_free', 'is_preorder', 'vat', 'origin_country', 'organic_control_number', 'preorder_days', 'ingredients_lieferant']);
        $this->allergene = app(SupplierItemService::class)->getAllergens($item);
        $this->deklarationen = app(SupplierItemService::class)->getDeclarations($item);
        $this->naehrwerte = app(SupplierItemService::class)->getNutrition($item);
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
                ...collect($this->eigenschaften)->only(['is_organic', 'is_vegan', 'is_vegetarian', 'is_alcohol', 'is_halal', 'is_gmo_free', 'is_preorder'])
                    ->map(fn ($v) => $v === '' || $v === null ? null : (bool) (int) $v)->all(),
                ...collect($this->eigenschaften)->only(['origin_country', 'organic_control_number', 'ingredients_lieferant'])
                    ->map(fn ($v) => $v === '' ? null : $v)->all(),
                'vat' => ($this->eigenschaften['vat'] ?? '') !== '' ? (float) str_replace(',', '.', (string) $this->eigenschaften['vat']) : null,
                'preorder_days' => ($this->eigenschaften['preorder_days'] ?? '') !== '' ? (int) $this->eigenschaften['preorder_days'] : null,
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

    public function naehrwerteSpeichern(): void
    {
        try {
            app(SupplierItemService::class)->setNutrition($this->team(), $this->item($this->itemId), $this->naehrwerte);
            $this->fehler = null;
            $this->dispatch('item-gespeichert');
        } catch (RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function preisAnlegen(): void
    {
        try {
            // Numerik-Guard wie in preisUpdate() — sonst castet (float) einen Tippfehler
            // still auf 0,00 € (0 < 0 ist false → rutscht durch createFor) und vergiftet
            // den GP-Leitpreis an der Wurzel der Kostenkette.
            $roh = str_replace(',', '.', trim((string) $this->preisNeu['preis']));
            if ($roh === '' || ! is_numeric($roh) || (float) $roh < 0) {
                $this->fehler = 'Preis braucht eine Zahl ≥ 0.';

                return;
            }
            app(PriceService::class)->createFor($this->team(), $this->item($this->itemId), (float) $roh, $this->preisNeu['status']);
            $this->preisNeu = ['preis' => '', 'status' => '0'];
            $this->fehler = null;
        } catch (RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    // R12 (Jarvis): Preiszeile bearbeiten (✎) — Preis, gültig bis, Notiz
    public ?int $preisEditId = null;

    /** @var array{preis: string, valid_to: string, note: string} */
    public array $preisEdit = ['preis' => '', 'valid_to' => '', 'note' => ''];

    public function preisBearbeiten(int $priceId): void
    {
        $p = \Platform\FoodAlchemist\Models\FoodAlchemistPrice::where('supplier_item_id', $this->itemId)->find($priceId);
        if ($p === null) {
            return;
        }
        $this->preisEditId = $priceId;
        $this->preisEdit = [
            'preis' => $p->price !== null ? number_format((float) $p->price, 2, ',', '') : '',
            'valid_to' => $p->valid_to ? \Illuminate\Support\Carbon::parse($p->valid_to)->format('Y-m-d') : '',
            'note' => (string) ($p->note ?? ''),
        ];
    }

    public function preisUpdate(): void
    {
        $p = \Platform\FoodAlchemist\Models\FoodAlchemistPrice::where('supplier_item_id', $this->itemId)->find($this->preisEditId);
        if ($p === null || ! Curate::canCurate(Auth::user(), $this->item($this->itemId))) {
            $this->fehler = 'Bearbeiten nur fürs Besitzer-Team (D1).';

            return;
        }
        $preis = str_replace(',', '.', trim($this->preisEdit['preis']));
        if (! is_numeric($preis) || (float) $preis < 0) {
            $this->fehler = 'Preis braucht eine Zahl ≥ 0.';

            return;
        }
        $p->update([
            'price' => (float) $preis,
            'valid_to' => $this->preisEdit['valid_to'] !== '' ? $this->preisEdit['valid_to'] . ' 23:59:59' : null,
            'note' => trim($this->preisEdit['note']) ?: null,
            'change_date' => now(),
        ]);
        $this->preisEditId = null;
        $this->fehler = null;
    }

    public function preisEditAbbrechen(): void
    {
        $this->preisEditId = null;
    }

    public function preisLoeschen(int $priceId): void
    {
        try {
            app(PriceService::class)->deleteFor($this->team(), $this->item($this->itemId), $priceId);
        } catch (RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    // ── R9 (Jarvis «GP-MAPPING»): ✨ KI-Vorschlag + GP zuweisen/lösen am LA ──

    public string $gpSuche = '';

    /** @var array<int, array{gp_id: int, name: string, score: int, grund: string}> */
    public array $gpVorschlaege = [];

    public function kiGpVorschlag(): void
    {
        $this->fehler = null;
        $item = $this->item($this->itemId);
        $this->gpVorschlaege = app(\Platform\FoodAlchemist\Services\MatchService::class)
            ->vorschlaegeFuerLa($item, $this->team())
            ->map(fn ($v) => [
                'gp_id' => $v['gp']->id,
                'name' => $v['gp']->name,
                'score' => (int) round(((float) $v['score']) * 100),
                'grund' => (string) ($v['grund'] ?? $v['methode'] ?? ''),
            ])->all();
        if ($this->gpVorschlaege === []) {
            $this->fehler = 'Kein Match-Kandidat gefunden (MatchService v1 — exakt + fuzzy).';
        }
    }

    public function gpZuweisen(int $gpId): void
    {
        $this->fehler = null;
        $team = $this->team();
        $gp = \Platform\FoodAlchemist\Models\FoodAlchemistGp::visibleToTeam($team)->findOrFail($gpId);
        if (! Curate::canCurate(Auth::user(), $gp)) {
            $this->fehler = 'Globale Katalog-Aktion — nur fürs Kurations-Team (D1).';

            return;
        }
        try {
            app(\Platform\FoodAlchemist\Services\LeadLaService::class)->verknuepfen($team, $gp, $this->itemId);
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        $this->gpSuche = '';
        $this->gpVorschlaege = [];
        $this->dispatch('gp-las-geaendert');
    }

    public function gpLoesen(): void
    {
        $this->fehler = null;
        $team = $this->team();
        $gpId = $this->item($this->itemId)->structure?->gp_id;
        // VOLL laden — die Panel-Relation (structure.gp:id,name) trägt kein team_id (Curate-Gate!)
        $gp = $gpId !== null ? \Platform\FoodAlchemist\Models\FoodAlchemistGp::visibleToTeam($team)->find($gpId) : null;
        if ($gp === null) {
            return;
        }
        if (! Curate::canCurate(Auth::user(), $gp)) {
            $this->fehler = 'Globale Katalog-Aktion — nur fürs Kurations-Team (D1).';

            return;
        }
        try {
            app(\Platform\FoodAlchemist\Services\LeadLaService::class)->entknuepfen($team, $gp, $this->itemId);
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        $this->dispatch('gp-las-geaendert');
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
            'naehrwertFelder' => SupplierItemService::NAEHRWERT_FELDER,
            'deklarationLabels' => FoodAlchemistItemDeclaration::STOFFE,
            'deklarationQuelle' => $item?->declarations?->quelle,
            'allergenQuelle' => $item?->allergens?->quelle,
            'aktiverPreis' => $aktiv,
            'vergleichspreis' => $item !== null && $aktiv !== null
                ? $preise->vergleichspreis($item, (float) $aktiv->price)
                : null,
            // R9: manuelle GP-Suche fürs Mapping
            'gpKandidaten' => $item !== null && trim($this->gpSuche) !== ''
                ? \Platform\FoodAlchemist\Models\FoodAlchemistGp::visibleToTeam($this->team())
                    ->whereRaw('LOWER(name) LIKE ?', ['%' . mb_strtolower(trim($this->gpSuche)) . '%'])
                    ->orderBy('name')->limit(6)->get(['id', 'name'])
                : collect(),
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
