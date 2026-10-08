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
        'pack_units' => 'decimal:3',
        'unit_base' => 'decimal:4',
        'counted_packs' => 'decimal:3',
        'counted_units' => 'decimal:3',
        'counted_loose' => 'decimal:4',
    ];

    public function bin(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistStorageBin::class, 'storage_bin_id');
    }

    /** Spec 66b: Gebinde bekannt → Zählen in Karton/Einheit/lose möglich. */
    public function hatGebinde(): bool
    {
        return $this->unit_base !== null && (float) $this->unit_base > 0;
    }

    public function inventur(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistInventoryCount::class, 'inventory_count_id');
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistRecipe::class, 'recipe_id');
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
