<?php

namespace Platform\FoodAlchemist\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasPresentation;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;
use Platform\FoodAlchemist\Models\Concerns\HatAusgabeStatus;
use Platform\FoodAlchemist\Models\Concerns\HatAusgabeZuordnung;

/**
 * @ai.description Speiseplan (M14) — dieselben Bausteine über eine Zeitachse
 * (Tag × Mahlzeit, Wochen-Zyklus). Zweite Ausgabeform neben dem Foodbook. team-eigen.
 */
class FoodAlchemistSpeiseplan extends Model
{
    use HasUuidV7, HatAusgabeStatus, HatAusgabeZuordnung, HasPresentation, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_menu_plans';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'start_date' => 'date',
        'cycle_weeks' => 'integer',
        'min_abstand_tage' => 'integer',
        'default_pax' => 'integer',
        'budget_wareneinsatz' => 'float',
        'opening_days' => 'array',
        'is_template' => 'boolean',
        'source_synced_at' => 'datetime',
        'vorgaben' => 'array',   // Spec 59: [{chip_id, mahlzeit|null, min|null, max|null}]
    ];

    /** Spec 57 · Paket 7: die Vorlage, aus der diese Betriebs-Kopie stammt. */
    public function sourcePlan(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(self::class, 'source_plan_id');
    }

    /** Spec 57 · Paket 7: die Betriebs-Kopien dieser Vorlage. */
    public function betriebsKopien(): HasMany
    {
        return $this->hasMany(self::class, 'source_plan_id');
    }

    /** Spec 57 · Paket 9: Standard-Öffnungstage, wenn nichts gepflegt ist (GV-Werktage). */
    public const OEFFNUNGSTAGE_STANDARD = [1, 2, 3, 4, 5];

    /**
     * Öffnungstage als ISO-Wochentage (1 = Mo … 7 = So), sortiert und eindeutig. Leer oder
     * unbrauchbar gepflegt → Mo–Fr, damit ein Plan nie „an keinem Tag“ geöffnet ist.
     *
     * @return list<int>
     */
    public function oeffnungstage(): array
    {
        $tage = array_values(array_unique(array_filter(
            array_map('intval', (array) ($this->opening_days ?? [])),
            fn (int $t) => $t >= 1 && $t <= 7,
        )));
        sort($tage);

        return $tage !== [] ? $tage : self::OEFFNUNGSTAGE_STANDARD;
    }

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

    /** Schreibstil für das KI-Wording der Einträge (wie Speisekarte). */
    public function writingStyle(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistWritingStyle::class, 'writing_style_id');
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
