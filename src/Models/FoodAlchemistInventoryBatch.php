<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Charge einer Eigenproduktion im Lager (Spec 69): Rezept, Lagerort, hergestellt/eingefroren,
 * verbrauchen bis, Anfangsmenge und Rest (g/Stk/Port), Bewertung zum Rezept-EK. Entnahme FIFO nach Haltbarkeit.
 */
class FoodAlchemistInventoryBatch extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_inventory_batches';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'produced_at' => 'date',
        'frozen_at' => 'date',
        'best_before' => 'date',
        'qty_initial' => 'decimal:4',
        'qty_rest' => 'decimal:4',
        'price_per_base' => 'decimal:6',
        'closed_at' => 'datetime',
    ];

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistRecipe::class, 'recipe_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistInventoryLocation::class, 'inventory_location_id');
    }

    public function istOffen(): bool
    {
        return $this->closed_at === null && (float) $this->qty_rest > 0;
    }

    /** Tage bis „verbrauchen bis" (negativ = abgelaufen), null ohne Datum. */
    public function tageBisAblauf(): ?int
    {
        return $this->best_before !== null ? (int) now()->startOfDay()->diffInDays($this->best_before, false) : null;
    }
}
