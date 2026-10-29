<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * Spec 82 §3 / Schritt 4d — ein Preislisten-Import eines Mandanten (Vorgang des Teams, nicht vererbbar:
 * Lesart wie {@see FoodAlchemistBulkRun}, Zugriff strikt über team_id).
 */
class FoodAlchemistPriceImportRun extends Model
{
    use HasUuidV7, LogsActivity, SoftDeletes;

    public const ART_PREISLISTE = 'preisliste';

    public const ART_STAMMDATEN = 'stammdaten';

    /** Eigene Lieferanten-Datei: die Spalten wurden nicht erkannt, die Datei wartet auf die Zuordnung (1a2). */
    public const STATUS_ZUORDNUNG_NOETIG = 'zuordnung_noetig';

    protected $table = 'foodalchemist_price_import_runs';

    protected $guarded = ['id'];

    protected $casts = ['mapping' => 'array', 'counts' => 'array'];

    public function lines(): HasMany
    {
        return $this->hasMany(FoodAlchemistPriceImportLine::class, 'run_id');
    }
}
