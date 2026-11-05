<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;

/** KI-Einordnung der Marktdaten je Team und Werte-Stand; nur Sätze, deren Zahlen in den abgerufenen Werten stehen. */
class FoodAlchemistMarktdatenEinordnung extends Model
{
    use BelongsToTeamHierarchy, LogsActivity;

    protected $table = 'foodalchemist_marktdaten_einordnung';

    protected $guarded = ['id'];

    protected $casts = ['saetze' => 'array'];
}
