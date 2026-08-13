<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description WaWi light — Lagerbewegungsjournal. `source_hash` macht automatische
 * Bewegungen aus Wareneingängen idempotent und korrigierbar.
 */
class FoodAlchemistInventoryMovement extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_inventory_movements';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'qty_base' => 'decimal:4',
        'qty_packs' => 'decimal:2',
        'moved_at' => 'datetime',
    ];

    public function stock(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistInventoryStock::class, 'stock_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistInventoryLocation::class, 'inventory_location_id');
    }

    public function gp(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistGp::class, 'gp_id');
    }

    public function supplierItem(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistSupplierItem::class, 'supplier_item_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistOrder::class, 'order_id');
    }

    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistOrderLine::class, 'order_line_id');
    }
}
