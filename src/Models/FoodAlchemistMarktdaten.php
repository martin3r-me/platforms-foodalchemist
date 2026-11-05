<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;

class FoodAlchemistMarktdaten extends Model
{
    use BelongsToTeamHierarchy, LogsActivity;

    protected $table = 'foodalchemist_marktdaten';

    protected $guarded = ['id'];

    protected $casts = ['punkte' => 'array', 'abgerufen_at' => 'datetime', 'versucht_at' => 'datetime'];
}
