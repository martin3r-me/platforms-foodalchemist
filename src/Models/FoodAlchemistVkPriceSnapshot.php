<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * R2.5 — Freigegebener VK-Snapshot je Darreichung (Präsentation). Der Kundenpreis,
 * eingefroren zum Freigabe-Zeitpunkt; die interne Live-Marge rechnet weiter. Ein
 * neuer Snapshot ist die einzige Art, den veröffentlichten VK zu ändern (menschliche
 * Batch-Freigabe) — kein stiller Kunden-Preissprung.
 */
class FoodAlchemistVkPriceSnapshot extends Model
{
    use BelongsToTeamHierarchy, HasUuidV7, LogsActivity, SoftDeletes;

    protected $table = 'foodalchemist_vk_price_snapshots';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'sales_net' => 'decimal:2',
        'sales_gross' => 'decimal:2',
        'released_at' => 'datetime',
        'released_by' => 'integer',
    ];

    public function presentation(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistRecipeDarreichung::class, 'presentation_id');
    }
}
