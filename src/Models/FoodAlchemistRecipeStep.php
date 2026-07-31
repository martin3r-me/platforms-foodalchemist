<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Ein Zubereitungs-Schritt (Spec 27 „Step-by-Step-Zubereitung", Phase 1).
 * Master der Zubereitung: Nummer = `position` (1-basiert, entsteht aus der Reihenfolge),
 * Abschnitts-Überschrift = `phase` (ersetzt `##` im alten Markdown), Anweisung = `text`.
 * Fotos hängen many-to-many am Schritt-Datensatz (Pivot `..._recipe_step_photo_links`),
 * nicht mehr an einer getippten Nummer.
 *
 * Schreibweg ist AUSSCHLIESSLICH der RecipeStepService — er hält nach jedem Schreiben
 * `recipes.preparation` als gerenderten Lese-Spiegel nach (EINBAHN Schritte → Markdown).
 */
class FoodAlchemistRecipeStep extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_recipe_steps';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'position' => 'integer',
    ];

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistRecipe::class, 'recipe_id');
    }

    /**
     * Fotos dieses Schritts. M:N — ein Foto darf an mehreren Schritten hängen
     * (z.B. dasselbe Mise-en-Place-Bild bei Vorbereitung und Anrichten).
     */
    public function photos(): BelongsToMany
    {
        return $this->belongsToMany(
            FoodAlchemistRecipeStepPhoto::class,
            'foodalchemist_recipe_step_photo_links',
            'step_id',
            'photo_id'
        )->withPivot('position')->withTimestamps()
            ->orderBy('foodalchemist_recipe_step_photo_links.position');
    }
}
