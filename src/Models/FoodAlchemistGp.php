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

    /** Spec 82 §12 A3: globale Zeilen tragen eine Klasse (basis | stamm_paket). */
    public const STAMM_KLASSE = true;

    protected $table = 'foodalchemist_gps';

    /**
     * Ein bestehendes GP, das nach global umgehängt wird (team_id → NULL), wird Stamm-Paket (Kurator 27.10.2026).
     *
     * Fund io: 57 Marken-Split-GPs wurden per createGp teameigen angelegt und danach global gestellt. Die Spalte `stamm_class` behielt
     * ihren DB-Default `basis`, und Basis-Zeilen der Stamm-Modelle sind für ALLE nur lesbar, auch für den Master
     * ({@see Concerns\BelongsToTeamHierarchy::isOwnedBy()}). Wer ein GP in den globalen Bestand hebt, kuratiert Stamm-Inhalt, und den
     * pflegt der Master nur als `stamm_paket` (so setzt es auch der GP-Paket-Import).
     *
     * Bewusst NUR beim Umhängen, nicht bei einer Anlage mit team_id NULL: `basis` ist für globale GPs ein gewollter Fall (Spec 82 §12 A3:
     * „Default ist basis, Bestand ohne Klasse bleibt für alle Teams sichtbar“; Basis-GP, an dem nur der Master den Lead setzt,
     * Dominique 18.10.). Das zeigen StammPaketScopeTest, MasterPflegtGlobalenStammTest und Spec85ControllingLeadBesitzerTest.
     * Eine ausdrücklich mitgesetzte Klasse gilt immer. Query-Builder-Wege (DB::table) umgehen das Modell.
     */
    protected static function booted(): void
    {
        static::saving(function (self $gp) {
            if ($gp->exists && $gp->team_id === null && $gp->isDirty('team_id') && ! $gp->isDirty('stamm_class')) {
                $gp->stamm_class = \Platform\FoodAlchemist\Support\StammPaket::PAKET;
            }
        });
    }

    /** Breite Stammdaten-Tabelle — Schreibwege laufen über Services (01_ARCHITEKTUR §1). */
    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'status' => GpStatus::class,
        'is_derivat' => 'boolean',
        'requires_la' => 'boolean',
        'is_platzhalter' => 'boolean',
        'namens_vegan' => 'boolean',
        'namens_glutenfrei' => 'boolean',
        'namens_laktosefrei' => 'boolean',
        'rezepturware' => 'boolean',   // §5 Ausnahme 3, nullable: NULL = nicht entschieden
        'marke_offen' => 'boolean',
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
        'diameter_mm' => 'decimal:1', 'length_mm' => 'decimal:1', 'width_mm' => 'decimal:1', 'height_mm' => 'decimal:1', 'volume_ml' => 'decimal:1',   // Maße, nur WG 16
    ];

    /** Non-Food-Grundprodukt (Warengruppe 16): keine Allergene, Nährwerte, Zustände; als Zutat gesperrt, am Rezept nur als Zubehör & Verpackung. */
    public function istNonFood(): bool
    {
        return (string) $this->commodity_group_code === \Platform\FoodAlchemist\Support\NonFoodRegeln::WG_CODE;
    }

    /** Größe in Betriebs-Einheiten („Ø 27 cm“, „30 x 20 x 5 cm“, „1,5 l“) aus den Maßen — nur Non-Food; null ohne Maß. */
    public function masseText(): ?string
    {
        return $this->istNonFood() ? \Platform\FoodAlchemist\Support\NonFoodMasse::groesse($this->only(\Platform\FoodAlchemist\Support\NonFoodMasse::FELDER)) : null;
    }

    /** Nur Lebensmittel-GPs (ohne Warengruppe 16); GPs ohne Warengruppe zählen als Lebensmittel. */
    public function scopeLebensmittel(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where(fn ($w) => $w->whereNull('commodity_group_code')->orWhere('commodity_group_code', '!=', \Platform\FoodAlchemist\Support\NonFoodRegeln::WG_CODE));
    }

    /** Nur Non-Food-GPs (Warengruppe 16). */
    public function scopeNonFood(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('commodity_group_code', \Platform\FoodAlchemist\Support\NonFoodRegeln::WG_CODE);
    }

    /**
     * Spec 85 FAV-1: die wirksamen Favoriten EINES Teams (Overlay mit Übernahme vom Hauptteam, nie vom Master), nach Rang.
     * Ersetzt das frühere `scopeFavorites` (globale Spalte `is_favorite`, entfernt in FAV-3).
     */
    public function scopeFavoritenFuer(\Illuminate\Database\Eloquent\Builder $query, \Platform\Core\Models\Team $team): \Illuminate\Database\Eloquent\Builder
    {
        $ids = \Platform\FoodAlchemist\Support\FavoritenOverlay::gpIds($team);
        if ($ids === []) {
            return $query->whereRaw('1 = 0');
        }
        $reihenfolge = 'CASE ' . $this->getTable() . '.id ' . implode(' ', array_map(fn ($id, $pos) => 'WHEN ' . (int) $id . ' THEN ' . (int) $pos, $ids, array_keys($ids))) . ' END';

        return $query->whereIn($this->getTable() . '.id', $ids)->orderByRaw($reihenfolge);
    }

    public function commodity_group(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistLookupWarengruppe::class, 'commodity_group_code', 'code')->ohneTeamSicht('verweis');
    }

    public function preferredCountUnit(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistVocabEinheit::class, 'preferred_count_unit_id')->ohneTeamSicht('verweis');
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

    /** #9 (2026-08-28): Naturaleinheit-Formen mit Gramm-Gewicht (Stück/Scheibe/Würfel …). */
    public function forms(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(FoodAlchemistGpForm::class, 'gp_id');
    }

    /** Kalkulationsführender Lieferantenartikel (GL-03; V-27-Kette folgt in D-2). */
    public function leadLa(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistSupplierItem::class, 'lead_la_supplier_item_id')->ohneTeamSicht('verweis');
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
