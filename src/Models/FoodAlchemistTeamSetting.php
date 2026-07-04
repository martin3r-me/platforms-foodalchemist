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
        'ausweich_kette_anzeigen' => 'boolean',
        'cooking_loss_defaults' => 'array',
        'trimming_loss_defaults' => 'array',
        'mwst_defaults' => 'array',
        'rundungsregeln' => 'array',
        'hk2_zuschlag_pct' => 'decimal:2',
        // M-K1: Kalkulations-Block-Schema (Doc 16)
        'calculation_schema' => 'array',
        'stundensatz_eur' => 'decimal:2',
        'marge_pct' => 'decimal:2',
        // #379+: Ziel-Wareneinsatzquote (Food-Cost-%) — Controlling-Ziel + Break-even-Treiber
        'ziel_wareneinsatz_pct' => 'decimal:2',
        // #379+: Lohnnebenkosten-Zuschlag % (AG-/Sozialabgaben auf den Produktionslohn)
        'lohnnebenkosten_pct' => 'decimal:2',
        // M-K6: Bezugsbasen für die Fixkosten-Ableitung (mek/fek/hk, monatlich)
        'calculation_bezugsbasen' => 'array',
        // Phase 5: pro-Typ-Farben (GP / Basisrezept / Gericht), teamweit
        'typ_farben' => 'array',
    ];
}
