<?php

namespace Platform\FoodAlchemist\Support;

/**
 * Jod aus der Zutatenliste des Lieferanten ableiten (15.10.2026). Necta `declarationIodhTlig` ist auf foodalchemist.io durchweg 0
 * (4.003 von 4.003 Stichproben), Jod steht aber in der Zutatenliste: dort ist jodiertes Speisesalz als solches zu benennen.
 *
 * Muster gemessen an den 39.764 Zutatentexten der WaWi-Kopie (2.180 mit „jod“/„iod“), nicht erfunden:
 *  - ja: jodiert(es) / iodiert inkl. Tippfehler (jodietes, jodiertets), Jodsalz/Speisejodsalz, Kalium-/Natriumjodat/-jodid/-iodat
 *    (Schreibfehler -jodad, -jodaat, -jodit, Kalimjodat), Abkürzung „jod.“ vor bzw. nach Salz, „Jodzusatz“, niederländisch
 *    „gejodeerd“, beim Salz selbst „Jod (15-25mg/kg)“ / „Jod (zugefügt als KIO3)“.
 *  - nein: unjodiert (auch angeklebt „Speisesalzunjodiert“), jodfrei, ohne Jod, nicht jodiert.
 *  - keine Aussage: „Jod“ in Mineralstoff-Aufzählungen (Nahrungsergänzung), Algen-Warnhinweis, Fehltreffer wie „Diglycerioden“,
 *    „biodynamisch“, „citriodora“.
 * Ein Artikel mit jodierter UND unjodierter Komponente gilt als jodiert (Vorsorge, wie ALL-MAXIMAL).
 */
final class JodAusZutaten
{
    /** Rohwert wie die Deklarations-Spalten: 3 = ja, 1 = nein. */
    public const JA = 3;

    public const NEIN = 1;

    private const NEGATIV = [
        '/unjodiert\p{L}*/u',
        '/jodfrei\p{L}*/u',
        '/ohne\s+jod\b/u',
        '/nicht\s+[ji]odiert\p{L}*/u',
    ];

    private const POSITIV = [
        '/[ji]odi(?:er|e)t/u',                                         // jodiert, iodiert, jodietes, jodiertets
        '/jodsalz/u',                                                   // Jodsalz, Speisejodsalz
        '/(?:kal\p{L}{0,3}m|natrium)\s*-?\s*[ji]od(?:at|aat|ad|id|it)\b/u',   // Kaliumjodat, Kaliumiodid, Kalimjodat, Natriumjodat
        '/\bjod\.\s*(?:speise|koch|nitrit|pökel|meer|salz)/u',          // jod. Speisesalz, jod.Nitritpökelsalz
        '/salz\s+jod\./u',                                              // Salz jod., Speisesalz jod.
        '/jodzusatz/u',
        '/gejodeerd/u',
        '/\bjod\s*\((?:[^)]*mg\/kg|[^)]*kio3|zugef)/u',                 // Jod (15-25mg/kg), Jod (zugefügt als KIO3)
        '/\bkio3\b/u',
    ];

    /** @return int|null 3 = jodiert, 1 = ausdrücklich nicht jodiert, NULL = keine Aussage (auch ohne Zutatenliste) */
    public static function wert(?string $zutaten): ?int
    {
        if ($zutaten === null || trim($zutaten) === '') {
            return null;
        }
        // Nur echte Tags entfernen: strip_tags() schnitt bei „Vanillezucker <1%“ den ganzen Rest ab (445 lokale Texte mit „<“)
        $text = mb_strtolower((string) preg_replace('~</?[a-z][^<>]*>~i', ' ', str_replace('#', ' ', $zutaten)));
        $ohneNegativ = (string) preg_replace(self::NEGATIV, ' ', $text, -1, $negativ);
        foreach (self::POSITIV as $muster) {
            if (preg_match($muster, $ohneNegativ) === 1) {
                return self::JA;
            }
        }

        return $negativ > 0 ? self::NEIN : null;
    }
}
