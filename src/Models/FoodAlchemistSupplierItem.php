<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;

/**
 * @ai.description Lieferantenartikel (Necta-Katalog) — konkreter Bestell-Artikel mit Gebinde + EANs.
 * 1 GP ↔ n Artikel via Structure-Schicht (GL-05); Preis-Historie in foodalchemist_prices (GL-11).
 */
class FoodAlchemistSupplierItem extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_supplier_items';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'qty' => 'decimal:3',
        'qty_ordering_per_packaging' => 'decimal:3',
        'is_organic' => 'boolean',
        'is_vegan' => 'boolean',
        'is_vegetarian' => 'boolean',
        'is_alcohol' => 'boolean',
        'is_discontinued' => 'boolean',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistSupplier::class, 'supplier_id');
    }

    public function structure(): HasOne
    {
        return $this->hasOne(FoodAlchemistSupplierItemStructure::class, 'supplier_item_id');
    }

    public function allergens(): HasOne
    {
        return $this->hasOne(FoodAlchemistItemAllergen::class, 'supplier_item_id');
    }

    public function prices(): HasMany
    {
        return $this->hasMany(FoodAlchemistPrice::class, 'supplier_item_id');
    }
}
