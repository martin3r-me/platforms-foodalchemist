<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Einkauf E1 — 1:1-Konfiguration der Rückvergütung je (Team, Lieferant):
 * aktiv-Schalter, manuell gewählte Stufe (selected_tier), angenommener Jahresumsatz
 * (Auto-Stufe) und ausgeschlossene Warengruppen. Schwester zu FoodAlchemistSupplierRebateTier.
 * team-scopes Overlay; Schreibwege über RebateService.
 */
class FoodAlchemistSupplierRebateConfig extends Model
{
    use HasUuidV7, LogsActivity, SoftDeletes;

    protected $table = 'foodalchemist_supplier_rebate_configs';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'active' => 'boolean',
        'assumed_annual_revenue' => 'decimal:2',
        'applies_to_all' => 'boolean',
        'commodity_groups' => 'array',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistSupplier::class, 'supplier_id');
    }

    public function selectedTier(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistSupplierRebateTier::class, 'selected_tier_id');
    }
}
