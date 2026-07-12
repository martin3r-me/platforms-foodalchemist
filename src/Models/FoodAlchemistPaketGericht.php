<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Paket-Gericht (M10-01) — ein VK-Rezept als Bestandteil eines
 * Pakets (z. B. „Green Power" in „Salad Wall"). Position-sortiert.
 */
class FoodAlchemistPaketGericht extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_package_dishes';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'quantity' => 'decimal:3',
        'position' => 'integer',
    ];

    public function package(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistPaket::class, 'package_id');
    }

    /** @deprecated #486 deutscher Alias → package() */
    public function paket(): BelongsTo
    {
        return $this->package();
    }

    /** Das verknüpfte Gericht (VK-Rezept). */
    public function dish(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistRecipe::class, 'sales_recipe_id');
    }

    /** @deprecated #486 deutscher Alias → dish() */
    public function gericht(): BelongsTo
    {
        return $this->dish();
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistVocabEinheit::class, 'unit_vocab_id');
    }

    /** Optional: explizit gewählte Darreichung des Gerichts (Umbau-Spec Phase 3). */
    public function presentation(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistRecipeDarreichung::class, 'presentation_id');
    }

    /** @deprecated #486 deutscher Alias → presentation() */
    public function darreichung(): BelongsTo
    {
        return $this->presentation();
    }
}
