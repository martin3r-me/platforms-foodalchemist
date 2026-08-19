<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Enums\OrderStatus;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Spec 17/S2 — Bestellung/Bestellschiene je Lieferant (N-Track, ohne Bestand).
 * Höchstens EIN offener `draft` je (team, supplier) sammelt Bedarf; send friert den Beleg ein.
 */
class FoodAlchemistOrder extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_orders';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'status' => OrderStatus::class,
        'desired_delivery_date' => 'date',
        'total_net' => 'decimal:2',
        'sent_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'confirmed_delivery_date' => 'date',
        'delivered_at' => 'datetime',
        'invoice_date' => 'date',
        'invoice_paid_at' => 'date',
        'approval_requested_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistSupplier::class, 'supplier_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(FoodAlchemistOrderLine::class, 'order_id')->orderBy('position');
    }

    public function rounds(): BelongsToMany
    {
        return $this->belongsToMany(
            FoodAlchemistOrderRound::class,
            'foodalchemist_order_round_links',
            'order_id',
            'round_id'
        )->withTimestamps();
    }
}
