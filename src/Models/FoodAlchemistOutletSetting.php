<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Ebene 2 (Betriebs-/Kunden-Kalkulation): Outlet-Override der
 * Kalkulations-Skalare — eine Zeile je Betrieb (Outlet), ALLE Felder nullable = „erben vom
 * Team". Auflösung Outlet→Team→Default lebt in TeamSettingsService::skalar (nicht hier).
 * Preisklassen bleiben team-geteilt (bewusst nicht abgebildet). Schreibweg: OutletSettingsService.
 */
class FoodAlchemistOutletSetting extends Model
{
    use HasUuidV7, LogsActivity, SoftDeletes, BelongsToTeamHierarchy;

    protected $table = 'foodalchemist_outlet_settings';

    protected $guarded = ['id'];

    protected $casts = [
        'margin_pct' => 'decimal:2',
        'target_food_cost_pct' => 'decimal:2',
        'stundensatz_eur' => 'decimal:2',
        'calculation_schema' => 'array',
        'calculation_reference_bases' => 'array',
        'hk2_surcharge_pct' => 'decimal:2',
        'labor_overhead_pct' => 'decimal:2',
        'outlet_role_rates' => 'array',
    ];

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistOutlet::class, 'outlet_id');
    }
}
