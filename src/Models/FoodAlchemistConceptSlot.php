<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Concept-Slot (M10-01) — eine Rolle im Concept, gefüllt mit GENAU
 * EINEM: `paket_id` (austauschbares Bündel) ODER `vk_recipe_id` (fest gesetztes
 * Gericht). Der Service erzwingt „genau eines". Position-sortiert.
 */
class FoodAlchemistConceptSlot extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_concept_slots';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'position' => 'integer',
        'is_pflicht' => 'boolean',
        'menge' => 'decimal:3',
    ];

    public function concept(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistConcept::class, 'concept_id');
    }

    /** Befüllung A: austauschbarer Paket. */
    public function paket(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistPaket::class, 'paket_id');
    }

    /** Befüllung B: fest gesetztes Gericht (VK-Rezept). */
    public function gericht(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistRecipe::class, 'vk_recipe_id');
    }

    public function einheit(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistVocabEinheit::class, 'einheit_vocab_id');
    }

    /** True, wenn der Slot durch einen austauschbaren Paket gefüllt ist. */
    public function istPaket(): bool
    {
        return $this->paket_id !== null;
    }
}
