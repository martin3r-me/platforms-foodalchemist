<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Spec 75a — Position eines Lieferscheins: gelieferte Gebinde zu einer Bestellzeile,
 * oder Ware ohne Bestellung (Grundprodukt + Menge in kg/l/Stk, Lagerzugang von Hand).
 */
class FoodAlchemistDeliveryNoteLine extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    public const GRUENDE = [
        'fehlt' => 'fehlt',
        'zu_wenig' => 'zu wenig',
        'zu_viel' => 'zu viel',
        'ersatzartikel' => 'Ersatzartikel',
        'beschaedigt' => 'beschädigt',
        'qualitaet' => 'Qualität mangelhaft',
        'sonstiges' => 'sonstiges',
    ];

    protected $table = 'foodalchemist_delivery_note_lines';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'qty_packs' => 'decimal:2',
        'expected_qty_packs' => 'decimal:2',
        'menge' => 'decimal:3',
        'pack_price_snapshot' => 'decimal:4',
    ];

    public function deliveryNote(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistDeliveryNote::class, 'delivery_note_id');
    }

    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistOrderLine::class, 'order_line_id');
    }

    public function gp(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistGp::class, 'gp_id');
    }
}
