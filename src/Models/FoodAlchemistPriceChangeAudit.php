<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

class FoodAlchemistPriceChangeAudit extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_price_change_audits';

    protected $guarded = ['id'];

    protected $casts = [
        'old_calculated_net' => 'decimal:2',
        'new_calculated_net' => 'decimal:2',
        'old_effective_net' => 'decimal:2',
        'new_effective_net' => 'decimal:2',
        'metadata' => 'array',
    ];
}
