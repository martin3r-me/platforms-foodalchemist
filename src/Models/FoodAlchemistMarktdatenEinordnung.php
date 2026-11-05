<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/** KI-Einordnung der Marktdaten je Team und Werte-Stand; nur Sätze, deren Zahlen in den abgerufenen Werten stehen. */
class FoodAlchemistMarktdatenEinordnung extends Model
{
    use BelongsToTeamHierarchy, HasUuidV7, LogsActivity, SoftDeletes;

    protected $table = 'foodalchemist_marktdaten_einordnung';

    protected $guarded = ['id'];

    protected $casts = ['uuid' => 'string', 'saetze' => 'array'];
}
