<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Spec 77c — Zuordnung Unter-Team (Standort) → Betrieb des Oberteams (Kalkulationsprofil).
 */
class FoodAlchemistTeamBetrieb extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_team_betriebe';

    protected $guarded = ['id'];

    protected $casts = ['uuid' => 'string'];
}
