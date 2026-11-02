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
 * @ai.description Spec 94 · Pax-Paket eines Konfigurators: Gästebereich von–bis, Referenz-Pax (Standard Mitte) und je
 * Leistung eine feste Menge. Jedes Paket hat je Hauptkonzept einen eigenen All-in-Preis pro Person.
 */
class FoodAlchemistKonfiguratorPaket extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_konfigurator_pakete';

    protected $guarded = ['id'];

    protected $casts = [
        'von_pax' => 'integer',
        'bis_pax' => 'integer',
        'referenz_pax' => 'integer',
        'referenz_manuell' => 'boolean',
        'geschnuert_at' => 'datetime',
        'position' => 'integer',
    ];

    public function konfigurator(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistKonfigurator::class, 'konfigurator_id');
    }

    public function mengen(): HasMany
    {
        return $this->hasMany(FoodAlchemistKonfiguratorPaketMenge::class, 'paket_id');
    }

    /** Anzeige „100–199 Gäste“, wenn keine eigene Bezeichnung gesetzt ist. */
    public function anzeigeName(): string
    {
        return $this->bezeichnung ?: $this->von_pax . '–' . $this->bis_pax . ' Gäste';
    }
}
