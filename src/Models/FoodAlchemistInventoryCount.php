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
 * @ai.description Inventur (Spec 66): Stichtag + Lagerort + Zählliste. Status offen → gebucht.
 * Gebucht setzt den Lagerbestand auf die gezählten Mengen; der Wert (value_total) ist der
 * bewertete Bestand zum Stichtag (Grundlage des Ist-Wareneinsatzes).
 */
class FoodAlchemistInventoryCount extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_inventory_counts';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'count_date' => 'date',
        'value_total' => 'decimal:2',
        'booked_at' => 'datetime',
    ];

    public function location(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistInventoryLocation::class, 'inventory_location_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(FoodAlchemistInventoryCountLine::class, 'inventory_count_id')->orderBy('position');
    }

    public function istGebucht(): bool
    {
        return $this->status === 'gebucht';
    }
}
