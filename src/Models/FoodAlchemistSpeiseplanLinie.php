<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Speiseplan-Menü-Linie (M14-03) — Kantinen-/Kita-Achse je Speiseplan
 * (z. B. Menü 1, Vegetarisch, Vital, Dessert). Farbe für die Matrix-Zeile, ist_vegetarisch
 * fürs GV-Tagescheck. Pro Speiseplan frei definierbar (sort_order). team-eigen.
 */
class FoodAlchemistSpeiseplanLinie extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_menu_plan_lines';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'ist_vegetarisch' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function speiseplan(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistSpeiseplan::class, 'menu_plan_id');
    }

    public function eintraege(): HasMany
    {
        return $this->hasMany(FoodAlchemistSpeiseplanEintrag::class, 'line_id');
    }
}
