<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/** R9.1 (E2) — datierte Absprache/Zusage je Lieferant, mit Wiedervorlage. */
class FoodAlchemistSupplierAgreement extends Model
{
    use BelongsToTeamHierarchy, HasUuidV7, LogsActivity, SoftDeletes;

    protected $table = 'foodalchemist_supplier_agreements';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'valid_from' => 'date',
        'valid_to' => 'date',
        'follow_up_at' => 'date',
        'author_id' => 'integer',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistSupplier::class, 'supplier_id');
    }
}
