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
 * @ai.description Spec 75b — Lieferanten-Rechnung als eigener Beleg. Rechnet Lieferscheine bzw.
 * Bestellzeilen eines Lieferanten ab (auch Sammelrechnung). Freigegeben schreibt Menge und Preis als
 * Rechnungsprüfung auf die Bestellzeile; Freigeben verlangt die Rolle „Freigeben" (Spec 61).
 */
class FoodAlchemistSupplierInvoice extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    public const STATUS_ERFASST = 'erfasst';
    public const STATUS_FREIGEGEBEN = 'freigegeben';
    public const STATUS_BEZAHLT = 'bezahlt';
    public const STATUS_STORNIERT = 'storniert';

    public const STATUS_LABELS = [
        self::STATUS_ERFASST => 'in Prüfung',
        self::STATUS_FREIGEGEBEN => 'freigegeben',
        self::STATUS_BEZAHLT => 'bezahlt',
        self::STATUS_STORNIERT => 'storniert',
    ];

    protected $table = 'foodalchemist_supplier_invoices';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'invoice_date' => 'date',
        'due_date' => 'date',
        'paid_at' => 'date',
        'total_net' => 'decimal:2',
        'is_disputed' => 'boolean',
        'approved_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistSupplier::class, 'supplier_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(FoodAlchemistSupplierInvoiceLine::class, 'invoice_id')->orderBy('position')->orderBy('id');
    }
}
