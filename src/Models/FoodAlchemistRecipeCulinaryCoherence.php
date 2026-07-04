<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Judge-Cache je Rezept (GL-10 §2, zweite Achse): Kohärenz-Urteil
 * (score/label/reasoning/schwachstelle) + Teller-Heber-Vorschläge; stale,
 * sobald components_hash nicht mehr zum Zutaten-Stand passt.
 */
class FoodAlchemistRecipeCulinaryCoherence extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    /** Teller-Heber-Vorschlagstypen (Ist-App: Kontrast/Ergänzung/Veredelung). */
    public const HEBER_TYPEN = ['kontrast', 'ergaenzung', 'veredelung'];

    protected $table = 'foodalchemist_recipe_culinary_coherence';

    protected $guarded = ['id'];

    protected $casts = [
        'heber_json' => 'array',
        'judged_at' => 'datetime',
        'heber_at' => 'datetime',
    ];

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistRecipe::class, 'recipe_id');
    }
}
