<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Niveau-Eignung eines Rezepts (D-5 §2.3, slug-basiert wie Quelle;
 * vocab_niveau folgt mit V-20). source manual|ai_inferred + Konfidenz (GL-07).
 */
class FoodAlchemistRecipeNiveauEignung extends Model
{
    use HasUuidV7, LogsActivity, SoftDeletes;

    protected $table = 'foodalchemist_recipe_level_suitability';

    protected $guarded = ['id'];

    protected $casts = ['ai_confidence' => 'decimal:3'];

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistRecipe::class, 'recipe_id');
    }
}
