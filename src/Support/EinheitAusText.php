<?php

namespace Platform\FoodAlchemist\Support;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;

/**
 * Einheit aus freiem Text auf das Einheiten-Vokabular abbilden (Rezept-Import, Kurator 27.10.): „Stück“ landete als „g“, weil nur
 * der Slug („stk“) gesucht wurde und alles andere still auf Gramm fiel; „Zweig“ kam nur an, weil Slug und Wort gleich sind.
 * Reihenfolge: Slug → Anzeigename (ä/ö/ü/ß gleich ae/oe/ue/ss) → gängige Abkürzungen → Plural (Zweige, Zehen, Scheiben).
 * Kein Treffer ⇒ null; der Aufrufer entscheidet über den Rückfall.
 */
final class EinheitAusText
{
    /** Gängige Schreibweisen ohne eigenen Slug (normalisiert, ohne Punkt). */
    private const ALIAS = [
        'st' => 'stk', 'stck' => 'stk', 'stueck' => 'stk', 'stuecke' => 'stk', 'stk' => 'stk',
        'gr' => 'g', 'gramm' => 'g', 'kilo' => 'kg', 'kilogramm' => 'kg',
        'ltr' => 'l', 'liter' => 'l', 'milliliter' => 'ml', 'essloeffel' => 'el', 'teeloeffel' => 'tl', 'pck' => 'pck', 'packung' => 'pck',
    ];

    public static function id(Team $team, string $text): ?int
    {
        $norm = self::norm($text);
        if ($norm === '') {
            return null;
        }
        $einheiten = FoodAlchemistVocabEinheit::visibleToTeam($team)->get(['id', 'slug', 'display_de']);
        $nachSlug = $einheiten->keyBy(fn ($e) => self::norm((string) $e->slug));
        $nachName = $einheiten->keyBy(fn ($e) => self::norm((string) $e->display_de));
        foreach ([$norm, self::ALIAS[$norm] ?? null, ...self::singular($norm)] as $kandidat) {
            if ($kandidat === null || $kandidat === '') {
                continue;
            }
            $treffer = $nachSlug->get($kandidat) ?? $nachName->get($kandidat) ?? (isset(self::ALIAS[$kandidat]) ? $nachSlug->get(self::ALIAS[$kandidat]) : null);
            if ($treffer !== null) {
                return (int) $treffer->id;
            }
        }

        return null;
    }

    private static function norm(string $s): string
    {
        return strtr(rtrim(mb_strtolower(trim($s)), '.'), ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
    }

    /** @return list<string> Singular-Kandidaten (Zweige → zweig, Zehen → zehe, Scheiben → scheibe, Knollen → knolle). */
    private static function singular(string $s): array
    {
        $out = [];
        foreach (['en' => '', 'n' => '', 'e' => '', 's' => ''] as $endung => $ersatz) {
            if (mb_strlen($s) > mb_strlen($endung) + 2 && str_ends_with($s, $endung)) {
                $out[] = mb_substr($s, 0, -mb_strlen($endung)) . $ersatz;
            }
        }

        return $out;
    }
}
