<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Enums\ProductionLineStatus;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Spec 18 — Produktionszeile PRO REZEPT (nicht pro Ziel). Inhalt = ein
 * Eintrag aus PlanungsblattService::produktionsblattFuerZiele()['rezepte']. Snapshot-
 * Felder frieren beim Übergang planned→in_progress ein.
 *
 * Spec 30 — zwei Populationen, die der Recompute unterschiedlich behandelt:
 *  - `origin='computed'`: gehört der Explosion, wird bei JEDEM Recompute gelöscht und neu
 *    erzeugt. Was der Mensch daran gepflegt hat, überlebt als Overlay
 *    (`ProductionOrderService::OVERLAY_FELDER`, per `recipe_id` wieder aufgesetzt).
 *  - `origin='manual'`: freie Position ohne Rezept (`recipe_id IS NULL`), liegt außerhalb
 *    des Recomputes und wird von ihm nie angefasst.
 *
 * Der Ansätze-Override liegt in `manual_ansaetze`, NICHT in `ansaetze` — nur so bleibt der
 * berechnete Wert als Referenz erhalten („manuell 2 — berechnet wären 3"). Lesen immer über
 * `ansaetze_effektiv`.
 */
class FoodAlchemistProductionOrderLine extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_production_order_lines';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'is_basisrezept' => 'boolean',
        'tiefe' => 'integer',
        'ansaetze' => 'decimal:3',
        'benoetigt_ansaetze' => 'decimal:3',
        'portionen' => 'integer',
        'basis_yield_kg' => 'decimal:3',
        'produzierte_menge_kg' => 'decimal:3',
        'arbeitszeit_min' => 'integer',
        'darreichung' => 'array',
        'zutaten' => 'array',
        'steps_snapshot' => 'array',   // Spec 27: eingefrorene Schrittfolge (NULL = Alt-Auftrag)
        'manual_ansaetze' => 'decimal:3',   // Spec 30
        'is_manual_ansaetze' => 'boolean',
        'is_struck' => 'boolean',
        'vorlauf_tage' => 'integer',        // Spec 30 E3: Rückwärts-Offset auf den Liefertag
        'plan_date' => 'date',              // abgeleitet — einziger Schreiber: syncPlanDates()
        'line_status' => ProductionLineStatus::class,   // Spec 30 E6: Küchen-Checkliste
        'done_at' => 'datetime',
    ];

    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistProductionOrder::class, 'production_order_id');
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistRecipe::class, 'recipe_id');
    }

    /** Posten (Spec 30 E3) — NULL = unverplant. */
    public function station(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistProductionStation::class, 'station_id');
    }

    /** Freie Position (Spec 30) — kein Rezept dahinter, vom Recompute unberührt. */
    public function istManuell(): bool
    {
        return $this->origin === 'manual';
    }

    /**
     * Die Ansätze, mit denen die Küche wirklich arbeitet: Override wenn gesetzt, sonst der
     * berechnete Wert. JEDE Summe und jeder Druck liest das hier, nie `ansaetze` direkt.
     */
    public function getAnsaetzeEffektivAttribute(): float
    {
        return $this->is_manual_ansaetze && $this->manual_ansaetze !== null
            ? (float) $this->manual_ansaetze
            : (float) $this->ansaetze;
    }

    /** Override weicht vom neu berechneten Wert ab → UI zeigt „berechnet wären N · zurücksetzen". */
    public function getOverrideStaleAttribute(): bool
    {
        return $this->is_manual_ansaetze
            && $this->manual_ansaetze !== null
            && (float) $this->manual_ansaetze !== (float) $this->ansaetze;
    }

    /** Anzeigename — freie Positionen haben kein Rezept. */
    public function anzeigeName(): string
    {
        return $this->istManuell()
            ? (string) ($this->titel ?: 'Freie Position')
            : (string) ($this->recipe?->name ?? '—');
    }
}
