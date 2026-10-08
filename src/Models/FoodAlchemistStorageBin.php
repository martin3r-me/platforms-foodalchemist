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
 * @ai.description Stellplatz in einem Lagerort (Spec 66b), z. B. „Kühlhaus · Regal A". Zone steuert
 * den Stammplatz-Vorschlag, sort_order den Laufweg der Zählliste.
 */
class FoodAlchemistStorageBin extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    public const ZONEN = ['kuehl' => 'Kühlung', 'tk' => 'Tiefkühlung', 'trocken' => 'Trocken', 'getraenke' => 'Getränke', 'sonstig' => 'Sonstiges'];

    protected $table = 'foodalchemist_storage_bins';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function location(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistInventoryLocation::class, 'inventory_location_id');
    }

    public function zuordnungen(): HasMany
    {
        return $this->hasMany(FoodAlchemistStorageBinItem::class, 'storage_bin_id');
    }
}
