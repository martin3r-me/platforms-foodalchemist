<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Concept↔Sektor-Eignung (M10R-1, Doc 15 §10.8) — mehrwertige
 * Sektor-Eignung am Concept, spiegelbildlich zu recipe_sektor_eignung (VK-Parität).
 * GL-07-Lineage (quelle/ai_confidence/ai_begruendung). Satellit: scopt über das
 * Eltern-Concept (kein eigener BelongsToTeamHierarchy — wie RecipeSektorEignung).
 */
class FoodAlchemistConceptSektorEignung extends Model
{
    use HasUuidV7, LogsActivity, SoftDeletes;

    protected $table = 'foodalchemist_concept_sektor_eignung';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'ai_confidence' => 'decimal:3',
    ];

    public function concept(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistConcept::class, 'concept_id');
    }
}
