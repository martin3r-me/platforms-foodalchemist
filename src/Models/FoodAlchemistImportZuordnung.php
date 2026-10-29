<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * Gemerkte Spaltenzuordnung eines Lieferanten für den Datei-Import (Preisliste | Stammdaten). Strikt team-eigen (team_id + supplier_id + art eindeutig).
 */
class FoodAlchemistImportZuordnung extends Model
{
    use HasUuidV7;

    protected $table = 'foodalchemist_import_zuordnungen';

    protected $guarded = ['id'];

    protected $casts = ['zuordnung' => 'array', 'kopf' => 'array', 'zuletzt_benutzt_at' => 'datetime'];
}
