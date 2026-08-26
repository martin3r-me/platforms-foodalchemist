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
use Platform\FoodAlchemist\Enums\EkPriceBasis;
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
        // Stufe 3 (Auto-Produktionsplaner)
        'default_station_id' => 'integer',
        'max_vorlauf_tage' => 'integer',
        'setup_time_min' => 'integer',
        'variable_work_time_min' => 'decimal:3',
        'standzeit_min' => 'integer',   // passive Gar-/Standzeit (Durchlaufzeit, bindet keinen Posten)
        'batch_max_kg' => 'decimal:3',
        'batch_max_pieces' => 'decimal:2',
        'ek_total_eur' => 'decimal:4',
        'ek_per_kg_eur' => 'decimal:4',
        'ek_price_basis' => EkPriceBasis::class,   // V-014: woher die EK-Zahl kommt (lead/avg/mixed/unknown)
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

    /**
     * Globaler Default-Topf-Deckel für die Koch-Vorgangs-Rechnung, wenn WEDER Rezept noch Posten
     * einen eigenen Deckel pflegen. Ersetzt die frühere „1 Ertrags-Ansatz = 1 Koch-Vorgang"-Annahme
     * (die z. B. 4,69 kg als zehn 469-g-Töpfe zählte und so die Arbeitszeit ver-10-fachte). Physisch
     * begründet — „wie viel passt in einen großen Gastro-Kessel", KEIN erfundener Zeit-Parameter.
     * Bis zur Promotion auf ein Team-Setting eine erklärbare Konstante.
     */
    public const DEFAULT_BATCH_MAX_KG = 20.0;

    public const DEFAULT_BATCH_MAX_PIECES = 200.0;

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

    /** Zubereitungs-Schritte (Spec 27) — Master, `preparation` ist nur ihr gerenderter Spiegel. */
    public function steps(): HasMany
    {
        return $this->hasMany(FoodAlchemistRecipeStep::class, 'recipe_id')->orderBy('position');
    }

    /** Stufe 3 — Default-Posten des Rezepts (weiche Referenz, team-scoped, kein DB-FK). */
    public function defaultStation(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistProductionStation::class, 'default_station_id');
    }

    /**
     * Stufe 3 — Koch-Vorgänge (Batches) für eine Bedarfsmenge unter dem Topf-Deckel.
     * Deckel = kleinster aus Rezept- und (optional) Posten-Deckel; ohne gepflegten Deckel greift der
     * globale Default-Kessel (DEFAULT_BATCH_MAX_*) statt „1 Ertrags-Ansatz = 1 Koch-Vorgang". Rechnet
     * in kg oder Stück; ohne Yield fällt es auf 1 Vorgang je Ansatz zurück.
     * Wirkt NUR auf die Zeit (arbeitszeitMin) — die Ansätze/Zutatenmengen kommen aus $batches (Explosion).
     */
    /** Stück-Ertrag statt kg: wenn kein kg-Yield, aber ein Stück-Yield gepflegt ist (z. B. Törtchen). */
    public function istStueckErtrag(): bool
    {
        return ($this->yield_kg_manual ?? $this->yield_kg) === null && (float) ($this->yield_pieces ?? 0) > 0;
    }

    public function kochBatches(float $rohBatches, ?float $stationDeckel = null, ?bool $stueck = null, ?float $fallbackDeckel = null): int
    {
        $stueck ??= $this->istStueckErtrag();
        $yield = $stueck
            ? (float) ($this->yield_pieces ?? 0)
            : (float) ($this->yield_kg_manual ?? $this->yield_kg ?? 0);

        // Ohne Yield lässt sich die Menge nicht in Topf-Vorgänge umrechnen: 1 Ansatz = 1 Koch-Vorgang.
        if ($yield <= 0) {
            return (int) max(1, ceil($rohBatches - 1e-9));
        }

        // Harte Deckel (Rezept/Posten) — der kleinere gewinnt.
        $rezeptDeckel = $stueck ? $this->batch_max_pieces : $this->batch_max_kg;
        $deckel = collect([$rezeptDeckel, $stationDeckel])
            ->filter(fn ($v) => $v !== null && (float) $v > 0)
            ->map(fn ($v) => (float) $v)->min();

        // Kein harter Deckel ⇒ Team-Fallback (Warengruppe/Team-Default, vom Service gereicht),
        // sonst der globale physische Default-Kessel (statt „1 Ansatz = 1 Topf").
        if ($deckel === null && $fallbackDeckel !== null && $fallbackDeckel > 0) {
            $deckel = (float) $fallbackDeckel;
        }
        $deckel ??= ($stueck ? self::DEFAULT_BATCH_MAX_PIECES : self::DEFAULT_BATCH_MAX_KG);
        if ($deckel <= 0) {
            return (int) max(1, ceil($rohBatches - 1e-9));
        }

        return (int) max(1, ceil(($rohBatches * $yield) / $deckel - 1e-9));
    }

    /**
     * Stufe 3 — nicht-lineare AKTIVE Belegzeit: Rüstzeit (einmal je Lauf) + Marginal je Koch-Vorgang.
     * Koch-Vorgänge über den Topf-Deckel (Rezept/Posten/globaler Default) — 4,69 kg in einem Kessel
     * sind EIN Vorgang, nicht zehn 469-g-Ansätze. Bindet Posten/Kapazität. Passive Standzeit steckt
     * NICHT hier drin (→ standzeitMin/durchlaufzeitMin). Die Rezeptart entscheidet nicht mehr über
     * lineare oder batchweise Skalierung. `null`, wenn gar keine Zeit hinterlegt ist.
     */
    public function arbeitszeitMin(float $rohBatches, bool $istVk, ?float $stationDeckel = null, ?bool $stueck = null, ?float $fallbackDeckel = null): ?int
    {
        if ($this->work_time_min === null && (int) ($this->setup_time_min ?? 0) === 0
            && (float) ($this->variable_work_time_min ?? 0) <= 0) {
            return null;
        }

        $stueck ??= $this->istStueckErtrag();
        $kochBatches = (float) $this->kochBatches($rohBatches, $stationDeckel, $stueck, $fallbackDeckel);
        $menge = $rohBatches * ($stueck
            ? (float) ($this->yield_pieces ?? 0)
            : (float) ($this->yield_kg_manual ?? $this->yield_kg ?? 0));

        return (int) round(
            (int) ($this->setup_time_min ?? 0)
            + (float) ($this->work_time_min ?? 0) * $kochBatches
            + (float) ($this->variable_work_time_min ?? 0) * $menge
        );
    }

    /**
     * Passive Gar-/Standzeit (Köcheln, Ziehen, Kühlen) — Teil der Durchlaufzeit, bindet aber KEINEN
     * Posten und geht NICHT in die Kapazität. Bewusst mengenunabhängig (1× je Lauf): mehrere Töpfe
     * köcheln unbeaufsichtigt/überlappend, das vervielfacht die Standzeit nicht. `null` = keine.
     */
    public function standzeitMin(): ?int
    {
        $s = (int) ($this->standzeit_min ?? 0);

        return $s > 0 ? $s : null;
    }

    /**
     * Durchlaufzeit = aktive Belegzeit (setup + Marginal × Koch-Vorgänge) + passive Standzeit.
     * „Wann ist es fertig", im Gegensatz zur reinen Posten-Belegzeit. `null` nur, wenn beide fehlen.
     */
    public function durchlaufzeitMin(float $rohBatches, bool $istVk, ?float $stationDeckel = null, ?bool $stueck = null, ?float $fallbackDeckel = null): ?int
    {
        $aktiv = $this->arbeitszeitMin($rohBatches, $istVk, $stationDeckel, $stueck, $fallbackDeckel);
        $stand = $this->standzeitMin();

        if ($aktiv === null && $stand === null) {
            return null;
        }

        return (int) (($aktiv ?? 0) + ($stand ?? 0));
    }

    /** Alle Fotos des Rezepts (Media-Pool) — verlinkt an Schritte oder „allgemein". */
    public function stepPhotos(): HasMany
    {
        return $this->hasMany(FoodAlchemistRecipeStepPhoto::class, 'recipe_id')
            ->orderBy('sort_order')->orderBy('id');
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
