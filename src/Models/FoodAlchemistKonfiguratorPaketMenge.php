<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;

/**
 * @ai.description Spec 94 · Menge einer Leistung in einem Pax-Paket: Ergebnis der Mengenregel oder Handwert (manuell).
 * `regel_anzahl` hält fest, was die Regel ergäbe. Konzept null = gilt für alle Hauptkonzepte.
 */
class FoodAlchemistKonfiguratorPaketMenge extends Model
{
    use BelongsToTeamHierarchy;

    protected $table = 'foodalchemist_konfigurator_paket_mengen';

    protected $guarded = ['id'];

    protected $casts = [
        'anzahl' => 'decimal:2',
        'regel_anzahl' => 'decimal:2',
        'manuell' => 'boolean',
    ];

    public function paket(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistKonfiguratorPaket::class, 'paket_id');
    }

    public function leistung(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistKonfiguratorLeistung::class, 'leistung_id');
    }
}
