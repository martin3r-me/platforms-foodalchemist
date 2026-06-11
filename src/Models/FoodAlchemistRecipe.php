<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Enums\RecipeStatus;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Rezept (D-5 Basisrezept / D-6 Verkaufsrezept — EIN Modell, zwei
 * Service-Sichten über ist_verkaufsrezept). Aggregat-Spalten (Allergene GL-01,
 * Zusatzstoffe GL-09, Kosten/Yield GL-02, Nährwerte GL-08, Spec-Flags) schreibt
 * NUR der RecipeRecomputeService. team_id NOT NULL (⚠D1: immer team-eigen).
 */
class FoodAlchemistRecipe extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_recipes';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'status' => RecipeStatus::class,
        'ist_verkaufsrezept' => 'boolean',
        'is_template' => 'boolean',
        'is_split_result' => 'boolean',
        'is_user_stub' => 'boolean',
        'yield_kg' => 'decimal:3',
        'yield_kg_manual' => 'decimal:3',
        'ek_total_eur' => 'decimal:4',
        'ek_per_kg_eur' => 'decimal:4',
        'n_zutaten_total' => 'integer',
        'n_zutaten_ungemappt' => 'integer',
        'ai_confidence' => 'decimal:3',
        'allergene_aggregiert_am' => 'datetime',
        'zusatz_aggregiert_am' => 'datetime',
        'nutri_aggregiert_am' => 'datetime',
        'spec_aggregiert_am' => 'datetime',
        'spec_is_vegan' => 'boolean',
        'spec_is_vegetarian' => 'boolean',
        'spec_is_halal' => 'boolean',
        'spec_contains_pork' => 'boolean',
        'spec_contains_beef' => 'boolean',
        'spec_is_gluten_free' => 'boolean',
        'spec_is_lactose_free' => 'boolean',
        'context_hooks_json' => 'array',
    ];

    // ── D-5/D-6-Sichten (Services erzwingen ihren Scope in JEDER Query) ──

    public function scopeBasis(Builder $q): Builder
    {
        return $q->where('ist_verkaufsrezept', false);
    }

    public function scopeVerkauf(Builder $q): Builder
    {
        return $q->where('ist_verkaufsrezept', true);
    }

    // ── Relationen ───────────────────────────────────────────────────────

    public function ingredients(): HasMany
    {
        return $this->hasMany(FoodAlchemistRecipeIngredient::class, 'recipe_id')->orderBy('position');
    }

    public function kategorie(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistRecipeCategory::class, 'kategorie_id');
    }

    public function equipment(): BelongsToMany
    {
        return $this->belongsToMany(FoodAlchemistVocabKochequipment::class, 'foodalchemist_recipe_equipment', 'recipe_id', 'equipment_id')
            ->withPivot('note');
    }

    /** Eltern-Rezepte = Rezepte, die DIESES als Sub-Rezept referenzieren (↑-Navigation). */
    public function parentIngredients(): HasMany
    {
        return $this->hasMany(FoodAlchemistRecipeIngredient::class, 'referenced_recipe_id');
    }

    public function niveauEignungen(): HasMany
    {
        return $this->hasMany(FoodAlchemistRecipeNiveauEignung::class, 'recipe_id');
    }

    public function sektorEignungen(): HasMany
    {
        return $this->hasMany(FoodAlchemistRecipeSektorEignung::class, 'recipe_id');
    }
}
