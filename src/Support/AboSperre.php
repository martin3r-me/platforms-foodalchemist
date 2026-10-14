<?php

namespace Platform\FoodAlchemist\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 82 §8 (PR 6): ein ABONNIERTES Master-Objekt lässt sich nicht löschen, nur zurückziehen (AboKuendigungService::zurueckziehen). Für Rezepte steht die
 * Sperre in RecipeService::referenzen('abos'); hier dieselbe Regel für Paket, Konzept und Format.
 */
final class AboSperre
{
    /** Zahl aktiver Abos, deren Sammlung das Objekt enthält. @param 'recipe'|'paket'|'concept'|'format' $typ */
    public static function aktiveAbos(string $typ, int $id): int
    {
        if (! Schema::hasTable('foodalchemist_abos')) {
            return 0;
        }

        return DB::table('foodalchemist_sammlung_objekte AS o')->join('foodalchemist_abos AS a', 'a.sammlung_id', '=', 'o.sammlung_id')
            ->where('o.typ', $typ)->where('o.objekt_id', $id)->where('a.status', 'aktiv')->count();
    }

    /** @param 'paket'|'concept'|'format' $typ */
    public static function pruefeLoeschen(string $typ, int $id, string $was): void
    {
        $n = self::aktiveAbos($typ, $id);
        if ($n > 0) {
            throw new \RuntimeException(sprintf('%s wird von %d Abo(s) genutzt und lässt sich nicht löschen — nur zurückziehen (Bereich „Abos“, Spec 82 §8)%s.', $was, $n,
                $typ === 'format' ? ' bzw. aus der Sammlung nehmen' : ''));
        }
    }
}
