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
 * gericht|basisrezept|gp|frei. Bei Referenz-Typen sind label/einheit/einzel_ek/
 * arbeitszeit_min ein Snapshot der Quelle (überschreibbar). Wareneinsatz = menge × einzel_ek.
 */
class FoodAlchemistKalkulationPosition extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_kalkulation_positionen';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'menge' => 'decimal:3',
        'einzel_ek' => 'decimal:4',
        'arbeitszeit_min' => 'integer',
        'position' => 'integer',
    ];

    public function kalkulation(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistKalkulation::class, 'kalkulation_id');
    }

    /** Wareneinsatz dieser Position (Snapshot-Einzelpreis × Menge). */
    public function wareneinsatz(): float
    {
        return round((float) $this->menge * (float) $this->einzel_ek, 4);
    }
}
