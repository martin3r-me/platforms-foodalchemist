<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description LA-Allergene (M2-09, GL-01-Quelle): 14 EU-Allergene im 4-Wert-Modell
 * je Lieferantenartikel; NULL = unbekannt. details = Getreide-/Nuss-Unterarten.
 */
class FoodAlchemistItemAllergen extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    public const ALLERGENE = [
        'gluten' => 'Glutenhaltiges Getreide',
        'crustaceans' => 'Krebstiere',
        'eggs' => 'Eier',
        'fish' => 'Fisch',
        'peanuts' => 'Erdnüsse',
        'soy' => 'Soja',
        'milk' => 'Milch',
        'tree_nuts' => 'Schalenfrüchte',
        'celery' => 'Sellerie',
        'mustard' => 'Senf',
        'sesame' => 'Sesam',
        'sulphites' => 'Schwefeldioxid & Sulfite',
        'lupin' => 'Lupinen',
        'molluscs' => 'Weichtiere',
    ];

    protected $table = 'foodalchemist_item_allergens';

    protected $guarded = ['id'];

    protected $casts = ['details' => 'array'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistSupplierItem::class, 'supplier_item_id');
    }
}
