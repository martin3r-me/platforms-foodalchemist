<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;

class FoodAlchemistWettbewerber extends Model
{
    use BelongsToTeamHierarchy, LogsActivity;

    protected $table = 'foodalchemist_wettbewerber';

    protected $guarded = ['id'];

    protected $casts = ['aliases' => 'array', 'suchbegriffe' => 'array', 'ausschliessen' => 'array', 'branchen' => 'array',
        'quellen_ids' => 'array', 'aktiv' => 'boolean', 'rss_aktiv' => 'boolean'];
}
