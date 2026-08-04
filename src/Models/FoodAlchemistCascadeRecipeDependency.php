<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;

class FoodAlchemistCascadeRecipeDependency extends Model
{
    protected $table = 'foodalchemist_cascade_recipe_dependencies';

    protected $guarded = ['id'];
}
