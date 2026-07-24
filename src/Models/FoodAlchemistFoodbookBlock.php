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

    /**
     * Kanonische Preis-Basen (Vokabular-Pflicht Spec 19, Entscheidung 6 — keine freien
     * Strings). blockPreis()/App-UI lesen exakt diese Werte:
     *  - person   = Per-Person (€/Gast, ×Pax)
     *  - pauschal = flacher Anteil (€/Position, NICHT ×Pax)
     *  - staffel  = Pax-gestaffelt (nur header_frei_preis)
     * MCP-Ergonomie-Labels (pro_person/pro_stueck) werden VOR dem Schreiben hierauf
     * gemappt (FoodbookBlocksPostTool::PRICE_BASIS_MAP) — die „pro_stueck-Falle".
     */
    public const PRICE_BASES = ['person', 'pauschal', 'staffel'];

    protected $casts = [
        'uuid' => 'string',
        'position' => 'integer',
        'level' => 'integer',
        'visible' => 'boolean',
        'variant_group_id' => 'integer',
        'presentation_id' => 'integer',
        'quantity' => 'decimal:3',
        'price_value' => 'decimal:2',
        'payload_json' => 'array',
    ];

    public function chapter(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistFoodbookKapitel::class, 'chapter_id');
    }

    /** @deprecated #486 deutscher Alias → chapter() */
    public function kapitel(): BelongsTo
    {
        return $this->chapter();
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

    /** @deprecated #486 deutscher Alias → dish() */
    public function gericht(): BelongsTo
    {
        return $this->dish();
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistVocabEinheit::class, 'unit_vocab_id');
    }

    /**
     * recipe_ref: expliziter Darreichungs-Override (Spec 19, M5/E7.1). Oberste Stufe der
     * Auflösung in {@see DarreichungResolver::fuerBlock()}; NULL ⇒ Servierform-/Standard-Fallback.
     * Loser Zeiger (unsignedBigInteger, keine harte FK — vgl. slot/paket_gericht.presentation_id).
     */
    public function presentation(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistRecipeDarreichung::class, 'presentation_id');
    }

    /** Staffelpreise (nur bei preis_basis='staffel'). */
    public function staffel(): HasMany
    {
        return $this->hasMany(FoodAlchemistFoodbookBlockStaffel::class, 'block_id')->orderBy('min_persons');
    }
}
