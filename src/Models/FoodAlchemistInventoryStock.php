<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description WaWi light — aktueller Lagerbestand je Grundprodukt/Lieferantenartikel.
 * Bewegungswahrheit liegt in FoodAlchemistInventoryMovement; dieser Datensatz ist der
 * schnelle aktuelle Saldo.
 */
class FoodAlchemistInventoryStock extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_inventory_stocks';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'qty_base' => 'decimal:4',
    ];

    public function gp(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistGp::class, 'gp_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistInventoryLocation::class, 'inventory_location_id');
    }

    /** Spec 69: Eigenproduktion — Bestand eines Rezepts (Basisrezept in g/Stk, Gericht in Portionen). */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistRecipe::class, 'recipe_id');
    }

    public function supplierItem(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistSupplierItem::class, 'supplier_item_id');
    }
}
