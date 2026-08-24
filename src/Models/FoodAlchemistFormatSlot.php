<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Format-Slot (F2) — eine Position im Format-Aufbau. Entweder ein
 * referenziertes Concept (type=concept, concept_id → Edition, in mehreren Formaten
 * nutzbar) oder ein Struktur-Block (header/text/spacer). Spiegelt FoodAlchemistConceptSlot
 * eine Ebene höher, schlanker (keine Dish-/Paket-/Preis-Interna). Position-sortiert.
 */
class FoodAlchemistFormatSlot extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_format_slots';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'position' => 'integer',
    ];

    /** Struktur-Block-Typen (analog ConceptService::STRUKTUR_TYPEN, ohne header_preis). */
    public const STRUKTUR_TYPEN = ['header', 'text', 'spacer'];

    public function format(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistFormat::class, 'format_id');
    }

    /** Referenziertes Concept (Edition) — type=concept. */
    public function concept(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistConcept::class, 'concept_id');
    }
}
