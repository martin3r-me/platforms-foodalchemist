<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * #507 Weg-2 · E7-b: eine Alias-Gruppe (Satz bedeutungsgleicher Phrasen) als
 * runtime-pflegbarer, additiver Zusatz zum Konstanten-Baseline des TerminologyService.
 */
class FoodAlchemistTerminologyAlias extends Model
{
    use BelongsToTeamHierarchy, HasUuidV7, LogsActivity, SoftDeletes;

    protected $table = 'foodalchemist_terminology_aliases';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'members' => 'array',
    ];
}
