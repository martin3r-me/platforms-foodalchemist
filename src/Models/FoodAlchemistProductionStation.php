<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Posten = Arbeitsplatz in der Küche mit einer Kapazität in Minuten pro Tag
 * (Spec 30 E3). Betriebsstammdaten, KEIN Vokabular: die Vokabular-Tabellen werden beim Import
 * geleert, und ihr `slug` ist global unique — Kapazität ist aber physisch, standortgebunden
 * und team-eigen.
 *
 * ⚠️ Wir planen POSTEN, nicht MENSCHEN. Keine Schichten, keine Verfügbarkeiten, keine
 * Personalstammdaten — „Touren- und Personalplanung" bleibt Nicht-Ziel des Moduls.
 * Der Verantwortliche an einer Auftragszeile ist ein freier Name ohne Aggregation.
 *
 * ⚠️ Geerbte Posten sind VORLAGEN, keine geteilte Ressource: die Auslastung wird immer
 * team-strikt gerechnet (`ProductionCapacityService`), nie über die Team-Kette summiert —
 * sonst blockierte die Produktion des Eltern-Betriebs die Posten des Kind-Betriebs.
 */
class FoodAlchemistProductionStation extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_production_stations';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'kapazitaet_min_pro_tag' => 'integer',
        'kapazitaet_wochentag' => 'array',
        'sort_order' => 'integer',
        'is_inactive' => 'boolean',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(FoodAlchemistProductionOrderLine::class, 'station_id');
    }

    /**
     * Kapazität an einem konkreten Tag in Minuten. `null` = dieser Posten plant nicht mit
     * Kapazität und warnt deshalb nie (opt-in je Posten — der wichtigste Anti-Nerv-Schalter).
     *
     * `kapazitaet_wochentag` enthält nur ABWEICHUNGEN (ISO 1=Mo…7=So); Samstag/Sonntag ist
     * im Catering der reale Sonderfall, eine eigene Tabelle dafür wäre Overkill.
     */
    public function kapazitaetAm(\DateTimeInterface $tag): ?int
    {
        $iso = (string) ((int) $tag->format('N'));
        $abweichung = ($this->kapazitaet_wochentag ?? [])[$iso] ?? null;

        if ($abweichung !== null) {
            return max(0, (int) $abweichung);
        }

        return $this->kapazitaet_min_pro_tag;
    }
}
