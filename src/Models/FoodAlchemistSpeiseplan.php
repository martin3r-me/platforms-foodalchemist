<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Speiseplan (M14) — dieselben Bausteine über eine Zeitachse
 * (Tag × Mahlzeit, Wochen-Zyklus). Zweite Ausgabeform neben dem Foodbook. team-eigen.
 */
class FoodAlchemistSpeiseplan extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_speiseplaene';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'start_datum' => 'date',
        'zyklus_wochen' => 'integer',
        'min_abstand_tage' => 'integer',
    ];

    public function eintraege(): HasMany
    {
        return $this->hasMany(FoodAlchemistSpeiseplanEintrag::class, 'speiseplan_id')
            ->orderBy('woche')->orderBy('wochentag')->orderBy('position');
    }
}
