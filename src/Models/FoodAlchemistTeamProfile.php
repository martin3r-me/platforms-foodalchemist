<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * Spec 82 §6 — Teamprofil (eine Zeile je Team): Fakten über die Küche, strikt team-eigen, nicht vererbt (Lesart wie BulkRun).
 */
class FoodAlchemistTeamProfile extends Model
{
    use HasUuidV7, LogsActivity, SoftDeletes;

    protected $table = 'foodalchemist_team_profiles';

    protected $guarded = ['id'];

    protected $casts = ['max_pax_parallel' => 'integer'];
}
