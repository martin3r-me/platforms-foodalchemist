<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Verwendungsnachweis (M6-01, D-6 §2.2): Kunde × Marketing-Name
 * pro VK-Rezept — team-eigen; MVP-Vorgriff auf den D-8-Foodbook-Anschluss.
 */
class FoodAlchemistRecipeCustomerName extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_recipe_customer_names';

    protected $guarded = ['id'];
}
