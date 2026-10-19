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
        return $this->belongsTo(FoodAlchemistConcept::class, 'concept_id')
            ->withoutGlobalScope(\Platform\FoodAlchemist\Models\Scopes\OhneFassungenScope::class)->ohneTeamSicht('verweis');   // Spec 82: Verweis löst auch Fassungen auf
    }

    /** Befüllung A: austauschbares Paket. */
    public function package(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistPaket::class, 'package_id')
            ->withoutGlobalScope(\Platform\FoodAlchemist\Models\Scopes\OhneFassungenScope::class)->ohneTeamSicht('verweis');   // Spec 82: Verweis löst auch Fassungen auf
    }

    /** @deprecated #486 deutscher Alias → package() */
    public function paket(): BelongsTo
    {
        return $this->package();
    }

    /** Kaskade (2026-08-24): eingebettetes Paket als kind=paket-Concept (ersetzt schrittweise package_id). */
    public function embeddedConcept(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistConcept::class, 'embedded_concept_id')
            ->withoutGlobalScope(\Platform\FoodAlchemist\Models\Scopes\OhneFassungenScope::class)->ohneTeamSicht('verweis');   // Spec 82: Verweis löst auch Fassungen auf
    }

    /** Befüllung B: fest gesetztes Gericht (VK-Rezept). */
    public function dish(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistRecipe::class, 'sales_recipe_id')
            ->withoutGlobalScope(\Platform\FoodAlchemist\Models\Scopes\OhneFassungenScope::class)->ohneTeamSicht('verweis');   // Spec 82: Verweis löst auch Fassungen auf
    }

    /** @deprecated #486 deutscher Alias → dish() */
    public function gericht(): BelongsTo
    {
        return $this->dish();
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistVocabEinheit::class, 'unit_vocab_id')->ohneTeamSicht('verweis');
    }

    /** Optional: explizit gewählte Darreichung des Gerichts (Umbau-Spec Phase 3). */
    public function presentation(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistRecipeDarreichung::class, 'presentation_id');
    }

    /** @deprecated #486 deutscher Alias → presentation() */
    public function darreichung(): BelongsTo
    {
        return $this->presentation();
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
