<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Geschirr-Leih-Lieferant (non-food) — team-eigen, KEIN WaWi-Sync.
 * Spiegelt FoodAlchemistSupplier strukturell (#388, Dominique 2026-06-17).
 */
class FoodAlchemistGeschirrSupplier extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_tableware_suppliers';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'is_inactive' => 'boolean',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(FoodAlchemistGeschirrItem::class, 'geschirr_supplier_id');
    }
}
