<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Komponenten-Delta einer Darreichung (Stufe 2, Umbau-Spec §1.3):
 * pro Zutatenzeile des Kernrezepts Menge überschreiben ODER weglassen.
 * Grenzregel E5: nur reduzieren/umgewichten/weglassen — NIE neue Zutaten
 * (hält die Allergen-Deklaration des Kerns als sichere Obergrenze).
 */
class FoodAlchemistRecipeDarreichungDelta extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_recipe_presentation_deltas';

    protected $guarded = ['id'];

    protected $casts = [
        'quantity_override_g' => 'float',
        'omitted' => 'bool',
    ];

    public function darreichung()
    {
        return $this->belongsTo(FoodAlchemistRecipeDarreichung::class, 'presentation_id');
    }

    public function ingredient()
    {
        return $this->belongsTo(FoodAlchemistRecipeIngredient::class, 'recipe_ingredient_id');
    }
}
