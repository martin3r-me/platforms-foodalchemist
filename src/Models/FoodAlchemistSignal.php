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
        'typ' => SignalTyp::class,
        'severity' => SignalSeverity::class,
        'status' => SignalStatus::class,
        'payload' => 'array',
        'erledigt_at' => 'datetime',
        'ignoriert_at' => 'datetime',
    ];

    public function scopeOffen(Builder $q): Builder
    {
        return $q->where('status', SignalStatus::Offen->value);
    }

    public function scopeTyp(Builder $q, string $typ): Builder
    {
        return $q->where('typ', $typ);
    }
}
