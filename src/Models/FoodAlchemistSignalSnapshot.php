<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Signal-Snapshot (Spec 21 · E1) — ein gemessener Zähler zu einem
 * Lauf-Zeitpunkt: `metric_key` (Lücken-Metrik der Datenqualitäts-Ampel oder
 * SignalTyp), `count`, `measured_at`. Aus der Reihe entsteht der Trend je Befund
 * („340 → 290 → 252") und darauf das Drift-Meta-Signal. Reine Messung, nie eine
 * Fach-Entscheidung.
 *
 * Bewusst OHNE BelongsToTeamHierarchy: eine Zeitreihe gehört dem messenden Team,
 * Vererbung würde fremde Serien in den eigenen Trend mischen (s. Migration).
 */
class FoodAlchemistSignalSnapshot extends Model
{
    use HasUuidV7, LogsActivity, SoftDeletes;

    protected $table = 'foodalchemist_signal_snapshots';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'count' => 'integer',
        'severity_counts' => 'array',
        'measured_at' => 'datetime',
    ];

    /** Quelle: Lücken-Metrik der Ampel. */
    public const SOURCE_DQ = 'data-quality';

    /** Quelle: offene Signale je Typ (inkl. Detektor-Typen ohne Ampel-Metrik). */
    public const SOURCE_SIGNALS = 'signals';
}
