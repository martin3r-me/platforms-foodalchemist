<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Projekt = Agentur-Akte im Master-Team zu einer CRM-Firma (Spec 82 §9): Kopf (Phase, Status, Ziel, Pilot-Standort,
 * Planner-Verweis), Projekt-Canvas (Phasen) und Kunden-Standorte. Der Mandant entsteht erst bei der Lizenz; `mandant_team_id` hält die Übernahme fest.
 */
class FoodAlchemistProjekt extends Model
{
    use BelongsToTeamHierarchy, HasUuidV7, LogsActivity, SoftDeletes;

    /** Phasen in Reihenfolge (Reiter des Projekt-Details). */
    public const PHASEN = [
        'zielbild' => 'Zielbild & Umfang',
        'standort' => 'Standortprofil',
        'dna' => 'DNA & Handschrift',
        'paketlogik' => 'Paketlogik',
        'foodbook' => 'Foodbook',
        'uebergabe' => 'Übergabe & Pflege',
    ];

    public const STATUS = ['aktiv', 'pausiert', 'abgeschlossen'];

    protected $table = 'foodalchemist_projekte';

    protected $guarded = ['id'];

    protected $casts = ['uuid' => 'string', 'struktur_freigegeben_at' => 'datetime'];

    /** Hartes Gate (Spec 82 §9): Struktur + Musterseite freigegeben? Vorher entsteht kein Foodbook aus der Akte. */
    public function strukturFreigegeben(): bool
    {
        return $this->struktur_freigegeben_at !== null;
    }

    public function standorte(): HasMany
    {
        return $this->hasMany(FoodAlchemistProjektStandort::class, 'projekt_id')->orderBy('sort_order')->orderBy('id');
    }
}
