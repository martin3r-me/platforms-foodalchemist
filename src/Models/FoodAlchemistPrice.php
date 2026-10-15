<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;

/**
 * @ai.description Preis-Zeile eines Lieferantenartikels (Necta, append-only-Historie im Ziel).
 * Kategorisierung (standard_ek/aktion/eingestellt/datenluecke) = GL-11 T1, lebt im Service.
 * Bewusst OHNE LogsActivity: Massendaten, Audit über Import-Reports.
 */
class FoodAlchemistPrice extends Model
{
    use HasUuidV7, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_prices';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'price' => 'decimal:4',
        'price_partial' => 'decimal:4',
        'valid_to' => 'datetime',
        'status_valid_from' => 'datetime',
        'is_blocked' => 'boolean',
        'change_date' => 'datetime',
        'creation_date' => 'datetime',
    ];

    /**
     * Spec 82 §12 A3: Die Klasse einer Preiszeile folgt dem Artikel (keine eigene Spalte).
     * Blendet Preise von globalen stamm_paket-Artikeln aus, wenn das Team kein Opt-in hat.
     * Preise teamgebundener Artikel und Team-Preise (team_id gesetzt) bleiben unberührt.
     *
     * Muss in jedem Lesepfad gesetzt werden, der Preise per Artikel-ID liest (activeFor,
     * activePriceSubquery, preisTrendBulk, historyFor, rohe SQL-Pfade) — sie prüfen die
     * Artikelsichtbarkeit nicht selbst.
     */
    public function scopeStammSichtbar(\Illuminate\Database\Eloquent\Builder $query, ?\Platform\Core\Models\Team $team): \Illuminate\Database\Eloquent\Builder
    {
        if (\Platform\FoodAlchemist\Support\StammPaket::sieheAlleLieferanten($team)) {
            return $query;
        }

        // Mit gebuchtem Paket: eigene Preise eines Teams (team_id gesetzt) sind nie Stamm und bleiben sichtbar; ohne Paket folgt der Preis dem Artikel.
        $eigene = \Platform\FoodAlchemist\Support\StammPaket::istFreigeschaltet($team);

        return $query->where(fn ($w) => ($eigene ? $w->whereNotNull($this->getTable() . '.team_id') : $w)->orWhereIn($this->getTable() . '.supplier_item_id', function ($sub) use ($team) {
            $sub->select('id')->from('foodalchemist_supplier_items');
            \Platform\FoodAlchemist\Support\StammPaket::begrenzen($sub, 'foodalchemist_supplier_items', $team);
        }));
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistSupplierItem::class, 'supplier_item_id');
    }
}
