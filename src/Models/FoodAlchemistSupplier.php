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

    /** Spec 82 §12 A3: globale Zeilen tragen eine Klasse (basis | stamm_paket). */
    public const STAMM_KLASSE = true;

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
        'order_lead_days' => 'integer',
        'allergene_nur_positiv' => 'boolean',   // Spec 85: leer = angenommen nicht enthalten
        'allergene_nur_positiv_seit' => 'date',
        'rolle' => \Platform\FoodAlchemist\Enums\SupplierRolle::class,   // §5 Ausnahme 3
    ];

    /** Hauptgruppe (Taxonomie); `branch` bleibt der Necta-Rohwert. */
    public function group(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(FoodAlchemistSupplierGroup::class, 'group_id');
    }

    /** §5 Ausnahme 3: Marken-Kürzel dieses Lieferanten (Eigenmarken, lieferantenbezogene Kürzel); globale Kürzel haben supplier_id NULL. */
    public function marken(): HasMany
    {
        return $this->hasMany(FoodAlchemistSupplierMarke::class, 'supplier_id');
    }

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
