<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Geschirr-Artikel (non-food) — konkreter Leih-Artikel eines Geschirr-
 * Lieferanten mit Leihpreis + Maßen/Material. KEIN GP-Layer (Dominique 2026-06-17, #388).
 */
class FoodAlchemistGeschirrItem extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_tableware_items';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'diameter_mm' => 'decimal:1',
        'length_mm' => 'decimal:1',
        'width_mm' => 'decimal:1',
        'height_mm' => 'decimal:1',
        'volumen_ml' => 'decimal:1',
        'weight_g' => 'decimal:1',
        'rental_price' => 'decimal:2',
        'pfand' => 'decimal:2',
        'is_inactive' => 'boolean',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistGeschirrSupplier::class, 'tableware_supplier_id');
    }

    /** Kurz-Maß-Label für Listen/Detail (Ø / L×B×H / Volumen — je nachdem was gesetzt ist). */
    public function getMasseLabelAttribute(): ?string
    {
        $teile = [];
        if ($this->diameter_mm !== null) {
            $teile[] = 'Ø ' . rtrim(rtrim((string) $this->diameter_mm, '0'), '.') . ' mm';
        }
        if ($this->length_mm !== null || $this->width_mm !== null || $this->height_mm !== null) {
            $lbh = collect([$this->length_mm, $this->width_mm, $this->height_mm])
                ->filter(fn ($v) => $v !== null)
                ->map(fn ($v) => rtrim(rtrim((string) $v, '0'), '.'))
                ->implode(' × ');
            $teile[] = $lbh . ' mm';
        }
        if ($this->volumen_ml !== null) {
            $teile[] = rtrim(rtrim((string) $this->volumen_ml, '0'), '.') . ' ml';
        }

        return $teile === [] ? null : implode(' · ', $teile);
    }
}
