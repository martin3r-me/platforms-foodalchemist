<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Spec 61/75 — FA-Zusatzrecht eines Mitglieds: „darf Rechnungen freigeben".
 * Die Rolle selbst (Inhaber/Admin/Mitglied/Betrachter) kommt aus den Team-Einstellungen der Plattform.
 */
class FoodAlchemistTeamMemberFlag extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_team_member_flags';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'can_approve_invoices' => 'boolean',
    ];
}
