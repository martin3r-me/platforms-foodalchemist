<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;

/**
 * @ai.description Lieferant (Necta-Stamm) — global (D1), Read-only für Teams (Pflege Admin).
 */
class FoodAlchemistSupplier extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_suppliers';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'is_inactive' => 'boolean',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(FoodAlchemistSupplierItem::class, 'supplier_id');
    }
}
