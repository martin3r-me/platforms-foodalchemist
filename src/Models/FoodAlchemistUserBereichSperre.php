<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Spec 77b — Bereich, der für einen User im Team abgeschaltet ist (Team-Admin; nur einschränken).
 */
class FoodAlchemistUserBereichSperre extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_user_bereich_sperren';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        
    ];
}
