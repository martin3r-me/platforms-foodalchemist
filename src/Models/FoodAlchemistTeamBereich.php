<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Spec 77b — FA-Bereich je Team (Plattform-Admin schaltet ab; keine Zeile = an).
 */
class FoodAlchemistTeamBereich extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_team_bereiche';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'aktiv' => 'boolean',
    ];
}
