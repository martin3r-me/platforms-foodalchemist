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
 * @ai.description Foodbook-Block (M11) — polymorphe Inhalts-Zeile eines Kapitels,
 * diskriminiert über `type` ∈ {concept_ref, recipe_ref, header, text, spacer, image}.
 * Wahl-Gruppen „A|B|C" über `variant_group_id`. concept_ref referenziert ein Concept
 * (live), recipe_ref ein einzelnes Gericht (VK-Rezept).
 */
class FoodAlchemistFoodbookBlock extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_foodbook_blocks';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'position' => 'integer',
        'ebene' => 'integer',
        'sichtbar' => 'boolean',
        'variant_group_id' => 'integer',
        'quantity' => 'decimal:3',
        'price_value' => 'decimal:2',
        'payload_json' => 'array',
    ];

    public function kapitel(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistFoodbookKapitel::class, 'chapter_id');
    }

    /** concept_ref: das referenzierte Concept (live). */
    public function concept(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistConcept::class, 'concept_id');
    }

    /** recipe_ref: einzelnes Gericht (VK-Rezept). */
    public function gericht(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistRecipe::class, 'sales_recipe_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistVocabEinheit::class, 'unit_vocab_id');
    }

    /** Staffelpreise (nur bei preis_basis='staffel'). */
    public function staffel(): HasMany
    {
        return $this->hasMany(FoodAlchemistFoodbookBlockStaffel::class, 'block_id')->orderBy('min_personen');
    }
}
