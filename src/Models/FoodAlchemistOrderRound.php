<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

class FoodAlchemistOrderRound extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_order_rounds';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'desired_delivery_date' => 'date',
    ];

    public function orders(): BelongsToMany
    {
        return $this->belongsToMany(
            FoodAlchemistOrder::class,
            'foodalchemist_order_round_links',
            'round_id',
            'order_id'
        )->withTimestamps();
    }
}
