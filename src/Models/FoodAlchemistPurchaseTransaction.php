<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Einkauf E2 — eine Ist-Einkaufsposition (Menge × Preis × Datum × Lieferant).
 * Zwei Quellen (`source`): necta_import (bulk) | fa_order (abgesendete Bestellschiene).
 * team-scoped; supplier_item_id/gp_id nullbar (noch ungematcht). Schreibwege über
 * PurchaseImportService bzw. den Order→Journal-Spiegel; source_hash = Dedup-Schlüssel.
 */
class FoodAlchemistPurchaseTransaction extends Model
{
    /**
     * Trait wegen des Trait-Vertrags (PolicyTest) — die Abfragen bleiben aber bewusst STRIKT
     * auf `team_id`: eine Ist-Einkaufsposition gehört dem Team, das sie gebucht hat. Ein
     * Kind-Team hat die Rechnungen des Eltern-Teams nicht zu sehen. Wer hier auf
     * `visibleToTeam()` umstellt, ändert die Sichtbarkeit — das ist eine Entscheidung, kein Refactor.
     */
    use BelongsToTeamHierarchy, HasUuidV7, LogsActivity, SoftDeletes;

    protected $table = 'foodalchemist_purchase_transactions';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'qty' => 'decimal:4',
        'unit_price' => 'decimal:4',
        'line_total' => 'decimal:2',
        'purchased_at' => 'date',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistSupplier::class, 'supplier_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistSupplierItem::class, 'supplier_item_id');
    }

    public function gp(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistGp::class, 'gp_id');
    }
}
