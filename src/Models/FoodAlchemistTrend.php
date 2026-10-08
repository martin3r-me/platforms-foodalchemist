<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Trend im Trendradar (Spec 79, Modell nach Sarah Spork): Trend oder Hype, Ebene der
 * Trendhierarchie (Mode → Konsum-/Branchentrend → Megatrend → Metatrend), Kategorie (Food, Getränke, Deko,
 * Veranstaltungskonzepte), Status von gesichtet bis in Umsetzung. Die Konfidenz ergibt sich aus den Belegen.
 */
class FoodAlchemistTrend extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_trends';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'befragung_bestaetigt' => 'boolean',
        'befragung_anteil' => 'float',
        'suchbegriffe' => 'array',
        'hashtags' => 'array',
        'geprueft_at' => 'datetime',
    ];

    public function belege(): HasMany
    {
        return $this->hasMany(FoodAlchemistTrendBeleg::class, 'trend_id')->orderByDesc('beobachtet_am')->orderByDesc('id');
    }

    public function signale(): HasMany
    {
        return $this->hasMany(FoodAlchemistTrendSignal::class, 'trend_id')->orderByDesc('id');
    }

    /** Wirksame Konfidenz: manuelle Übersteuerung vor berechnetem Wert. */
    public function wirksameKonfidenz(): string
    {
        return $this->konfidenz_manuell ?: ($this->konfidenz ?: 'niedrig');
    }
}
