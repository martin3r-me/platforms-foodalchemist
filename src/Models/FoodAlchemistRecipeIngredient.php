<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Enums\MatchMethod;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Rezept-Zutat (D-5 §2.2): gp_id XOR referenced_recipe_id
 * (Service-erzwungen), match_method als Enum-Cast (GL-04 §2.3 — verhindert A-10).
 * Schreibwege über RecipeService (jede Mutation triggert recomputeAndPropagate).
 */
class FoodAlchemistRecipeIngredient extends Model
{
    use HasUuidV7, LogsActivity, SoftDeletes;

    protected $table = 'foodalchemist_recipe_ingredients';

    protected $guarded = ['id'];

    protected $casts = [
        'match_method' => MatchMethod::class,
        'match_confidence' => 'decimal:3',
        'quantity' => 'decimal:4',
        'quantity_max' => 'decimal:4',
        'trimming_loss_pct' => 'decimal:2',
        'cooking_loss_pct' => 'decimal:2',
        'is_optional' => 'boolean',
        'is_value_relevant' => 'boolean',
        'position' => 'integer',
    ];

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistRecipe::class, 'recipe_id');
    }

    public function gp(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistGp::class, 'gp_id');
    }

    public function referencedRecipe(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistRecipe::class, 'referenced_recipe_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistVocabEinheit::class, 'unit_vocab_id');
    }
}
