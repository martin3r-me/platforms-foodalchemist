<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description VK-Hauptgruppe (M6-01, D-6 §2.2) — 16 Codes APE…GET; der Code
 * ist Präfix des Pipe-Namings (D-6 §4.4).
 */
class FoodAlchemistDishMainGroup extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_dish_main_groups';

    protected $guarded = ['id'];

    public function dishClasses()
    {
        return $this->hasMany(FoodAlchemistDishClass::class, 'dish_main_group_id');
    }

    /** @deprecated #486 deutscher Alias → dishClasses() */
    public function klassen()
    {
        return $this->hasMany(FoodAlchemistDishClass::class, 'dish_main_group_id');
    }
}
