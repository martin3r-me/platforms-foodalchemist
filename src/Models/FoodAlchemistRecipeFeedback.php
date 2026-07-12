<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Enums\FeedbackQuelle;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * R2.6 — Ein Feedback-Eintrag an einem Gericht oder Basisrezept (menschliche
 * Quelle Küche/Kunde/Event). Aggregat (Ø/Count) wird on-read gerechnet
 * (FeedbackService), nicht gespeichert. D1: team-eigen; Eltern sieht Kinder
 * aggregiert via visibleToTeam.
 */
class FoodAlchemistRecipeFeedback extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_recipe_feedback';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'quelle' => FeedbackQuelle::class,
        'score' => 'integer',
        'machbarkeit' => 'integer',
        'aufwand' => 'integer',
        'geschmack' => 'integer',
        'gaeste_reaktion' => 'integer',
        'kontext_datum' => 'date',
    ];

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistRecipe::class, 'recipe_id');
    }

    /** „Weiterentwickeln"-Ziel: die aus diesem Feedback erzeugte Draft-Iteration. */
    public function spawnedRecipe(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistRecipe::class, 'spawned_recipe_id');
    }
}
