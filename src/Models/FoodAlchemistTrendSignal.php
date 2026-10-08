<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Messung einer externen Quelle zu einem Trend (Spec 79), heute Google Trends über DataForSEO:
 * Suchinteresse 0–100 über die Zeit, Richtung, Spitze und Kosten der Abfrage.
 */
class FoodAlchemistTrendSignal extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_trend_signale';

    protected $guarded = ['id'];

    protected $casts = ['uuid' => 'string', 'werte' => 'array', 'spitze_am' => 'date', 'veraenderung' => 'float', 'kosten_usd' => 'float', 'spitze_ohne_sockel' => 'boolean'];
}
