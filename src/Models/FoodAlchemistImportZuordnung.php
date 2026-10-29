<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * Gemerkte Spaltenzuordnung eines Lieferanten für den Datei-Import (Preisliste | Stammdaten). Strikt team-eigen (team_id + supplier_id + art eindeutig).
 */
class FoodAlchemistImportZuordnung extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_import_zuordnungen';

    protected $guarded = ['id'];

    protected $casts = ['zuordnung' => 'array', 'kopf' => 'array', 'zuletzt_benutzt_at' => 'datetime'];
}
