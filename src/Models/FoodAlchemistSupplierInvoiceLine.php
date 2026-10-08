<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Spec 75b — Rechnungsposition: Ware zu einer Lieferschein- und/oder Bestellzeile,
 * oder Nebenkosten (Fracht, Pfand, Zuschlag, Rabatt) ohne Artikel. `begruendung` akzeptiert eine
 * Abweichung bewusst (Voraussetzung für die Freigabe).
 */
class FoodAlchemistSupplierInvoiceLine extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    public const ARTEN = [
        'ware' => 'Ware',
        'fracht' => 'Fracht',
        'pfand' => 'Pfand',
        'zuschlag' => 'Zuschlag',
        'rabatt' => 'Rabatt',
    ];

    protected $table = 'foodalchemist_supplier_invoice_lines';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'qty_packs' => 'decimal:2',
        'pack_price' => 'decimal:4',
        'line_net' => 'decimal:2',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistSupplierInvoice::class, 'invoice_id');
    }

    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistOrderLine::class, 'order_line_id');
    }

    public function deliveryNoteLine(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistDeliveryNoteLine::class, 'delivery_note_line_id');
    }
}
