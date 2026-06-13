<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Enums\LeadLaStrategie;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Team-Einstellungen (eine Zeile je Team): Lead-LA-Strategie (M1-05/V-27)
 * und Kalkulations-Defaults (M1-07/GL-02). Bewusst OHNE BelongsToTeamHierarchy —
 * Einstellungen werden nicht vererbt, jedes Team entscheidet selbst.
 */
class FoodAlchemistTeamSetting extends Model
{
    use HasUuidV7, LogsActivity, SoftDeletes;

    protected $table = 'foodalchemist_team_settings';

    protected $guarded = ['id'];

    protected $casts = [
        'lead_la_strategie' => LeadLaStrategie::class,
        'lead_la_prioritaeten' => 'array',
        'ausweich_kette_anzeigen' => 'boolean',
        'garverlust_defaults' => 'array',
        'mwst_defaults' => 'array',
        'rundungsregeln' => 'array',
        'hk2_zuschlag_pct' => 'decimal:2',
    ];
}
