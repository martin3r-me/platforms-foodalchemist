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
 * Trendhierarchie (Mode → Konsum-/Branchentrend → Megatrend → Metatrend), Kategorie = was (Food, Getränke,
 * Ambiente & Deko, Service & Format), Sparten = für wen (Event & Bankett, Betriebsgastronomie, Care, Bildung,
 * Restaurant & Hotel, Delivery; leer = alle), Status von gesichtet bis in Umsetzung. Konfidenz aus den Belegen.
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
        'manuell_felder' => 'array',          // Spec 93 C: von Hand gesetzte Felder — die Automatik fasst sie nie an
        'automatik_ereignisse' => 'array',    // Spec 93 C: letzte Ereignisse der Automatik (Quelle der Signale)
        'ableitung' => 'array',               // Spec 93 D: „Was heißt das für uns“ (Kurzfazit, Bedeutung je Sparte, Impulse)
        'ableitung_at' => 'datetime',
        'hashtags' => 'array',
        'sparten' => 'array',
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
