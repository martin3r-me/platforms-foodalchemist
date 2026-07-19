<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/** R6.11 · S3 (E4) — FA-Lab-Journal-Notiz (Hypothese/Widerspruch/Idee), team-eigen. */
class FoodAlchemistLabNote extends Model
{
    use BelongsToTeamHierarchy, HasUuidV7, LogsActivity, SoftDeletes;

    protected $table = 'foodalchemist_lab_notes';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'author_id' => 'integer',
    ];
}
