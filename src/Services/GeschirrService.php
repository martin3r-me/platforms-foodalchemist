<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGeschirrItem;
use Platform\FoodAlchemist\Models\FoodAlchemistGeschirrSupplier;
use RuntimeException;

/**
 * #388 Geschirr-Datenbank — Lieferant- + Artikel-CRUD (non-food).
 *
 * Bündelt, was im food-Lieferanten-Modul auf SupplierService + SupplierItemService
 * verteilt ist (Geschirr hat kein GP-Mapping → kein Bedarf für zwei Klassen).
 * Team-Scoping wie überall: visibleToTeam() lesen, isOwnedBy() schreiben.
 */
class GeschirrService
{
    // ── Lieferant ───────────────────────────────────────────────────────

    /** Geschirr-Lieferanten der Team-Kette + item_count (eine GROUP-BY-Query). */
    public function listSuppliersWithCounts(Team $team, bool $includeInactive = false, string $search = ''): Collection
    {
        $itemCounts = DB::table('foodalchemist_tableware_items')
            ->whereNull('deleted_at')
            ->selectRaw('geschirr_supplier_id, COUNT(*) AS n')
            ->groupBy('geschirr_supplier_id')
            ->pluck('n', 'geschirr_supplier_id');

        return FoodAlchemistGeschirrSupplier::visibleToTeam($team)
            ->when(! $includeInactive, fn ($q) => $q->where('is_inactive', false))
            ->when($search !== '', fn ($q) => $q->where('name', 'like', '%' . $search . '%'))
            ->orderBy('name')
            ->get()
            ->each(fn ($s) => $s->setAttribute('item_count', (int) ($itemCounts[$s->id] ?? 0)));
    }

    public function createSupplier(Team $team, array $input): FoodAlchemistGeschirrSupplier
    {
        $name = trim($input['name'] ?? '');
        if ($name === '') {
            throw new RuntimeException('Lieferanten-Name ist Pflicht.');
        }
        if (FoodAlchemistGeschirrSupplier::visibleToTeam($team)->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->exists()) {
            throw new RuntimeException("Geschirr-Lieferant [{$name}] existiert bereits in der Team-Kette.");
        }

        return FoodAlchemistGeschirrSupplier::create([
            'team_id' => $team->id,
            'name' => $name,
            'city' => ($input['city'] ?? '') ?: null,
            'email_order' => ($input['email_order'] ?? '') ?: null,
            'homepage' => ($input['homepage'] ?? '') ?: null,
            'telefon' => ($input['telefon'] ?? '') ?: null,
        ]);
    }

    public function updateSupplier(Team $team, int $id, array $input): FoodAlchemistGeschirrSupplier
    {
        $supplier = FoodAlchemistGeschirrSupplier::visibleToTeam($team)->findOrFail($id);
        if (! $supplier->isOwnedBy($team)) {
            throw new RuntimeException('Geerbter Geschirr-Lieferant — Pflege nur durch das Besitzer-Team.');
        }
        $name = trim($input['name'] ?? '');
        if ($name === '') {
            throw new RuntimeException('Lieferanten-Name ist Pflicht.');
        }

        $supplier->update([
            'name' => $name,
            'city' => ($input['city'] ?? '') ?: null,
            'address' => ($input['address'] ?? '') ?: null,
            'postal_code' => ($input['postal_code'] ?? '') ?: null,
            'email_order' => ($input['email_order'] ?? '') ?: null,
            'homepage' => ($input['homepage'] ?? '') ?: null,
            'telefon' => ($input['telefon'] ?? '') ?: null,
        ]);

        return $supplier;
    }

    public function setSupplierInactive(Team $team, int $id, bool $inactive): void
    {
        $supplier = FoodAlchemistGeschirrSupplier::visibleToTeam($team)->findOrFail($id);
        if (! $supplier->isOwnedBy($team)) {
            throw new RuntimeException('Geerbter Geschirr-Lieferant — nur das Besitzer-Team darf (de)aktivieren.');
        }
        $supplier->update(['is_inactive' => $inactive]);
    }

    // ── Artikel ─────────────────────────────────────────────────────────

    private function scopedItems(Team $team): Builder
    {
        return FoodAlchemistGeschirrItem::visibleToTeam($team)->with('supplier:id,name');
    }

    public function paginateForSupplier(Team $team, int $supplierId, array $filters = [], int $perPage = 100): LengthAwarePaginator
    {
        return $this->scopedItems($team)
            ->where('geschirr_supplier_id', $supplierId)
            ->when($filters['onlyActive'] ?? true, fn ($q) => $q->where('is_inactive', false))
            ->when($q = trim($filters['q'] ?? ''), fn ($w) => $w->where(fn ($x) => $x
                ->whereRaw('LOWER(label) LIKE ?', ['%' . mb_strtolower($q) . '%'])
                ->orWhere('artikel_nr', 'like', $q . '%')))
            ->orderBy('label')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function searchGlobal(Team $team, string $q, array $filters = [], int $perPage = 100): LengthAwarePaginator
    {
        return $this->scopedItems($team)
            ->when($filters['onlyActive'] ?? true, fn ($x) => $x->where('is_inactive', false))
            ->where(fn ($w) => $w
                ->whereRaw('LOWER(label) LIKE ?', ['%' . mb_strtolower($q) . '%'])
                ->orWhere('artikel_nr', 'like', $q . '%'))
            ->orderBy('label')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function findItem(int $id, Team $team): ?FoodAlchemistGeschirrItem
    {
        return $this->scopedItems($team)->find($id);
    }

    /** Leichtgewichtige Suche für den Geschirr-Picker im Concepter (Gericht-Slot). */
    public function searchItems(Team $team, string $q, int $limit = 12, ?int $bevorzugtVehikelId = null): Collection
    {
        return FoodAlchemistGeschirrItem::visibleToTeam($team)
            ->with('supplier:id,name')
            ->where('is_inactive', false)
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x
                ->whereRaw('LOWER(label) LIKE ?', ['%' . mb_strtolower($q) . '%'])
                ->orWhereRaw('LOWER(kategorie) LIKE ?', ['%' . mb_strtolower($q) . '%'])))
            // A2: Teile mit passendem Servier-Vehikel-Typ zuerst (weiches Ranking, kein
            // Hard-Filter — ungemappte Kataloge bleiben voll benutzbar)
            ->when($bevorzugtVehikelId !== null, fn ($w) => $w
                ->orderByRaw('CASE WHEN vehicle_vocab_id = ? THEN 0 ELSE 1 END', [$bevorzugtVehikelId]))
            ->orderBy('label')
            ->limit($limit)
            ->get();
    }

    public function createItem(Team $team, int $supplierId, array $input): FoodAlchemistGeschirrItem
    {
        if (! FoodAlchemistGeschirrSupplier::visibleToTeam($team)->whereKey($supplierId)->exists()) {
            throw new RuntimeException('Geschirr-Lieferant nicht sichtbar.');
        }
        $bez = trim($input['label'] ?? '');
        if ($bez === '') {
            throw new RuntimeException('Bezeichnung ist Pflicht.');
        }

        return FoodAlchemistGeschirrItem::create($this->itemFelder($input) + [
            'team_id' => $team->id,
            'geschirr_supplier_id' => $supplierId,
            'label' => $bez,
        ]);
    }

    public function updateItem(Team $team, int $id, array $input): FoodAlchemistGeschirrItem
    {
        $item = FoodAlchemistGeschirrItem::visibleToTeam($team)->findOrFail($id);
        if (! $item->isOwnedBy($team)) {
            throw new RuntimeException('Geerbter Geschirr-Artikel — Pflege nur durch das Besitzer-Team.');
        }
        $bez = trim($input['label'] ?? $item->label);
        if ($bez === '') {
            throw new RuntimeException('Bezeichnung ist Pflicht.');
        }
        $item->update($this->itemFelder($input) + ['label' => $bez]);

        return $item;
    }

    public function setItemInactive(Team $team, int $id, bool $inactive): void
    {
        $item = FoodAlchemistGeschirrItem::visibleToTeam($team)->findOrFail($id);
        if (! $item->isOwnedBy($team)) {
            throw new RuntimeException('Geerbter Geschirr-Artikel — nur das Besitzer-Team darf (de)aktivieren.');
        }
        $item->update(['is_inactive' => $inactive]);
    }

    /** Normalisiert das Editier-Formular auf DB-Felder (leere Strings → null, Zahlen casten). */
    private function itemFelder(array $in): array
    {
        $str = fn (string $k) => isset($in[$k]) && trim((string) $in[$k]) !== '' ? trim((string) $in[$k]) : null;
        // is_numeric/≥0-Guard (analog VocabularyService::dezimalOrNull): ein Tippfehler darf
        // nicht still als 0 landen — 0,00 € Leihpreis/Pfand bzw. 0 mm/g sind irreführend.
        $num = function (string $k) use ($in) {
            if (! isset($in[$k]) || trim((string) $in[$k]) === '') {
                return null;
            }
            $clean = str_replace(',', '.', trim((string) $in[$k]));

            return is_numeric($clean) && (float) $clean >= 0 ? (float) $clean : null;
        };

        return [
            'artikel_nr' => $str('artikel_nr'),
            'kategorie' => $str('kategorie'),
            'material' => $str('material'),
            'form' => $str('form'),
            'farbe' => $str('farbe'),
            'durchmesser_mm' => $num('durchmesser_mm'),
            'laenge_mm' => $num('laenge_mm'),
            'breite_mm' => $num('breite_mm'),
            'hoehe_mm' => $num('hoehe_mm'),
            'volumen_ml' => $num('volumen_ml'),
            'gewicht_g' => $num('gewicht_g'),
            'leihpreis' => $num('leihpreis'),
            'pfand' => $num('pfand'),
            'unit' => $str('unit') ?? 'Stk',
            'note' => $str('note'),
            // A2: Servier-Vehikel-Typ (abstrakte Präsentationsform ↔ konkretes Mietteil)
            'vehicle_vocab_id' => isset($in['vehicle_vocab_id']) && $in['vehicle_vocab_id'] !== ''
                ? (int) $in['vehicle_vocab_id'] : null,
        ];
    }
}
