<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Position einer Standalone-Kalkulation (M-K10, Doc 16 §11). typ =
 * gericht|basisrezept|gp|frei. Bei Referenz-Typen sind label/unit/einzel_ek/
 * work_time_min ein Snapshot der Quelle (überschreibbar). Wareneinsatz = quantity × einzel_ek.
 */
class FoodAlchemistKalkulationPosition extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_calculation_positions';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'quantity' => 'decimal:3',
        'einzel_ek' => 'decimal:4',
        'work_time_min' => 'integer',
        'position' => 'integer',
    ];

    public function calculation(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistKalkulation::class, 'calculation_id');
    }

    /** @deprecated #486 deutscher Alias → calculation() */
    public function kalkulation(): BelongsTo
    {
        return $this->calculation();
    }

    /** Wareneinsatz dieser Position (Snapshot-Einzelpreis × Menge). */
    public function wareneinsatz(): float
    {
        return round((float) $this->quantity * (float) $this->einzel_ek, 4);
    }
}
