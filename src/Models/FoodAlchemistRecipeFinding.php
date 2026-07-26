<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * Spec 21 · S5a — ein persistierter KI-Befund am Rezept (Erzeuger: `RecipeReviewService`).
 *
 * Bewusst **ohne** `BelongsToTeamHierarchy`: ein Befund ist keine Katalog-Zeile, die
 * ein Kind-Team mitbenutzt, sondern das Urteil EINES Teams über ein Rezept. Eltern
 * und Kind dürfen zum selben Rezept getrennte Befunde führen und getrennt darüber
 * entscheiden — geerbte Sichtbarkeit würde fremde Entscheidungen einmischen.
 */
class FoodAlchemistRecipeFinding extends Model
{
    use HasUuidV7, LogsActivity, SoftDeletes;

    /** Offen = Signal-Kandidat; die übrigen drei sind Endzustände (verworfen bleibt verworfen). */
    public const STATUS = ['offen', 'uebernommen', 'verworfen', 'verschwunden'];

    protected $table = 'foodalchemist_recipe_findings';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'quantity' => 'float',
        'confidence' => 'float',
        'auto_applicable' => 'boolean',
        'seen_count' => 'integer',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'decided_at' => 'datetime',
    ];

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistRecipe::class, 'recipe_id');
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistRecipeIngredient::class, 'ingredient_id');
    }
}
