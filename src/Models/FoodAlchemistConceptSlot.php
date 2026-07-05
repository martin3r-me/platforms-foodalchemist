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
 * @ai.description Concept-Slot (M10-01) — eine Rolle im Concept, gefüllt mit GENAU
 * EINEM: `package_id` (austauschbares Bündel) ODER `sales_recipe_id` (fest gesetztes
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
        'level' => 'integer',
        'is_pflicht' => 'boolean',
        'quantity' => 'decimal:3',
        'price_value' => 'decimal:2',
    ];

    public function concept(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistConcept::class, 'concept_id');
    }

    /** Befüllung A: austauschbarer Paket. */
    public function paket(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistPaket::class, 'package_id');
    }

    /** Befüllung B: fest gesetztes Gericht (VK-Rezept). */
    public function gericht(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistRecipe::class, 'sales_recipe_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistVocabEinheit::class, 'unit_vocab_id');
    }

    /** Optional: explizit gewählte Darreichung des Gerichts (Umbau-Spec Phase 3). */
    public function darreichung(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistRecipeDarreichung::class, 'presentation_id');
    }

    /** #388: direktes Geschirr je Gericht. */
    public function geschirrItem(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistGeschirrItem::class, 'tableware_item_id');
    }

    /** #388: Alternativ-Geschirr (z. B. anderer Leih-Caterer). */
    public function geschirrAltItem(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistGeschirrItem::class, 'tableware_alt_item_id');
    }

    /** Staffelpreise (nur bei type=header_preis + preis_basis='staffel'). */
    public function staffel(): HasMany
    {
        return $this->hasMany(FoodAlchemistConceptSlotStaffel::class, 'slot_id')->orderBy('min_persons');
    }

    /** True, wenn der Slot durch einen austauschbaren Paket gefüllt ist. */
    public function istPaket(): bool
    {
        return $this->package_id !== null;
    }
}
