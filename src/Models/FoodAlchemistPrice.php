<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;

/**
 * @ai.description Preis-Zeile eines Lieferantenartikels (Necta, append-only-Historie im Ziel).
 * Kategorisierung (standard_ek/aktion/eingestellt/datenluecke) = GL-11 T1, lebt im Service.
 * Bewusst OHNE LogsActivity: Massendaten, Audit über Import-Reports.
 */
class FoodAlchemistPrice extends Model
{
    use HasUuidV7, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_prices';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'price' => 'decimal:4',
        'price_partial' => 'decimal:4',
        'valid_to' => 'datetime',
        'status_valid_from' => 'datetime',
        'is_blocked' => 'boolean',
        'change_date' => 'datetime',
        'creation_date' => 'datetime',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistSupplierItem::class, 'supplier_item_id');
    }
}
