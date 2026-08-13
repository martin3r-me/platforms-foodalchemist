<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description WaWi light — Lagerort/Lager in den Einkaufs-Einstellungen.
 */
class FoodAlchemistInventoryLocation extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_inventory_locations';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function stocks(): HasMany
    {
        return $this->hasMany(FoodAlchemistInventoryStock::class, 'inventory_location_id');
    }
}
