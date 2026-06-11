<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description LA-Nährwerte je 100 g Rohmasse (M2-17, GL-08-Quelle, BLS-Daten).
 * LMIV-Kernwerte: energy_kcal, protein, fat, carbs_absorbable; Salz = sodium × 0.0025 (GL-08).
 */
class FoodAlchemistItemNutritional extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    /** GL-08-Kernwerte für die Ø-Aggregation (GP) bzw. Summen (Rezept). */
    public const KERNWERTE = ['energy_kcal', 'protein', 'fat', 'carbs_absorbable', 'sodium'];

    protected $table = 'foodalchemist_item_nutritionals';

    protected $guarded = ['id'];

    protected $casts = ['raw_json' => 'array'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistSupplierItem::class, 'supplier_item_id');
    }

    /** GL-08: Salz (g) = Natrium (mg) × 0.0025. */
    public function salzG(): ?float
    {
        return $this->sodium !== null ? (float) $this->sodium * 0.0025 : null;
    }
}
