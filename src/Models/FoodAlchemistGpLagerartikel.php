<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Lagerartikel eines Betriebs (Spec 74): Grundprodukt als Grundvorrat (Gewürze, Öle …) mit
 * Mindest- und Sollbestand in Basiseinheit (g/ml/Stk). Wird nicht über Rezeptbedarf bestellt, sondern nachgefüllt.
 */
class FoodAlchemistGpLagerartikel extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_gp_lagerartikel';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'ist_lagerartikel' => 'boolean',
        'mindestbestand' => 'decimal:4',
        'sollbestand' => 'decimal:4',
    ];

    public function gp(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistGp::class, 'gp_id');
    }
}
