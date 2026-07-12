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
        'yield_pieces' => 'decimal:2',   // Basisrezept-Ertrag in Stück (kg↔Stück)
        'ek_total_eur' => 'decimal:4',
        'ek_per_kg_eur' => 'decimal:4',
        'additional_costs_eur' => 'decimal:4',
        'n_ingredients_total' => 'integer',
        'n_ingredients_unmapped' => 'integer',
        'ai_confidence' => 'decimal:3',
        'allergens_aggregated_at' => 'datetime',
        'additive_aggregated_at' => 'datetime',
        'nutri_aggregated_at' => 'datetime',
        'spec_aggregated_at' => 'datetime',
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

    public function category(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistRecipeCategory::class, 'category_id');
    }

    /** @deprecated #486 deutscher Alias → category() */
    public function kategorie(): BelongsTo
    {
        return $this->category();
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

    public function levelSuitabilities(): HasMany
    {
        return $this->hasMany(FoodAlchemistRecipeNiveauEignung::class, 'recipe_id');
    }

    /** @deprecated #486 deutscher Alias → levelSuitabilities() */
    public function niveauEignungen(): HasMany
    {
        return $this->levelSuitabilities();
    }

    public function sectorSuitabilities(): HasMany
    {
        return $this->hasMany(FoodAlchemistRecipeSektorEignung::class, 'recipe_id');
    }

    /** @deprecated #486 deutscher Alias → sectorSuitabilities() */
    public function sektorEignungen(): HasMany
    {
        return $this->sectorSuitabilities();
    }

    // ── M6-03: Verkaufslayer (D-6) ───────────────────────────────────────

    public function dishClass(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistDishClass::class, 'dish_class_id');
    }

    /** @deprecated #486 deutscher Alias → dishClass() */
    public function speisenKlasse(): BelongsTo
    {
        return $this->dishClass();
    }

    /**
     * VK-Taxonomie Modell A (Regelwerk_Verkaufsgerichte v1.1): Die Hauptgruppe ist die
     * Kategorie und wird direkt am Rezept geführt (Klasse = nur noch Diätform).
     */
    public function dishMainGroup(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistDishMainGroup::class, 'dish_main_group_id');
    }

    /** @deprecated #486 deutscher Alias → dishMainGroup() */
    public function speisenHauptgruppe(): BelongsTo
    {
        return $this->dishMainGroup();
    }

    public function markupClass(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistMarkupClass::class, 'markup_class_id');
    }

    /** @deprecated #486 deutscher Alias → markupClass() */
    public function aufschlagsklasse(): BelongsTo
    {
        return $this->markupClass();
    }

    public function salesUnit(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistVocabEinheit::class, 'sales_unit_vocab_id');
    }

    /** @deprecated #486 deutscher Alias → salesUnit() */
    public function vkEinheit(): BelongsTo
    {
        return $this->salesUnit();
    }

    /** Darreichungs-Varianten des Gerichts (Umbau-Spec Phase 3). */
    public function presentations(): HasMany
    {
        return $this->hasMany(FoodAlchemistRecipeDarreichung::class, 'recipe_id');
    }

    /** @deprecated #486 deutscher Alias → presentations() */
    public function darreichungen(): HasMany
    {
        return $this->presentations();
    }

    /** Die Standard-Darreichung (genau eine pro Gericht; Preis-Wahrheit). */
    public function standardPresentation(): HasOne
    {
        return $this->hasOne(FoodAlchemistRecipeDarreichung::class, 'recipe_id')
            ->where('is_standard', true);
    }

    /** @deprecated #486 deutscher Alias → standardPresentation() */
    public function standardDarreichung(): HasOne
    {
        return $this->standardPresentation();
    }

    public function customerNames(): HasMany
    {
        return $this->hasMany(FoodAlchemistRecipeCustomerName::class, 'recipe_id');
    }

    public function regenerations(): HasMany
    {
        return $this->hasMany(FoodAlchemistRecipeRegeneration::class, 'recipe_id')->orderBy('sort_order');
    }

    /** R2.6: Praxis-Feedback (Küche/Kunde/Event), neueste zuerst. */
    public function feedbacks(): HasMany
    {
        return $this->hasMany(FoodAlchemistRecipeFeedback::class, 'recipe_id')->latest();
    }
}
