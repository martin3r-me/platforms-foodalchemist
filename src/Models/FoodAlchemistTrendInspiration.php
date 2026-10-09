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
 * @ai.description Inspiration im Trendradar (Spec 79): ein Thema auf der Pinnwand mit beliebig vielen Quellen
 * (Instagram-Post, Video, Artikel, Foto — je ein Fundstück-Beleg). Noch kein Trend; kann einem Trend zugeordnet
 * oder zu einem gemacht werden, dann werden alle Quellen dessen Belege.
 */
class FoodAlchemistTrendInspiration extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_trend_inspirationen';

    protected $guarded = ['id'];

    protected $casts = ['uuid' => 'string', 'schlagworte' => 'array'];

    public function quellen(): HasMany
    {
        return $this->hasMany(FoodAlchemistTrendBeleg::class, 'inspiration_id')->orderBy('id');
    }

    public function trend(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistTrend::class, 'trend_id');
    }
}
