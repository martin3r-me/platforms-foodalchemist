<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Concept (M10-01) — die verkäufliche Komposition über mehrere
 * Rollen-Slots (z. B. „Grill-Buffet" = Vorspeise + Hauptgang + Dessert). Jeder
 * Slot ist mit einem Baustein oder einem festen Gericht gefüllt; der Concept-Preis
 * = Σ der Slot-Preise. `is_vorlage` markiert ein gespeichertes Slot-Gerüst
 * (Vorlage = Kopie-Quelle, Fork beim Start — D-CON-7). team-eigen.
 */
class FoodAlchemistConcept extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_concepts';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'is_vorlage' => 'boolean',
        'personen' => 'integer',
        'preis_pro_person_cache' => 'decimal:2',
    ];

    public function scopeVorlagen(Builder $q): Builder
    {
        return $q->where('is_vorlage', true);
    }

    public function scopeEchte(Builder $q): Builder
    {
        return $q->where('is_vorlage', false);
    }

    public function slots(): HasMany
    {
        return $this->hasMany(FoodAlchemistConceptSlot::class, 'concept_id')->orderBy('position');
    }

    /** Vorlage, aus der dieses Concept geforkt wurde (Lineage, optional). */
    public function vorlageQuelle(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistConcept::class, 'vorlage_quelle_id');
    }
}
