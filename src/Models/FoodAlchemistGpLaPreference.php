<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description V-27-Overlay (M3-06): team-eigene Lead-LA-Abweichung über der
 * globalen GL-03-Kette — locked (LA fällt für dieses Team aus) / gepinnt
 * (fixiert als effektiver Lead, überlebt Bulk-Neuwahl). Schreibwege über LeadLaService.
 */
class FoodAlchemistGpLaPreference extends Model
{
    use HasUuidV7, LogsActivity, SoftDeletes;

    protected $table = 'foodalchemist_gp_la_preferences';

    protected $guarded = ['id'];

    protected $casts = ['locked' => 'boolean', 'gepinnt' => 'boolean'];

    public function gp(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistGp::class, 'gp_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistSupplierItem::class, 'supplier_item_id');
    }
}
