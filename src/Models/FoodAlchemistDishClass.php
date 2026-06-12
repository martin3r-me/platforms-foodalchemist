<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Speisen-Klasse (M6-01, D-6 §2.2) — HG × Diätform (49),
 * VK-Taxonomie getrennt von der Produktions-Taxonomie (recipe_categories).
 */
class FoodAlchemistDishClass extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_dish_classes';

    protected $guarded = ['id'];

    public function hauptgruppe()
    {
        return $this->belongsTo(FoodAlchemistDishMainGroup::class, 'dish_main_group_id');
    }

    public function defaultMarkupClass()
    {
        return $this->belongsTo(FoodAlchemistMarkupClass::class, 'default_markup_class_id');
    }
}
