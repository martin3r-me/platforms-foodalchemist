<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Spec 82 §13 B4 — plattformweite FA-Einstellung (Schlüssel/Wert), bewusst ohne team_id. Gelesen über
 * {@see \Platform\FoodAlchemist\Support\MasterTeam}, geschrieben nur über MasterTeamService (Plattform-Admin).
 */
class FoodAlchemistPlattformEinstellung extends Model
{
    public const MASTER_TEAM = 'master_team_id';

    protected $table = 'foodalchemist_plattform_einstellungen';

    protected $guarded = ['id'];
}
