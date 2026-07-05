<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;

/**
 * @ai.description Strukturierte LA-Schicht (Kern-IP): kuratierte Klassifikation eines
 * Lieferantenartikels + GP-Zuordnung (GL-05). needs_review = Review-Queue (V-10).
 */
class FoodAlchemistSupplierItemStructure extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_supplier_item_structures';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'is_food' => 'boolean',
        'is_primary_flavor_carrier' => 'boolean',
        'is_bio' => 'boolean',
        'is_halal' => 'boolean',
        'is_vegan' => 'boolean',
        'needs_review' => 'boolean',
        'main_ingredient_confidence' => 'decimal:3',
        'flavor_ingredients_confidence' => 'decimal:3',
        'processing_confidence' => 'decimal:3',
        'commodity_group_confidence' => 'decimal:3',
        'classified_at' => 'datetime',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistSupplierItem::class, 'supplier_item_id');
    }

    public function gp(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistGp::class, 'gp_id');
    }
}
