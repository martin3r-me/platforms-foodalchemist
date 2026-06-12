<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Step-by-Step-Foto (R6): an die Zubereitung gekoppelt über
 * schritt_nr (1-basiert, 0 = allgemein); Datei auf dem public-Disk.
 */
class FoodAlchemistRecipeStepPhoto extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_recipe_step_photos';

    protected $guarded = ['id'];

    protected $casts = ['schritt_nr' => 'integer'];

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistRecipe::class, 'recipe_id');
    }

    public function url(): string
    {
        return Storage::disk('public')->url($this->pfad);
    }
}
