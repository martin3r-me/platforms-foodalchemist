<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Spec 77b — Kontingente je Team: max. Standorte (Unter-Teams), max. User, KI-Budget € je Monat (NULL = unbegrenzt).
 */
class FoodAlchemistTeamKontingent extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_team_kontingente';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'ki_budget_eur_monat' => 'decimal:2', 'max_standorte' => 'integer', 'max_user' => 'integer',
    ];
}
