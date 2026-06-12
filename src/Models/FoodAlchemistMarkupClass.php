<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Aufschlagsklasse (M6-01, D-6 §2.2) — VK-Kalkulationsbasis;
 * formel_typ 'deckungsbeitrag' ist anlegbar, rechnet aber nicht (W-1-Guard im MargeService).
 */
class FoodAlchemistMarkupClass extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_markup_classes';

    protected $guarded = ['id'];
}
