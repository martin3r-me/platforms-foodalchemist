<?php

namespace Platform\FoodAlchemist\Services\Necta;

use Illuminate\Support\Facades\DB;

/**
 * „Nicht wieder fragen“ (PR 3): Hat jemand einen Prüffall mit „FA behalten“ / „aktiv lassen“ entschieden (status verworfen), legt der
 * nächste Lauf für DENSELBEN Necta-Wert keinen neuen offenen Fall an. Liefert Necta einen anderen Wert, wird wieder gefragt.
 * Gleiche Regel wie beim Produktpass-Vorrang ({@see NectaAbgleichService} passAbweichung). Vergleich als Text — so, wie der Lauf
 * necta_wert schreibt.
 */
final class NectaPruefallSperre
{
    public static function schonVerworfen(string $art, string $objekt, int $legacy, ?string $feld, ?string $nectaWert): bool
    {
        return DB::table('foodalchemist_necta_pruefaelle')->where('art', $art)->where('objekt', $objekt)->where('legacy_id', $legacy)
            ->where(fn ($q) => $feld === null ? $q->whereNull('feld') : $q->where('feld', $feld))
            ->where('status', 'verworfen')
            ->where(fn ($q) => $nectaWert === null ? $q->whereNull('necta_wert') : $q->where('necta_wert', $nectaWert))
            ->exists();
    }
}
