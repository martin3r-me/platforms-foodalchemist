<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Speisekarten-Position — polymorphe Zeile einer Rubrik, diskriminiert
 * über `type` ∈ {gericht_ref, menue_ref, header, text, spacer, image}.
 *  - gericht_ref referenziert ein einzelnes VK-Gericht (oder Getränk, WG 15).
 *  - menue_ref referenziert ein Concept (Fix-Menü / Mehrgänger).
 * Darreichungs-Override (Glas/Flasche/Portion) über `presentation_id`.
 */
class FoodAlchemistSpeisekartePosition extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_menu_card_items';

    protected $guarded = ['id'];

    /** Positions-Typen. */
    public const TYPES = ['gericht_ref', 'menue_ref', 'header', 'text', 'spacer', 'image'];

    /** Positions-Typen mit einem referenzierten Inhalt (Gericht bzw. Concept). */
    public const REF_TYPES = ['gericht_ref', 'menue_ref'];

    protected $casts = [
        'uuid' => 'string',
        'position' => 'integer',
        'level' => 'integer',
        'visible' => 'boolean',
        'variant_group_id' => 'integer',
        'presentation_id' => 'integer',
        'price_value' => 'decimal:2',
        'payload_json' => 'array',
    ];

    public function section(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistSpeisekarteRubrik::class, 'section_id');
    }

    /** Deutscher Alias → section(). */
    public function rubrik(): BelongsTo
    {
        return $this->section();
    }

    /** gericht_ref: einzelnes Gericht/Getränk (VK-Rezept). */
    public function dish(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistRecipe::class, 'sales_recipe_id');
    }

    /** Deutscher Alias → dish(). */
    public function gericht(): BelongsTo
    {
        return $this->dish();
    }

    /** menue_ref: das referenzierte Concept (Fix-Menü, live). */
    public function concept(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistConcept::class, 'concept_id');
    }

    /**
     * Expliziter Darreichungs-Override (Glas/Flasche/Portion). Oberste Stufe der
     * Preis-Auflösung in {@see DarreichungResolver::fuerBlock()}; loser Zeiger.
     */
    public function presentation(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistRecipeDarreichung::class, 'presentation_id');
    }
}
