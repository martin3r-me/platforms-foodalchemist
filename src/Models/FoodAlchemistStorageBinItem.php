<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @ai.description Stammplatz eines Grundprodukts in einem Lagerort (Spec 66b). */
class FoodAlchemistStorageBinItem extends Model
{
    protected $table = 'foodalchemist_storage_bin_items';

    protected $guarded = ['id'];

    public function bin(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistStorageBin::class, 'storage_bin_id');
    }

    public function gp(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistGp::class, 'gp_id');
    }
}
