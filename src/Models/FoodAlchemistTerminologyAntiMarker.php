<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * #507 Weg-2 · E7-b: eine Anti-Marker-Regel (Verwechslungs-Sperre) als runtime-
 * pflegbarer, additiver Zusatz zum Konstanten-Baseline des TerminologyService.
 * „taucht trigger_token in der Query auf, unterdrücke Kandidaten mit forbid_token
 * (es sei denn, der Kandidat trägt unless_token)".
 */
class FoodAlchemistTerminologyAntiMarker extends Model
{
    use BelongsToTeamHierarchy, HasUuidV7, LogsActivity, SoftDeletes;

    protected $table = 'foodalchemist_terminology_anti_markers';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
    ];
}
