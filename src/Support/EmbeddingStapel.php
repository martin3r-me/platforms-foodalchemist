<?php

namespace Platform\FoodAlchemist\Support;

/**
 * Stapel-Grenze für Embedding-Requests — EINE Stelle für Wissen und Pool.
 *
 * Das Limit des Providers liegt bei 300k Tokens je Request (OpenAI). Wer dem Core-`embedAndStoreBatch` viele Einträge in einem Aufruf gibt,
 * läuft in HTTP 400 („Requested 987757 tokens, max 300000“; gemessen beim Import von 14.250 Wissens-Dossiers auf foodalchemist.io).
 * Geteilt wird deshalb VOR dem Core-Aufruf, konservativ nach Zeichen (~3–4 Zeichen je Token: 500k Zeichen ≈ 125–165k Tokens, sicherer Abstand)
 * plus Item-Obergrenze. Ein einzelner Eintrag über der Zeichengrenze bekommt seinen eigenen Stapel (nie verworfen, nie geteilt).
 */
final class EmbeddingStapel
{
    public const MAX_ZEICHEN = 500_000;

    public const MAX_EINTRAEGE = 1000;

    /**
     * @param  list<array{id:int,text:string,metadata?:array}>  $eintraege
     * @return list<list<array{id:int,text:string,metadata?:array}>>
     */
    public static function teilen(array $eintraege, int $maxZeichen = self::MAX_ZEICHEN, int $maxEintraege = self::MAX_EINTRAEGE): array
    {
        $stapel = [];
        $aktuell = [];
        $zeichen = 0;
        foreach ($eintraege as $eintrag) {
            $laenge = mb_strlen((string) ($eintrag['text'] ?? ''));
            if ($aktuell !== [] && ($zeichen + $laenge > $maxZeichen || count($aktuell) >= $maxEintraege)) {
                $stapel[] = $aktuell;
                $aktuell = [];
                $zeichen = 0;
            }
            $aktuell[] = $eintrag;
            $zeichen += $laenge;
        }
        if ($aktuell !== []) {
            $stapel[] = $aktuell;
        }

        return $stapel;
    }
}
