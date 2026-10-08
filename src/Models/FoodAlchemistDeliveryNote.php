<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Spec 75a — Lieferschein als eigener Beleg je Lieferant. Positionen zeigen auf
 * Bestellzeilen (auch aus mehreren Bestellungen) oder stehen ohne Bestellung. Gebucht schreibt die
 * Summe der gebuchten Positionen als Wareneingang auf die Bestellzeile.
 */
class FoodAlchemistDeliveryNote extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    public const STATUS_ENTWURF = 'entwurf';
    public const STATUS_GEBUCHT = 'gebucht';
    public const STATUS_STORNIERT = 'storniert';

    public const STATUS_LABELS = [
        self::STATUS_ENTWURF => 'Entwurf',
        self::STATUS_GEBUCHT => 'gebucht',
        self::STATUS_STORNIERT => 'storniert',
    ];

    protected $table = 'foodalchemist_delivery_notes';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'delivered_on' => 'date',
        'booked_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistSupplier::class, 'supplier_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(FoodAlchemistDeliveryNoteLine::class, 'delivery_note_id')->orderBy('position')->orderBy('id');
    }
}
