<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;

/**
 * @ai.description Lieferant (Necta-Stamm) — global (D1), Read-only für Teams (Pflege Admin).
 */
class FoodAlchemistSupplier extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_suppliers';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'is_inactive' => 'boolean',
        // R9.1: Beziehungs-Status + Konditionen
        'status' => \Platform\FoodAlchemist\Enums\SupplierStatus::class,
        'rebate_pct' => 'decimal:2',
        'payment_term_days' => 'integer',
        'min_order_value' => 'decimal:2',
        'free_shipping_threshold' => 'decimal:2',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(FoodAlchemistSupplierItem::class, 'supplier_id');
    }

    /** R9.1 — Ansprechpartner. */
    public function contacts(): HasMany
    {
        return $this->hasMany(FoodAlchemistSupplierContact::class, 'supplier_id');
    }

    /** R9.1 — Absprachen/Zusagen-Log. */
    public function agreements(): HasMany
    {
        return $this->hasMany(FoodAlchemistSupplierAgreement::class, 'supplier_id');
    }

    /** R9.1 — Vertrags-/Dokumenten-Ablage. */
    public function documents(): HasMany
    {
        return $this->hasMany(FoodAlchemistSupplierDocument::class, 'supplier_id');
    }
}
