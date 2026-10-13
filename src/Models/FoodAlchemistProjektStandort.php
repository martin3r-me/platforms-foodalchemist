<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Kunden-Standort eines Projekts (Agentur-Akte im Master-Team), noch OHNE Mandanten-Team: Träger des Standortprofils
 * (Spec 82 §9, Variante B). Bei der Lizenz-Übernahme wird daraus ein Unter-Team des Mandanten mit kopiertem Teamprofil.
 */
class FoodAlchemistProjektStandort extends Model
{
    use BelongsToTeamHierarchy, HasUuidV7, LogsActivity, SoftDeletes;

    protected $table = 'foodalchemist_projekt_standorte';

    protected $guarded = ['id'];

    protected $casts = ['uuid' => 'string', 'ist_pilot' => 'boolean', 'sort_order' => 'integer'];

    public function projekt(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistProjekt::class, 'projekt_id');
    }
}
