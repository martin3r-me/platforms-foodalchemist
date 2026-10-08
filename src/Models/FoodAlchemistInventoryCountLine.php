<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Zählposition einer Inventur (Spec 66): Soll-Bestand und Bewertungspreis je
 * Basiseinheit sind beim Anlegen eingefroren; qty_counted = null heißt „nicht gezählt".
 */
class FoodAlchemistInventoryCountLine extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_inventory_count_lines';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'qty_expected' => 'decimal:4',
        'qty_counted' => 'decimal:4',
        'price_per_base' => 'decimal:6',
    ];

    public function inventur(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistInventoryCount::class, 'inventory_count_id');
    }

    public function gp(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistGp::class, 'gp_id');
    }

    public function supplierItem(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistSupplierItem::class, 'supplier_item_id');
    }

    /** Wert der gezählten Menge (null = nicht gezählt oder kein Preis). */
    public function wert(): ?float
    {
        return $this->qty_counted !== null && $this->price_per_base !== null
            ? round((float) $this->qty_counted * (float) $this->price_per_base, 2) : null;
    }
}
