<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Spec 61 — FA-Rolle eines Mitglieds in einem Team. Kein Eintrag = Lesen;
 * Inhaber/Admins sind immer FA-Admin (berechnet in FaRechte, nicht hier gespeichert).
 */
class FoodAlchemistTeamMemberRole extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_team_member_roles';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'rolle' => FaRolle::class,
    ];
}
