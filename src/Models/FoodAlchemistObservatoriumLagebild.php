<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/** Wöchentliches Lagebild des Observatoriums (Master schreibt, alle Mandanten lesen); Aussagen mit Belegen (Eingangs-IDs). */
class FoodAlchemistObservatoriumLagebild extends Model
{
    use BelongsToTeamHierarchy, HasUuidV7, LogsActivity, SoftDeletes;

    protected $table = 'foodalchemist_observatorium_lagebild';

    protected $guarded = ['id'];

    protected $casts = ['uuid' => 'string', 'aussagen' => 'array', 'impulse' => 'array', 'zeitraum_von' => 'date', 'zeitraum_bis' => 'date'];
}
