<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Spec 17/S2 — Bestellzeile PRO ARTIKEL. source_contributions {source_ref: base_g}
 * trägt die Quell-Beiträge; needed_base_g = Summe, qty_packs = ceil(Summe ÷ Gebinde) (E3/E10).
 * Snapshot-Spalten (article_number … pack_price) frieren beim send den Beleg ein (E2).
 */
class FoodAlchemistOrderLine extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_order_lines';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'source_contributions' => 'array',
        'needed_base_g' => 'decimal:2',
        'qty_packs' => 'decimal:2',
        'is_manual_qty' => 'boolean',
        'pack_qty' => 'decimal:3',
        'pack_price' => 'decimal:4',
        'line_total' => 'decimal:2',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistOrder::class, 'order_id');
    }

    public function supplierItem(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistSupplierItem::class, 'supplier_item_id');
    }

    public function gp(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistGp::class, 'gp_id');
    }
}
