<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * Eigene Schrift eines Teams für Präsentations-Designs (eine Datei = ein Schnitt: Familie × Stärke × Stil).
 * Sichtbar in Kind-Teams (Ancestry), schreibbar nur im eigenen Team. Referenz im Design:
 * tokens_json.typography.heading/body = "team:<Familie>".
 */
class FoodAlchemistPresentationFont extends Model
{
    use BelongsToTeamHierarchy, HasUuidV7, LogsActivity, SoftDeletes;

    protected $table = 'foodalchemist_presentation_fonts';

    protected $guarded = ['id'];

    protected $casts = ['uuid' => 'string', 'weight' => 'integer'];
}
