<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Platform\ActivityLog\Traits\LogsActivity;

/**
 * Spec 82 §13 B4 — plattformweite FA-Einstellung (Schlüssel/Wert), bewusst ohne team_id. Gelesen über
 * {@see \Platform\FoodAlchemist\Support\MasterTeam}, geschrieben nur über MasterTeamService (Plattform-Admin).
 */
class FoodAlchemistPlattformEinstellung extends Model
{
    use LogsActivity;   // Spec 82 §13 C4: Plattform-Protokoll hängt an diesem Modell (PlattformProtokoll)

    public const MASTER_TEAM = 'master_team_id';

    protected $table = 'foodalchemist_plattform_einstellungen';

    protected $guarded = ['id'];
}
