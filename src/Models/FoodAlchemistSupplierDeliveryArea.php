<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/** Liefergebiet eines Lieferanten (Link-Stufe 2d): Liefertage/-zeiten, Bestellschluss, Mindestbestellwert je Gebiet. team_id-gebunden. */
class FoodAlchemistSupplierDeliveryArea extends Model
{
    use BelongsToTeamHierarchy, HasUuidV7, LogsActivity, SoftDeletes;

    protected $table = 'foodalchemist_supplier_delivery_areas';

    protected $guarded = ['id'];

    protected $casts = ['uuid' => 'string', 'lead_days' => 'integer', 'min_order_value' => 'decimal:2'];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistSupplier::class, 'supplier_id')->ohneTeamSicht('verweis');
    }
}
