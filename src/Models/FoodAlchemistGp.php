<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Enums\AllergenValue;
use Platform\FoodAlchemist\Enums\GpStatus;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;

/**
 * @ai.description Grundprodukt (GP) — abstrakte, kuratierte Zutat (z.B. "Zanderfilet"). Kern der
 * Stammdaten-Welt: trägt Naming (§6), Klassifikation (Warengruppe/Zustand), Allergen-Override-Layer
 * (GL-01), Eigenschafts-Tags und Kalkulations-Defaults. team_id NULL = global/BHG-kuratiert (D1).
 */
class FoodAlchemistGp extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    /** Die 14 EU-Allergen-Spalten (GL-01) — Reihenfolge = Anzeige-Reihenfolge. */
    public const ALLERGEN_FIELDS = [
        'gluten', 'crustaceans', 'eggs', 'fish', 'peanuts', 'soy', 'milk',
        'tree_nuts', 'celery', 'mustard', 'sesame', 'sulphites', 'lupin', 'molluscs',
    ];

    public const TAG_FIELDS = [
        'is_vegan', 'is_vegetarian', 'is_halal', 'contains_pork', 'contains_beef',
        'is_organic', 'is_regional', 'is_staple_food', 'is_convenience',
        'is_lactose_free', 'is_gluten_free',
    ];

    protected $table = 'foodalchemist_gps';

    /** Breite Stammdaten-Tabelle — Schreibwege laufen über Services (01_ARCHITEKTUR §1). */
    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'status' => GpStatus::class,
        'is_derivat' => 'boolean',
        'requires_la' => 'boolean',
        'is_platzhalter' => 'boolean',
        'n_las_total' => 'integer',
        'cooking_loss_default_pct' => 'decimal:2',
        'trimming_loss_default_pct' => 'decimal:2',
        'piece_default_g' => 'decimal:2',
        'ai_confidence' => 'decimal:3',
        'allergens_confidence' => 'decimal:3',
        'tag_ai_confidence' => 'decimal:3',
        'food_domain_ai_confidence' => 'decimal:3',
        'stk_default_g_ai_confidence' => 'decimal:3',
        'first_seen_at' => 'datetime',
        'last_review_at' => 'datetime',
        'allergens_aggregated_at' => 'datetime',
        'tag_aggregated_at' => 'datetime',
        'food_domain_aggregated_at' => 'datetime',
        // Tags tri-state: NULL = unbewertet
        'tag_is_vegan' => 'boolean',
        'tag_is_vegetarian' => 'boolean',
        'tag_is_halal' => 'boolean',
        'tag_contains_pork' => 'boolean',
        'tag_contains_beef' => 'boolean',
        'tag_is_organic' => 'boolean',
        'tag_is_regional' => 'boolean',
        'tag_is_staple_food' => 'boolean',
        'tag_is_convenience' => 'boolean',
        'tag_is_lactose_free' => 'boolean',
        'tag_is_gluten_free' => 'boolean',
        // 06·H1 Convenience-Highlights (kuratierte Haus-Standard-Liste)
        'is_favorite' => 'boolean',
        'favorite_rank' => 'integer',
    ];

    /** 06·H1: kuratierte Favoriten-GPs (nach Anzeige-Rang, dann Name). */
    public function scopeFavorites(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('is_favorite', true)
            ->orderByRaw('favorite_rank IS NULL, favorite_rank ASC')
            ->orderBy('name');
    }

    public function commodity_group(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistLookupWarengruppe::class, 'commodity_group_code', 'code');
    }

    public function preferredCountUnit(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistVocabEinheit::class, 'preferred_count_unit_id');
    }

    public function derivedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'derivat_von_gp_id');
    }

    /** @deprecated #486 deutscher Alias → derivedFrom() */
    public function derivatVon(): BelongsTo
    {
        return $this->derivedFrom();
    }

    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_id');
    }

    /** Strukturierte LA-Zuordnungen dieses GP (GL-05). */
    public function structures(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(FoodAlchemistSupplierItemStructure::class, 'gp_id');
    }

    /** Rezept-Zutatenzeilen, die dieses GP referenzieren (Verwendungs-Zähler, D-5). */
    public function recipeIngredients(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(FoodAlchemistRecipeIngredient::class, 'gp_id');
    }

    /** Kalkulationsführender Lieferantenartikel (GL-03; V-27-Kette folgt in D-2). */
    public function leadLa(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistSupplierItem::class, 'lead_la_supplier_item_id');
    }

    /**
     * Gesetzte Allergen-Overrides als [feld => AllergenValue] — NULL-Spalten (kein Override) entfallen.
     * Achtung GL-01: Das ist NUR der Override-Layer; die Wahrheit für Rezepte ist COALESCE(Override, LA-MAX).
     */
    public function allergenOverrides(): array
    {
        $out = [];
        foreach (self::ALLERGEN_FIELDS as $field) {
            $raw = $this->getAttribute("allergen_{$field}");
            if ($raw !== null && $raw !== '') {
                $value = AllergenValue::tryFrom($raw);
                if ($value !== null) {
                    $out[$field] = $value;
                }
            }
        }

        return $out;
    }

    /** Gesetzte (nicht-NULL) Eigenschafts-Tags als [tag => bool]. */
    public function setTags(): array
    {
        $out = [];
        foreach (self::TAG_FIELDS as $tag) {
            $value = $this->getAttribute("tag_{$tag}");
            if ($value !== null) {
                $out[$tag] = (bool) $value;
            }
        }

        return $out;
    }
}
