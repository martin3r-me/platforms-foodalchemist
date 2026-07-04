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

    protected $table = 'foodalchemist_menu_plans';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'start_date' => 'date',
        'zyklus_wochen' => 'integer',
        'min_abstand_tage' => 'integer',
    ];

    public function eintraege(): HasMany
    {
        return $this->hasMany(FoodAlchemistSpeiseplanEintrag::class, 'menu_plan_id')
            ->orderBy('entry_date')->orderBy('woche')->orderBy('wochentag')->orderBy('position');
    }

    public function linien(): HasMany
    {
        return $this->hasMany(FoodAlchemistSpeiseplanLinie::class, 'menu_plan_id')
            ->orderBy('sort_order')->orderBy('id');
    }
}
