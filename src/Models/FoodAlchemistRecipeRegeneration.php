<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description V-19 (M6-01, D-6 §2.3): Multi-Komponenten-Regeneration —
 * ein Programm je Komponente (Label, Gerät, °C, min, Kerntemp), zeilenbasierte
 * KI-Lineage. UI/Services lesen NUR diese Tabelle, nie die Alt-Skalarspalten.
 */
class FoodAlchemistRecipeRegeneration extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_recipe_regenerations';

    protected $guarded = ['id'];
}
