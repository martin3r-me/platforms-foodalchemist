<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Beleg zu einem Trend oder — ohne Trend — Fundstück in der Inspirations-Pinnwand (Spec 79): eine Quelle (Marktforschung, Befragung, Instagram, Google Trends …)
 * mit Link, Notiz, Fundort, Datum und optional einer Datei (Screenshot, Foto, PDF).
 */
class FoodAlchemistTrendBeleg extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_trend_belege';

    protected $guarded = ['id'];

    protected $casts = ['uuid' => 'string', 'beobachtet_am' => 'date', 'anteil' => 'float', 'schlagworte' => 'array', 'fundstueck' => 'boolean'];

    public function trend(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistTrend::class, 'trend_id');
    }

    public function inspiration(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistTrendInspiration::class, 'inspiration_id');
    }
}
