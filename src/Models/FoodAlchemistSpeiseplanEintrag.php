<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Speiseplan-Eintrag (M14) — belegt (woche × wochentag × mahlzeit)
 * mit GENAU EINEM: Concept, Paket oder Gericht (D-PLAN-1). Position-sortiert je Zelle.
 */
class FoodAlchemistSpeiseplanEintrag extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_menu_plan_entries';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'woche' => 'integer',
        'wochentag' => 'integer',
        'position' => 'integer',
        'datum' => 'date',
    ];

    public function speiseplan(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistSpeiseplan::class, 'menu_plan_id');
    }

    public function linie(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistSpeiseplanLinie::class, 'line_id');
    }

    public function concept(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistConcept::class, 'concept_id');
    }

    public function paket(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistPaket::class, 'package_id');
    }

    public function gericht(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistRecipe::class, 'vk_recipe_id');
    }

    /** Identitäts-Schlüssel des Inhalts (für Wiederholungs-Check). */
    public function inhaltKey(): ?string
    {
        return match (true) {
            $this->concept_id !== null => 'c' . $this->concept_id,
            $this->package_id !== null => 'p' . $this->package_id,
            $this->vk_recipe_id !== null => 'g' . $this->vk_recipe_id,
            default => null,
        };
    }

    public function inhaltName(): string
    {
        return $this->concept?->name ?? $this->paket?->name ?? $this->gericht?->name ?? '—';
    }
}
