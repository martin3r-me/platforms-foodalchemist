<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * Mögliche Teller-Rolle eines Basisrezepts (Dominique 11.10.) — damit es gefunden wird und die Gerichtszeile beim
 * Einsetzen die passende Rolle bekommt. `position` 0 = Hauptrolle; `source` ki | kaskade | vorschlag | manuell.
 */
class FoodAlchemistRecipeRolle extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    public const SOURCES = ['ki', 'kaskade', 'vorschlag', 'manuell'];

    protected $table = 'foodalchemist_recipe_rollen';

    protected $guarded = ['id'];

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistRecipe::class, 'recipe_id');
    }
}
