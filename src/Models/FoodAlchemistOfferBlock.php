<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Angebot-Block (#380 Composer) — polymorphe Inhalts-Zeile eines
 * Angebot-Kapitels, diskriminiert über `type`. Spiegelt FoodAlchemistFoodbookBlock,
 * in eigener Tabelle. concept_ref referenziert ein Concept (live). Format wird NICHT
 * als Block eingesetzt, sondern als Format-Kapitel (Kapitel.format_id) — wie Foodbook.
 */
class FoodAlchemistOfferBlock extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_offer_blocks';

    protected $guarded = ['id'];

    /** Erlaubte Block-Typen (Enum im PHP-Layer). recipe_ref = Parität, im Picker vorerst aus. */
    public const BLOCK_TYPES = ['concept_ref', 'recipe_ref', 'header', 'header_preis', 'text', 'spacer', 'image'];

    /** Kanonische Preis-Basen für header_preis (Vokabular-Pflicht). */
    public const PRICE_BASES = ['person', 'pauschal'];

    protected $casts = [
        'uuid' => 'string',
        'position' => 'integer',
        'level' => 'integer',
        'visible' => 'boolean',
        'quantity' => 'decimal:3',
        'price_value' => 'decimal:2',
        'presentation_id' => 'integer',
        'payload_json' => 'array',
    ];

    public function chapter(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistOfferChapter::class, 'chapter_id');
    }

    /** concept_ref: das referenzierte Concept (live). */
    public function concept(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistConcept::class, 'concept_id');
    }

    /** recipe_ref: einzelnes Gericht (VK-Rezept). */
    public function dish(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistRecipe::class, 'sales_recipe_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistVocabEinheit::class, 'unit_vocab_id');
    }

    /** Expliziter Darreichungs-Override (loser Zeiger, keine harte FK). */
    public function presentation(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistRecipeDarreichung::class, 'presentation_id');
    }
}
