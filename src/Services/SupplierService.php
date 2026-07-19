<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\SupplierStatus;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierContact;

/**
 * M2-01 / D-2: Lieferanten-Stamm — Liste mit den P-7-Zählern
 * („n Artikel · m gemapped" = LA-First-Fortschritt auf einen Blick).
 */
class SupplierService
{
    /**
     * Lieferanten der Team-Kette mit item_count + mapped_count (eine GROUP-BY-Query je Zähler).
     */
    public function listWithCounts(Team $team, bool $includeInactive = false, string $search = ''): Collection
    {
        $itemCounts = DB::table('foodalchemist_supplier_items')
            ->whereNull('deleted_at')
            ->selectRaw('supplier_id, COUNT(*) AS n')
            ->groupBy('supplier_id')
            ->pluck('n', 'supplier_id');

        $mappedCounts = DB::table('foodalchemist_supplier_item_structures AS s')
            ->join('foodalchemist_supplier_items AS i', 'i.id', '=', 's.supplier_item_id')
            ->whereNull('s.deleted_at')
            ->whereNotNull('s.gp_id')
            ->selectRaw('i.supplier_id, COUNT(*) AS n')
            ->groupBy('i.supplier_id')
            ->pluck('n', 'supplier_id');

        return FoodAlchemistSupplier::visibleToTeam($team)
            ->when(! $includeInactive, fn ($q) => $q->where('is_inactive', false))
            ->when($search !== '', fn ($q) => $q->where('name', 'like', '%' . $search . '%'))
            ->orderBy('name')
            ->get()
            ->each(function ($supplier) use ($itemCounts, $mappedCounts) {
                $supplier->setAttribute('item_count', (int) ($itemCounts[$supplier->id] ?? 0));
                $supplier->setAttribute('mapped_count', (int) ($mappedCounts[$supplier->id] ?? 0));
            });
    }

    // ── Anlage & Lebenszyklus (Dominique-Feedback 2026-06-11, D-2 §1) ───

    /** „+ Neuer Lieferant": gehört dem anlegenden Team (D1 — Kind-eigene möglich). */
    public function create(Team $team, array $input): FoodAlchemistSupplier
    {
        $name = trim($input['name'] ?? '');
        if ($name === '') {
            throw new \RuntimeException('Lieferanten-Name ist Pflicht.');
        }
        if (FoodAlchemistSupplier::visibleToTeam($team)->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->exists()) {
            throw new \RuntimeException("Lieferant [{$name}] existiert bereits in der Team-Kette."); // V-06
        }

        return FoodAlchemistSupplier::create([
            'team_id' => $team->id,
            'name' => $name,
            'city' => ($input['city'] ?? '') ?: null,
            'email_order' => ($input['email_order'] ?? '') ?: null,
            'homepage' => ($input['homepage'] ?? '') ?: null,
        ]);
    }

    /** „Bearbeiten": Stammdaten-Pflege, nur Besitzer-Team (D1). */
    public function update(Team $team, int $id, array $input): FoodAlchemistSupplier
    {
        $supplier = FoodAlchemistSupplier::visibleToTeam($team)->findOrFail($id);
        if (! $supplier->isOwnedBy($team)) {
            throw new \RuntimeException('Geerbter Katalog-Lieferant — Pflege nur durch das Besitzer-Team (D1).');
        }
        $name = trim($input['name'] ?? '');
        if ($name === '') {
            throw new \RuntimeException('Lieferanten-Name ist Pflicht.');
        }

        $supplier->update([
            'name' => $name,
            'city' => ($input['city'] ?? '') ?: null,
            'address' => ($input['address'] ?? '') ?: null,
            'postal_code' => ($input['postal_code'] ?? '') ?: null,
            'email_order' => ($input['email_order'] ?? '') ?: null,
            'homepage' => ($input['homepage'] ?? '') ?: null,
        ]);

        return $supplier;
    }

    /** Inaktiv = soft (Liste blendet aus), nur Besitzer-Team (D1). */
    public function setInactive(Team $team, int $id, bool $inactive): void
    {
        $supplier = FoodAlchemistSupplier::visibleToTeam($team)->findOrFail($id);
        if (! $supplier->isOwnedBy($team)) {
            throw new \RuntimeException('Geerbter Katalog-Lieferant — nur das Besitzer-Team darf (de)aktivieren (D1).');
        }
        $supplier->update(['is_inactive' => $inactive]);
    }

    // ── R9.1: Beziehungs-Ebene (Status · Konditionen · Kontakte · Stammblatt) ──

    /** R9.1 (E1): Beziehungs-Status setzen (aktiv/zweitquelle/gesperrt), nur Besitzer-Team. */
    public function setStatus(Team $team, int $id, SupplierStatus|string $status): FoodAlchemistSupplier
    {
        $supplier = $this->ownedOrFail($team, $id);
        $wert = $status instanceof SupplierStatus ? $status : (SupplierStatus::tryFrom($status)
            ?? throw new \RuntimeException("Unbekannter Status [{$status}]. Erlaubt: aktiv, zweitquelle, gesperrt."));
        $supplier->update(['status' => $wert]);

        return $supplier;
    }

    /** R9.1 (E4): Konditionen pflegen (nur gesetzte Keys), nur Besitzer-Team. */
    public function updateConditions(Team $team, int $id, array $input): FoodAlchemistSupplier
    {
        $supplier = $this->ownedOrFail($team, $id);
        $daten = [];
        foreach (['rebate_pct', 'payment_term_days', 'min_order_value', 'free_shipping_threshold'] as $k) {
            if (array_key_exists($k, $input)) {
                $daten[$k] = $input[$k] === '' ? null : $input[$k];
            }
        }
        if ($daten !== []) {
            $supplier->update($daten);
        }

        return $supplier;
    }

    /** R9.1 (E1): Ansprechpartner hinzufügen (Supplier sichtbar; Kontakt gehört dem Team). */
    public function addContact(Team $team, int $supplierId, array $input): FoodAlchemistSupplierContact
    {
        if (! FoodAlchemistSupplier::visibleToTeam($team)->whereKey($supplierId)->exists()) {
            throw new \RuntimeException('Lieferant nicht im Zugriff (D1).');
        }
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            throw new \RuntimeException('Kontakt-Name ist Pflicht.');
        }

        return FoodAlchemistSupplierContact::create([
            'team_id' => $team->id,
            'supplier_id' => $supplierId,
            'name' => $name,
            'role' => ($input['role'] ?? '') ?: null,
            'phone' => ($input['phone'] ?? '') ?: null,
            'email' => ($input['email'] ?? '') ?: null,
        ]);
    }

    /**
     * R9.1: Stammblatt-Aggregat für die Detailseite/MCP — Stammdaten + Status +
     * Konditionen + Kontakte + Absprachen + Dokumente + WG-Abdeckung.
     */
    public function stammblatt(Team $team, int $id): array
    {
        $supplier = FoodAlchemistSupplier::visibleToTeam($team)->with('contacts')->findOrFail($id);
        $agreements = app(SupplierAgreementService::class);

        // WG-Abdeckung: für welche Warengruppen ist dieser Lieferant Stamm (in der Team-Kette)?
        // NULL commodity_group_code = globaler Stamm.
        $wgAbdeckung = DB::table('foodalchemist_preferred_suppliers')
            ->where('supplier_id', $id)->whereNull('deleted_at')
            ->whereIn('team_id', FoodAlchemistSupplier::teamAncestryIds($team))
            ->pluck('commodity_group_code')
            ->map(fn ($c) => $c ?? '(global)')->unique()->values()->all();

        return [
            'id' => (int) $supplier->id,
            'name' => $supplier->name,
            'status' => $supplier->status instanceof SupplierStatus ? $supplier->status->value : 'aktiv',
            'is_inactive' => (bool) $supplier->is_inactive,
            'is_owned' => $supplier->isOwnedBy($team),
            'stammdaten' => [
                'branch' => $supplier->branch,
                'gln' => $supplier->gln,
                'postal_code' => $supplier->postal_code,
                'city' => $supplier->city,
                'address' => $supplier->address,
                'homepage' => $supplier->homepage,
                'email_order' => $supplier->email_order,
            ],
            'konditionen' => [
                'rebate_pct' => $supplier->rebate_pct !== null ? (float) $supplier->rebate_pct : null,
                'payment_term_days' => $supplier->payment_term_days,
                'min_order_value' => $supplier->min_order_value !== null ? (float) $supplier->min_order_value : null,
                'free_shipping_threshold' => $supplier->free_shipping_threshold !== null ? (float) $supplier->free_shipping_threshold : null,
            ],
            'kontakte' => $supplier->contacts->map(fn ($c) => [
                'id' => (int) $c->id, 'name' => $c->name, 'role' => $c->role, 'phone' => $c->phone, 'email' => $c->email,
            ])->all(),
            'absprachen' => $agreements->forSupplier($team, $id)->map(fn ($a) => [
                'id' => (int) $a->id, 'type' => $a->type, 'note' => $a->note,
                'valid_from' => $a->valid_from?->toDateString(), 'valid_to' => $a->valid_to?->toDateString(),
                'follow_up_at' => $a->follow_up_at?->toDateString(), 'author_id' => $a->author_id,
            ])->all(),
            'dokumente' => $agreements->documentsFor($team, $id)->map(fn ($d) => [
                'id' => (int) $d->id, 'kind' => $d->kind, 'title' => $d->title, 'file_ref' => $d->file_ref,
                'term_start' => $d->term_start?->toDateString(), 'term_end' => $d->term_end?->toDateString(),
                'notice_period_days' => $d->notice_period_days, 'notice_deadline' => $d->noticeDeadline()?->toDateString(),
            ])->all(),
            'wg_abdeckung' => $wgAbdeckung,
        ];
    }

    /** Sichtbar + team-eigen, sonst sprechender Fehler (D1-Schreibrecht). */
    private function ownedOrFail(Team $team, int $id): FoodAlchemistSupplier
    {
        $supplier = FoodAlchemistSupplier::visibleToTeam($team)->findOrFail($id);
        if (! $supplier->isOwnedBy($team)) {
            throw new \RuntimeException('Geerbter Katalog-Lieferant — Beziehungs-Pflege nur durch das Besitzer-Team (D1).');
        }

        return $supplier;
    }
}
