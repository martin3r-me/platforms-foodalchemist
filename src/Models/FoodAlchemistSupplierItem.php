<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;

/**
 * @ai.description Lieferantenartikel (Necta-Katalog) — konkreter Bestell-Artikel mit Gebinde + EANs.
 * 1 GP ↔ n Artikel via Structure-Schicht (GL-05); Preis-Historie in foodalchemist_prices (GL-11).
 */
class FoodAlchemistSupplierItem extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    /** Spec 82 §12 A3: globale Zeilen tragen eine Klasse (basis | stamm_paket). */
    public const STAMM_KLASSE = true;

    protected $table = 'foodalchemist_supplier_items';

    /**
     * Lieferbar = nicht von Hand ausgelistet UND nicht von Necta eingestellt (status 2 ohne Preis, 16.10.2026). „Läuft aus“ bleibt
     * lieferbar (letzter Preis gilt weiter).
     *
     * $vollscan (Suche „Artikel verknüpfen“, 27.10.): `is_discontinued <> 1` statt `= 0` — gleiche Zeilen (Spalte NOT NULL), aber MySQL nimmt dann nicht den
     * is_discontinued-Index, der bei ~100 % aktiven Artikeln fast die ganze Tabelle über PK-Lookups liest (io: ref, 176.841 rows) statt sie einmal zu scannen.
     */
    public function scopeLieferbar(\Illuminate\Database\Eloquent\Builder $q, bool $vollscan = false): \Illuminate\Database\Eloquent\Builder
    {
        $t = $q->getModel()->getTable();

        return $q->when($vollscan, fn ($w) => $w->whereRaw("{$t}.is_discontinued <> 1"), fn ($w) => $w->where("{$t}.is_discontinued", false))
            ->where(fn ($w) => $w->whereNull("{$t}.lieferstatus")->orWhere("{$t}.lieferstatus", '!=', 'eingestellt'));
    }

    /**
     * Lieferstatus aus Necta in Worten: „Eingestellt seit 14.10.2026“ bzw. „Läuft aus seit …“. `lieferstatus_seit` liegt in UTC
     * (wie status_valid_from), angezeigt wird das Datum in Europe/Berlin. null = gelistet.
     *
     * @return ?array{status:string, seit_ts:?int, seit:?string, text:string, kurz:string}
     */
    public function lieferstatusInfo(): ?array
    {
        $status = (string) $this->lieferstatus;
        if (! in_array($status, ['eingestellt', 'auslaufend'], true)) {
            return null;
        }
        $ts = $this->lieferstatus_seit !== null ? \Illuminate\Support\Carbon::parse($this->lieferstatus_seit, 'UTC')->getTimestamp() : null;
        $seit = $ts !== null ? \Illuminate\Support\Carbon::createFromTimestamp($ts, 'Europe/Berlin')->format('d.m.Y') : null;
        $wort = $status === 'eingestellt' ? 'Eingestellt' : 'Läuft aus';

        return ['status' => $status, 'seit_ts' => $ts, 'seit' => $seit, 'text' => $wort.($seit !== null ? ' seit '.$seit : ''),
            'kurz' => $wort.($seit !== null ? ' seit '.substr($seit, 0, 6) : '')];
    }

    /** Jod (15.10.2026): Ändert sich die Zutatenliste, wird `iodized_abgeleitet` neu abgeleitet (Eloquent-Pfade: UI, MCP, Datei-Import). */
    protected static function booted(): void
    {
        static::saving(function (self $item) {
            if (! $item->exists || $item->isDirty('ingredients_supplier')) {
                $item->iodized_abgeleitet = \Platform\FoodAlchemist\Support\JodAusZutaten::wert($item->ingredients_supplier);
            }
            // Mengen-Korrektur (16.10.2026): neu ableiten, wenn sich Bezeichnung, Menge, Stückzahl oder Einheit ändert
            if (! $item->exists || $item->isDirty(['designation', 'qty', 'qty_ordering_per_packaging', 'unit_code'])) {
                foreach (self::mengenKorrektur($item->designation, $item->qty, $item->qty_ordering_per_packaging, $item->unit_code) as $k => $v) {
                    $item->{$k} = $v;
                }
            }
        });
    }

    /**
     * Spalten der Mengen-Korrektur aus der Bezeichnung ({@see \Platform\FoodAlchemist\Support\MengeAusBezeichnung}).
     *
     * @return array{qty_korrigiert: ?float, qty_korrektur_quelle: ?string}
     */
    public static function mengenKorrektur(?string $bezeichnung, mixed $qty, mixed $stueck, ?string $einheit): array
    {
        $k = \Platform\FoodAlchemist\Support\MengeAusBezeichnung::korrektur($bezeichnung, $qty, $stueck, $einheit);

        return ['qty_korrigiert' => $k, 'qty_korrektur_quelle' => $k === null ? null : \Platform\FoodAlchemist\Support\MengeAusBezeichnung::QUELLE];
    }

    /** SQL der wirksamen Menge je Bestelleinheit für rohe Abfragen: korrigierte Menge vor Necta-qty. */
    public static function mengeSql(string $alias): string
    {
        return "COALESCE({$alias}.qty_korrigiert, {$alias}.qty)";
    }

    /** Wirksame Menge eines Artikels (Modell oder Abfrage-Zeile): `qty_korrigiert ?? qty`, NULL wenn keine. */
    public static function mengeVon(object $la): ?float
    {
        $wert = $la->qty_korrigiert ?? $la->qty ?? null;

        return $wert === null || $wert === '' ? null : (float) $wert;
    }

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'qty' => 'decimal:3',
        'qty_korrigiert' => 'decimal:4',
        'qty_ordering_per_packaging' => 'decimal:3',
        'is_organic' => 'boolean',
        'is_vegan' => 'boolean',
        'is_vegetarian' => 'boolean',
        'is_alcohol' => 'boolean',
        'is_discontinued' => 'boolean',
        'deposit' => 'decimal:2',
        // M2-16: nachgezogene Detail-Felder
        'is_halal' => 'boolean',
        'is_gmo_free' => 'boolean',
        'is_preorder' => 'boolean',
        'preorder_days' => 'integer',
        'vat' => 'decimal:2',
        'quota_qty_packs' => 'decimal:2',
        'quota_used_packs' => 'decimal:2',
        'quota_valid_from' => 'date',
        'quota_valid_to' => 'date',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistSupplier::class, 'supplier_id')->ohneTeamSicht('verweis');
    }

    public function structure(): HasOne
    {
        return $this->hasOne(FoodAlchemistSupplierItemStructure::class, 'supplier_item_id');
    }

    /** Non-Food-Urteil (Regel, KI oder von Hand); ohne Zeile gilt der Artikel als Food. */
    public function nonfood(): HasOne
    {
        return $this->hasOne(FoodAlchemistSupplierItemNonfood::class, 'supplier_item_id');
    }

    public function allergens(): HasOne
    {
        return $this->hasOne(FoodAlchemistItemAllergen::class, 'supplier_item_id');
    }

    public function declarations(): HasOne
    {
        return $this->hasOne(FoodAlchemistItemDeclaration::class, 'supplier_item_id');
    }

    public function nutritionals(): HasOne
    {
        return $this->hasOne(FoodAlchemistItemNutritional::class, 'supplier_item_id');
    }

    public function prices(): HasMany
    {
        return $this->hasMany(FoodAlchemistPrice::class, 'supplier_item_id');
    }
}
