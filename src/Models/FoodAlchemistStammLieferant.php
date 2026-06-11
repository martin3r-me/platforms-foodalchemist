<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Stamm-Lieferant (M1-06): Lieferant × Warengruppe je Team;
 * warengruppe_code NULL = globaler Stamm. Kind-Teams erben die Eltern-Matrix
 * lesend (D1) und ergänzen eigene Einträge.
 */
class FoodAlchemistStammLieferant extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_stamm_lieferanten';

    protected $guarded = ['id'];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistSupplier::class, 'supplier_id');
    }
}
