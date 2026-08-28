<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * Spec 43 (Bild-Epic) — zusätzliches Bild eines Concepts (kleine Galerie neben dem
 * Titelbild auf foodalchemist_concepts). team_id erbt vom Concept.
 */
class FoodAlchemistConceptImage extends Model
{
    use HasUuidV7, SoftDeletes;

    protected $table = 'foodalchemist_concept_images';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'sort_order' => 'integer',
    ];

    public function concept(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistConcept::class, 'concept_id');
    }
}
