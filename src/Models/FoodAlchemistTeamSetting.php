<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Enums\LeadLaStrategie;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Team-Einstellungen (eine Zeile je Team): Lead-LA-Strategie (M1-05/V-27)
 * und Kalkulations-Defaults (M1-07/GL-02).
 *
 * #390 (2026-06-17): Trait für die Ahnen-Kette (teamAncestryIds), damit der
 * Per-Setting-Resolver in TeamSettingsService org-vererbte Settings (z. B. MwSt)
 * über die Team-Hierarchie auflösen kann. Team-lokale Settings (Marge/Stundensatz/
 * Küchen-Profil) lesen weiterhin NUR die eigene Zeile — Policy: TeamSettingsService::ORG_VERERBT.
 */
class FoodAlchemistTeamSetting extends Model
{
    use HasUuidV7, LogsActivity, SoftDeletes, BelongsToTeamHierarchy;

    protected $table = 'foodalchemist_team_settings';

    protected $guarded = ['id'];

    protected $casts = [
        'lead_la_strategie' => LeadLaStrategie::class,
        'lead_la_strategie_per_wg' => 'array',
        'lead_la_prioritaeten' => 'array',
        'show_fallback_chain' => 'boolean',
        'cooking_loss_defaults' => 'array',
        'trimming_loss_defaults' => 'array',
        'vat_defaults' => 'array',
        'rundungsregeln' => 'array',
        'hk2_surcharge_pct' => 'decimal:2',
        // M-K1: Kalkulations-Block-Schema (Doc 16)
        'calculation_schema' => 'array',
        'stundensatz_eur' => 'decimal:2',
        'margin_pct' => 'decimal:2',
        // #379+: Ziel-Wareneinsatzquote (Food-Cost-%) — Controlling-Ziel + Break-even-Treiber
        'target_food_cost_pct' => 'decimal:2',
        // #379+: Lohnnebenkosten-Zuschlag % (AG-/Sozialabgaben auf den Produktionslohn)
        'labor_overhead_pct' => 'decimal:2',
        // R2.1: Preis-Alarm-Schwelle (relative LA-Preisänderung in %, ab der ein Signal entsteht)
        'price_alarm_threshold_pct' => 'decimal:2',
        // R2.5: Saison-Auto-Pricing-Leitplanken (Margen-Zielband, max VK-Delta je Freigabe, Mindestmarge)
        'season_margin_band_min_pct' => 'decimal:2',
        'season_margin_band_max_pct' => 'decimal:2',
        'max_vk_delta_pct' => 'decimal:2',
        'min_margin_pct' => 'decimal:2',
        // M-K6: Bezugsbasen für die Fixkosten-Ableitung (mek/fek/hk, monatlich)
        'calculation_reference_bases' => 'array',
        // Phase 5: pro-Typ-Farben (GP / Basisrezept / Gericht), teamweit
        'type_colors' => 'array',
    ];
}
