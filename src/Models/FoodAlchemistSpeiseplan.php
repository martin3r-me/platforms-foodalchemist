<?php

namespace Platform\FoodAlchemist\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;
use Platform\FoodAlchemist\Models\Concerns\HatAusgabeStatus;

/**
 * @ai.description Speiseplan (M14) — dieselben Bausteine über eine Zeitachse
 * (Tag × Mahlzeit, Wochen-Zyklus). Zweite Ausgabeform neben dem Foodbook. team-eigen.
 */
class FoodAlchemistSpeiseplan extends Model
{
    use HasUuidV7, HatAusgabeStatus, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_menu_plans';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'start_date' => 'date',
        'cycle_weeks' => 'integer',
        'min_abstand_tage' => 'integer',
        'default_pax' => 'integer',
        'budget_wareneinsatz' => 'float',
    ];

    public function entries(): HasMany
    {
        return $this->hasMany(FoodAlchemistSpeiseplanEintrag::class, 'menu_plan_id')
            ->orderBy('entry_date')->orderBy('week')->orderBy('weekday')->orderBy('position');
    }

    // ── Gültigkeitsfenster (Spec 33 · P1) ────────────────────────────────────

    /**
     * Der Speiseplan hat KEINE `gueltig_von`/`gueltig_bis`-Spalten — und soll auch keine
     * bekommen. Sein Fenster steht bereits in den Einträgen: der erste und der letzte
     * `entry_date`. Zwei Wahrheiten nebeneinander (gepflegtes Fenster vs. tatsächliche
     * Belegung) würden garantiert auseinanderlaufen, sobald jemand einen Eintrag verschiebt.
     *
     * **N+1-Schutz:** wenn der Aufrufer die Aggregate eager lädt
     * (`->withMin('entries', 'entry_date')->withMax('entries', 'entry_date')`), werden sie
     * benutzt. Sonst kostet es eine Abfrage je Plan — in einer Liste ist das Eager-Loading
     * darum Pflicht (der PortfolioService macht es).
     */
    public function gueltigVon(): ?CarbonInterface
    {
        return $this->fensterAusEintraegen('entries_min_entry_date', 'min');
    }

    public function gueltigBis(): ?CarbonInterface
    {
        return $this->fensterAusEintraegen('entries_max_entry_date', 'max');
    }

    private function fensterAusEintraegen(string $aggregatSpalte, string $richtung): ?CarbonInterface
    {
        $wert = $this->attributes[$aggregatSpalte] ?? null;

        if ($wert === null && ! array_key_exists($aggregatSpalte, $this->attributes)) {
            // Nicht eager geladen — nachschlagen. `exists`-Guard, damit ein frisch
            // instanziiertes Model (ohne id) keine sinnlose Abfrage auslöst.
            $wert = $this->exists ? $this->entries()->reorder()->{$richtung}('entry_date') : null;
        }

        return ($wert === null || $wert === '') ? null : Carbon::parse((string) $wert);
    }

    /** @deprecated #486 deutscher Alias → entries() */
    public function eintraege(): HasMany
    {
        return $this->entries();
    }

    public function lines(): HasMany
    {
        return $this->hasMany(FoodAlchemistSpeiseplanLinie::class, 'menu_plan_id')
            ->orderBy('sort_order')->orderBy('id');
    }

    /** @deprecated #486 deutscher Alias → lines() */
    public function linien(): HasMany
    {
        return $this->lines();
    }
}
