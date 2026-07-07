<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Standalone-Kalkulation (M-K10, Doc 16 §11) — eine benannte
 * Positionsliste (Composer/„Prüfung"), entkoppelt vom Concepter. HK1 = Σ Wareneinsatz
 * der Positionen; HK2 = + Settings-Zuschläge (mehrstufig). team-eigen.
 */
class FoodAlchemistKalkulation extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_calculations';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'margin_override_pct' => 'decimal:2',
    ];

    public function positionen(): HasMany
    {
        return $this->hasMany(FoodAlchemistKalkulationPosition::class, 'calculation_id')->orderBy('position');
    }
}
