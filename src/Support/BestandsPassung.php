<?php

namespace Platform\FoodAlchemist\Support;

use Platform\FoodAlchemist\Services\Matching\TokenEngine;

/**
 * Spec 80 B3 — passt ein Bestands-Basisrezept FUNKTIONAL zu einer Zutatenzeile? Vorher reichte ein
 * Namens-/Score-Treffer: „Petersilienmatte" (Blattgrün zum Färben) landete auf „Garnitur: Kräutermatte
 * Petersilie (Vegan)" (Gel-Blatt), „Cremige Polenta" auf „Chip: Polenta", „Rote-Bete-Püree" auf
 * „Püree: Kartoffel-Rote-Bete", „Dashi-Beurre-blanc" auf „Beurre Blanc".
 *
 * Drei deterministische Prüfungen, in dieser Reihenfolge. Jede liefert einen Grund (für den Plan und das
 * Lauf-Protokoll) oder null:
 *  1. Typ — das Präfix des Kandidaten steht im Typ-Vokabular (§1.2, aus dem Wissensmodul) und gehört zur
 *     selben Hauptgruppe wie der Typ, den die Zeile verlangt. Ohne geladenes Vokabular entfällt die Prüfung.
 *  2. Bestandteile — der Hauptbestandteil des Kandidaten steht in der Zeile, und was die Zeile zusätzlich
 *     nennt (Variante: „Dashi"), steht im Kandidaten.
 *  3. Diät — ein Kandidat, der ausdrücklich nicht vegan/vegetarisch ist, passt nicht zu einer harten Diät.
 *     Unbekannt (null) blockiert nicht.
 */
final class BestandsPassung
{
    /**
     * @param  list<string>  $diaetHart  Lauf-Regler `diaet_hart`
     */
    public static function grund(string $zeile, string $kandidatName, ?bool $vegan = null, ?bool $vegetarisch = null, array $diaetHart = []): ?string
    {
        $zeile = trim($zeile);
        $kandidatName = trim($kandidatName);
        if ($zeile === '' || $kandidatName === '') {
            return null;   // nichts zu vergleichen (z. B. Bestandszug ohne Zeilentext) → wie bisher
        }

        return self::typGrund($zeile, $kandidatName)
            ?? self::bestandteilGrund($zeile, $kandidatName)
            ?? self::diaetGrund($vegan, $vegetarisch, $diaetHart);
    }

    private static function typGrund(string $zeile, string $kandidat): ?string
    {
        if (! RezeptTypVokabular::istGeladen()) {
            return null;
        }
        $praefix = RezeptTypVokabular::praefix($kandidat);
        if ($praefix !== null && RezeptTypVokabular::finde($praefix) === null) {
            return "Typ „{$praefix}“ steht nicht im Typ-Vokabular (§1.2) — Funktion nicht belegt";
        }
        $kandTyp = $praefix !== null ? RezeptTypVokabular::finde($praefix) : RezeptTypVokabular::typImText($kandidat);
        $zeileTyp = RezeptTypVokabular::typImText($zeile);
        if ($kandTyp === null || $zeileTyp === null || $kandTyp === $zeileTyp) {
            return null;
        }
        if (array_intersect(RezeptTypVokabular::gruppenVon($kandTyp), RezeptTypVokabular::gruppenVon($zeileTyp)) !== []) {
            return null;   // gleiche Hauptgruppe (Sauce/Hollandaise, Fond/Demi-Glace)
        }

        return "Typ „{$kandTyp}“ passt nicht zu „{$zeileTyp}“";
    }

    private static function bestandteilGrund(string $zeile, string $kandidat): ?string
    {
        $engine = app(TokenEngine::class);
        $zeileFlach = self::flach($zeile);
        $kandFlach = self::flach($kandidat);

        // (a) Hauptbestandteil des Kandidaten (erstes Wort der Bezeichnung) muss in der Zeile stehen.
        if (RezeptTypVokabular::praefix($kandidat) !== null) {
            $teile = $engine->tokenize(RezeptTypVokabular::bezeichnung($kandidat));
            $haupt = $teile[0] ?? null;
            if ($haupt !== null && mb_strlen($haupt) >= 3) {
                $stamm = $engine->stemGerman($haupt);
                if (! str_contains($zeileFlach, $stamm)) {
                    return "Hauptbestandteil „{$haupt}“ steht nicht in der Zeile";
                }
            }
        }

        // (b) Was die Zeile zusätzlich nennt (Variante), muss im Kandidaten stehen.
        $zeileTyp = RezeptTypVokabular::typImText($zeile);
        $typFlach = $zeileTyp !== null ? str_replace(' ', '', RezeptTypVokabular::norm($zeileTyp)) : null;
        foreach ($engine->tokenize(RezeptTypVokabular::bezeichnung($zeile)) as $wort) {
            if ($typFlach !== null && str_ends_with($wort, $typFlach)) {
                $wort = mb_substr($wort, 0, mb_strlen($wort) - mb_strlen($typFlach));   // „kalbsfond" → „kalbs"
            }
            $wort = preg_replace('/(s|n|en)$/u', '', $wort) ?? $wort;                   // Fugen-s/-n
            if (mb_strlen($wort) < 4 || self::istBeschreibung($wort)) {
                continue;
            }
            if (! str_contains($kandFlach, $engine->stemGerman($wort))) {
                return "„{$wort}“ aus der Zeile fehlt im Bestandsrezept (andere Variante)";
            }
        }

        return null;
    }

    /** @param  list<string>  $diaetHart */
    private static function diaetGrund(?bool $vegan, ?bool $vegetarisch, array $diaetHart): ?string
    {
        if (in_array('vegan', $diaetHart, true) && $vegan === false) {
            return 'nicht vegan (Diät-Vorgabe vegan)';
        }
        if (array_intersect(['vegan', 'vegetarisch'], $diaetHart) !== [] && $vegetarisch === false) {
            return 'nicht vegetarisch (Diät-Vorgabe ' . implode('/', array_intersect(['vegan', 'vegetarisch'], $diaetHart)) . ')';
        }

        return null;
    }

    /** Eigenschaftswörter beschreiben, sie unterscheiden keine Variante („cremig", „hausgemacht", „klassisch"). */
    private static function istBeschreibung(string $wort): bool
    {
        return preg_match('/(ig|lich|isch|iert|haft|bar|gemacht)(e|en|er|es|em)?$/u', $wort) === 1
            || in_array(preg_replace('/(e|er|es|en|em)$/u', '', $wort), [
                'frisch', 'fein', 'grob', 'hell', 'dunkel', 'klar', 'kalt', 'warm', 'heiss', 'braun', 'weiss',
                'gruen', 'rot', 'gelb', 'schwarz', 'basis', 'grund', 'rezept', 'klein', 'gross', 'leicht', 'kraeftig',
            ], true);
    }

    private static function flach(string $s): string
    {
        return str_replace(' ', '', RezeptTypVokabular::norm(preg_replace('/[^\p{L}\p{N}\s-]+/u', ' ', $s) ?? $s));
    }
}
