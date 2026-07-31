<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Foto im Media-Pool eines Rezepts (R6); Datei auf dem public-Disk.
 * Die Kopplung an die Zubereitung läuft seit Spec 27 über den M:N-Pivot
 * `foodalchemist_recipe_step_photo_links` → steps(). Ein Foto OHNE Pivot-Eintrag
 * ist ein allgemeines Rezept-Foto (Hero/Ergebnis).
 */
class FoodAlchemistRecipeStepPhoto extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_recipe_step_photos';

    protected $guarded = ['id'];

    /** @deprecated Spec 27 — `schritt_nr` ist die alte Zahlen-Kopplung, wird nicht mehr geschrieben. */
    protected $casts = ['schritt_nr' => 'integer'];

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistRecipe::class, 'recipe_id');
    }

    /** Schritte, an denen dieses Foto hängt (Spec 27, M:N — 0..n). */
    public function steps(): BelongsToMany
    {
        return $this->belongsToMany(
            FoodAlchemistRecipeStep::class,
            'foodalchemist_recipe_step_photo_links',
            'photo_id',
            'step_id'
        )->withPivot('position')->withTimestamps();
    }

    public function url(): string
    {
        return Storage::disk('public')->url($this->pfad);
    }
}
