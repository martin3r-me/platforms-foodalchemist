<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Platform\ActivityLog\Traits\LogsActivity;

/** Spec 82 §13 I: gebuchtes Paket eines Mandanten (z. B. „grundeinstellungen"). Subjekt des Plattform-Protokolls. */
class FoodAlchemistTeamPaket extends Model
{
    use LogsActivity;

    public const GRUNDEINSTELLUNGEN = 'grundeinstellungen';

    protected $table = 'foodalchemist_team_pakete';

    protected $guarded = ['id'];

    protected $casts = ['gebucht_am' => 'datetime'];
}
