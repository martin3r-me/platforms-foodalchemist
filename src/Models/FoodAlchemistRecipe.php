<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Enums\RecipeStatus;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Rezept (D-5 Basisrezept / D-6 Verkaufsrezept — EIN Modell, zwei
 * Service-Sichten über is_sales_recipe). Aggregat-Spalten (Allergene GL-01,
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
        'is_sales_recipe' => 'boolean',
        'is_template' => 'boolean',
        'is_split_result' => 'boolean',
        'is_user_stub' => 'boolean',
        'yield_kg' => 'decimal:3',
        'yield_kg_manual' => 'decimal:3',
        'ertrag_stueck' => 'decimal:2',   // Basisrezept-Ertrag in Stück (kg↔Stück)
        'ek_total_eur' => 'decimal:4',
        'ek_per_kg_eur' => 'decimal:4',
        'nebenkosten_eur' => 'decimal:4',
        'n_ingredients_total' => 'integer',
        'n_ingredients_ungemappt' => 'integer',
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
        return $q->where('is_sales_recipe', false);
    }

    public function scopeVerkauf(Builder $q): Builder
    {
        return $q->where('is_sales_recipe', true);
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

    // ── M6-03: Verkaufslayer (D-6) ───────────────────────────────────────

    public function speisenKlasse(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistDishClass::class, 'dish_class_id');
    }

    public function aufschlagsklasse(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistMarkupClass::class, 'markup_class_id');
    }

    public function vkEinheit(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistVocabEinheit::class, 'vk_unit_vocab_id');
    }

    /** Darreichungs-Varianten des Gerichts (Umbau-Spec Phase 3). */
    public function darreichungen(): HasMany
    {
        return $this->hasMany(FoodAlchemistRecipeDarreichung::class, 'recipe_id');
    }

    /** Die Standard-Darreichung (genau eine pro Gericht; Preis-Wahrheit). */
    public function standardDarreichung(): HasOne
    {
        return $this->hasOne(FoodAlchemistRecipeDarreichung::class, 'recipe_id')
            ->where('ist_standard', true);
    }

    public function customerNames(): HasMany
    {
        return $this->hasMany(FoodAlchemistRecipeCustomerName::class, 'recipe_id');
    }

    public function regenerations(): HasMany
    {
        return $this->hasMany(FoodAlchemistRecipeRegeneration::class, 'recipe_id')->orderBy('sort_order');
    }
}
