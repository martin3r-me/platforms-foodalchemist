<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Enums\ProductionOrderStatus;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Spec 18 — Produktionsauftrag je (team, production_date). Aggregiert
 * mehrere Ziele (Konzept+Personen ODER Gericht+Portionen) desselben Produktionstags in
 * `targets`; die Zeilen entstehen ausschließlich über eine vollständige Neu-Explosion
 * (ProductionOrderService::recomputeOrder), nie additiv gepatcht.
 */
class FoodAlchemistProductionOrder extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_production_orders';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'status' => ProductionOrderStatus::class,
        'production_date' => 'date',
        'targets' => 'array',
        'warnungen' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(FoodAlchemistProductionOrderLine::class, 'production_order_id')->orderBy('position');
    }
}
