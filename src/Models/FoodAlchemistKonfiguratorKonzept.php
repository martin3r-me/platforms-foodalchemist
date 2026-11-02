<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Spec 94 · Eintrag im Konfigurator: Hauptkonzept (Lunch- und/oder Dinner-Concept aus dem Concepter) oder
 * Add-on (ein Concept, Abschnitt, Bindung, Tageszeit). Concepts werden verknüpft, nicht kopiert.
 */
class FoodAlchemistKonfiguratorKonzept extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    public const ROLLEN = ['haupt', 'addon'];

    public const ADDON_TAGESZEITEN = ['lunch', 'dinner', 'beide'];

    protected $table = 'foodalchemist_konfigurator_konzepte';

    protected $guarded = ['id'];

    protected $casts = [
        'indikativ_lunch' => 'boolean',
        'indikativ_dinner' => 'boolean',
        'position' => 'integer',
    ];

    public function konfigurator(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistKonfigurator::class, 'konfigurator_id');
    }

    public function lunchConcept(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistConcept::class, 'lunch_concept_id');
    }

    public function dinnerConcept(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistConcept::class, 'dinner_concept_id');
    }

    public function istHaupt(): bool
    {
        return $this->rolle === 'haupt';
    }
}
