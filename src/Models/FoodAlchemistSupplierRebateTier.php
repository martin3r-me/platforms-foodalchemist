<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Einkauf E1 — eine Rückvergütungs-Staffelstufe (Schwelle ab € → Rabatt %)
 * eines Teams für einen Lieferanten. team-scopes Overlay über dem globalen Lieferanten
 * (analog FoodAlchemistGpLaPreference). Schreibwege über RebateService.
 */
class FoodAlchemistSupplierRebateTier extends Model
{
    use HasUuidV7, LogsActivity, SoftDeletes;

    protected $table = 'foodalchemist_supplier_rebate_tiers';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'threshold_eur' => 'decimal:2',
        'percent' => 'decimal:2',
        'sort' => 'integer',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistSupplier::class, 'supplier_id');
    }
}
