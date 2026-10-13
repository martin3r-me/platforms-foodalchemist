<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Übernahme einer neueren Master-Fassung in ein eingefrorenes Angebot (Spec 82 §12 B3): von/nach Fassung, bestätigter Allergen-Diff,
 * Person und Zeitpunkt der Bestätigung, betroffene Ausgaben. Historie, wird nie geändert.
 */
class FoodAlchemistAngebotUebernahme extends Model
{
    use BelongsToTeamHierarchy, HasUuidV7, LogsActivity, SoftDeletes;

    protected $table = 'foodalchemist_angebot_uebernahmen';

    protected $guarded = ['id'];

    protected $casts = ['uuid' => 'string', 'allergen_geaendert' => 'boolean', 'allergen_bestaetigt' => 'boolean', 'bestaetigt_at' => 'datetime',
        'diff' => 'array', 'betroffene_ausgaben' => 'array', 'umgehaengt' => 'array'];
}
