<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Paket-Skizzengruppe der Kreativ-Ebene (Spec 19 „Foodbook-Leitstelle", M4).
 * Bündelt mehrere `FoodAlchemistDishIdea` (Einzel-Skizzen) zu einem geplanten Paket; `name`
 * wird beim Kapitel-Go (E7.3) zum Konzept-Namen, `target_price_pp` zum €/Gast-Ziel des Konzepts.
 * XOR-Zugehörigkeit chapter_id/concept_id (Service-Guard). Erdet NICHTS — erst das Go
 * materialisiert (`materialized_concept_id` = erzeugtes Konzept).
 */
class FoodAlchemistDishIdeaGroup extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_dish_idea_groups';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'target_price_pp' => 'decimal:2',
        'position' => 'integer',
    ];

    /** Owner-Kapitel (XOR mit concept). */
    public function chapter(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistFoodbookKapitel::class, 'chapter_id');
    }

    /** Owner-Konzept (XOR mit chapter). */
    public function concept(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistConcept::class, 'concept_id');
    }

    /** Skizzen dieses Pakets. */
    public function ideas(): HasMany
    {
        return $this->hasMany(FoodAlchemistDishIdea::class, 'group_id');
    }

    /** Beim Kapitel-Go erzeugtes Konzept (loser Zeiger, kein Cascade). */
    public function materializedConcept(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistConcept::class, 'materialized_concept_id');
    }
}
