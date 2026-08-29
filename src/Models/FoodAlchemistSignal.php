<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Enums\SignalSeverity;
use Platform\FoodAlchemist\Enums\SignalStatus;
use Platform\FoodAlchemist\Enums\SignalTyp;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Signal (#378) — eine detektierte Auffälligkeit (Klasse B) im
 * „Signale"-Modul: Preis-Anomalie, veraltete Preise, Marge unter Ziel, Datenqualität
 * GP/LA. Trägt Severity + Lifecycle (offen|erledigt|ignoriert) + dedup_key (kein
 * Dauerfeuer) + optionalen Objekt-Bezug (ref_type/ref_id) + payload. team-eigen.
 */
class FoodAlchemistSignal extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_signals';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'type' => SignalTyp::class,
        'severity' => SignalSeverity::class,
        'status' => SignalStatus::class,
        'payload' => 'array',
        'erledigt_at' => 'datetime',
        'ignoriert_at' => 'datetime',
        // V-009 (22·H4a): Wiederkehr-Historie. `last_seen_at` ist bei Alt-Zeilen NULL —
        // nicht auf `created_at` geraten, s. Migration 2026_07_28_000004.
        'last_seen_at' => 'datetime',
        'seen_count' => 'integer',
        // Ebene 2: die Betriebs-Lane. NULL = Team-Core, sonst der Betrieb.
        'outlet_id' => 'integer',
    ];

    public function scopeOffen(Builder $q): Builder
    {
        return $q->where('status', SignalStatus::Offen->value);
    }

    public function scopeTyp(Builder $q, string $typ): Builder
    {
        return $q->where('type', $typ);
    }

    public function outlet(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(FoodAlchemistOutlet::class, 'outlet_id');
    }

    /**
     * Ebene 2 — die Betriebsbrille als Lane-Filter. Ohne Betrieb (Team-Core-Brille) zeigt sie
     * nur die NULL-Lane; mit Betrieb dessen Lane PLUS die immer sichtbare Team-Core-Lane
     * (betriebs-unabhängige Signale — Artikel/Hygiene/Rezept — liegen dort und gelten überall).
     */
    public function scopeLane(Builder $q, ?FoodAlchemistOutlet $outlet): Builder
    {
        return $q->where(function (Builder $inner) use ($outlet) {
            $inner->whereNull('outlet_id');
            if ($outlet !== null) {
                $inner->orWhere('outlet_id', $outlet->id);
            }
        });
    }
}
