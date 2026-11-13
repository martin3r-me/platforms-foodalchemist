<?php

namespace Platform\FoodAlchemist\Support;

/**
 * Plural-Ausnahmen der Singular-Pflicht (GP-Regelwerk §6.1 Teil 2, Entscheid 29.05.2026, bestätigt 13.11.2026; Nachtrag Dominique 13.11. abends):
 * Sammelware bleibt in der Mehrzahl — Eier, Hülsenfrüchte (in jedem Zustand), Nüsse & Kerne (inkl. Samen), Beeren, dazu Kapern und Oliven.
 *
 * Geschlossene Startliste (Einzahl => Mehrzahl). Was nicht draufsteht, wird NICHT geraten: weder singularisiert noch pluralisiert — es geht auf
 * die Prüfliste. Eine Singularisierung (Bereinigungs-Lauf „§6.1 Plural“) darf Sammelware nie anfassen ({@see self::verbotenerSingular()}).
 */
final class GpSammelware
{
    /** Einzahl => Mehrzahl, Gruppen laut §6.1 Teil 2/3. */
    public const LISTE = [
        // Eier
        'Ei' => 'Eier', 'Hühnerei' => 'Hühnereier', 'Wachtelei' => 'Wachteleier',
        // Hülsenfrüchte (jeder Zustand)
        'Linse' => 'Linsen', 'Belugalinse' => 'Belugalinsen', 'Kichererbse' => 'Kichererbsen', 'Kidneybohne' => 'Kidneybohnen',
        'Weiße Bohne' => 'Weiße Bohnen', 'Schwarze Bohne' => 'Schwarze Bohnen', 'Borlottibohne' => 'Borlottibohnen', 'Limabohne' => 'Limabohnen',
        'Mungbohne' => 'Mungbohnen', 'Sojabohne' => 'Sojabohnen', 'Edamame' => 'Edamame',
        // Nüsse & Kerne, Samen
        'Erdnuss' => 'Erdnüsse', 'Mandel' => 'Mandeln', 'Walnuss' => 'Walnüsse', 'Walnusskern' => 'Walnusskerne', 'Haselnuss' => 'Haselnüsse',
        'Haselnusskern' => 'Haselnusskerne', 'Pinienkern' => 'Pinienkerne', 'Cashewkern' => 'Cashewkerne', 'Cashewnuss' => 'Cashewnüsse',
        'Pistazie' => 'Pistazien', 'Pistazienkern' => 'Pistazienkerne', 'Sonnenblumenkern' => 'Sonnenblumenkerne', 'Kürbiskern' => 'Kürbiskerne',
        'Macadamianuss' => 'Macadamianüsse', 'Pekannuss' => 'Pekannüsse', 'Paranuss' => 'Paranüsse',
        'Chiasamen' => 'Chiasamen', 'Leinsamen' => 'Leinsamen', 'Sesamsamen' => 'Sesamsamen', 'Hanfsamen' => 'Hanfsamen', 'Fenchelsamen' => 'Fenchelsamen',
        // Beeren
        'Erdbeere' => 'Erdbeeren', 'Himbeere' => 'Himbeeren', 'Heidelbeere' => 'Heidelbeeren', 'Brombeere' => 'Brombeeren',
        'Johannisbeere' => 'Johannisbeeren', 'Preiselbeere' => 'Preiselbeeren', 'Stachelbeere' => 'Stachelbeeren', 'Cranberry' => 'Cranberries',
        // Nachtrag 13.11.2026
        'Kaper' => 'Kapern', 'Olive' => 'Oliven',
    ];

    /**
     * Ist die Hauptzutat Sammelware (Einzahl oder Mehrzahl, auch mit Sorte/Farbe dahinter: „Oliven grün“, „Linsen rot“)?
     * Maßgeblich ist das erste Wort bzw. die ersten zwei Wörter („Weiße Bohnen“).
     */
    public static function ist(string $hauptzutat): bool
    {
        return self::treffer($hauptzutat) !== null;
    }

    /**
     * Steht Sammelware in der EINZAHL im Namen? Dann die richtige Mehrzahl-Form der Hauptzutat, sonst null.
     * „Walnuss“ → „Walnüsse“, „Olive grün“ → „Oliven grün“; „Walnüsse“ → null.
     */
    public static function mehrzahlStatt(string $hauptzutat): ?string
    {
        $t = self::treffer($hauptzutat);
        if ($t === null || $t['form'] !== 'einzahl' || $t['einzahl'] === $t['mehrzahl']) {
            return null;
        }

        return $t['mehrzahl'] . $t['rest'];
    }

    /**
     * Würde eine Umbenennung Sammelware von der Mehrzahl in die Einzahl setzen (verbotene Singularisierung, §6.1)?
     * Vergleicht die Hauptzutaten (Teil vor dem Doppelpunkt) von altem und neuem Namen.
     */
    public static function verbotenerSingular(string $alterName, string $neuerName): bool
    {
        $alt = self::treffer(self::hauptzutat($alterName));
        $neu = self::treffer(self::hauptzutat($neuerName));

        return $alt !== null && $neu !== null && $alt['form'] === 'mehrzahl' && $neu['form'] === 'einzahl' && $neu['einzahl'] !== $neu['mehrzahl'];
    }

    private static function hauptzutat(string $name): string
    {
        return trim((string) strstr($name . ':', ':', true));
    }

    /** @return array{einzahl: string, mehrzahl: string, form: string, rest: string}|null */
    private static function treffer(string $hauptzutat): ?array
    {
        $h = trim($hauptzutat);
        if ($h === '') {
            return null;
        }
        $n = self::norm($h);
        $best = null;
        foreach (self::LISTE as $einzahl => $mehrzahl) {
            foreach (['mehrzahl' => $mehrzahl, 'einzahl' => $einzahl] as $form => $wort) {
                $w = self::norm($wort);
                if (($n === $w || str_starts_with($n, $w . ' ')) && ($best === null || mb_strlen($w) > $best['len'])) {
                    $best = ['einzahl' => $einzahl, 'mehrzahl' => $mehrzahl, 'form' => $form, 'len' => mb_strlen($w),
                        'rest' => self::restNachWoertern($h, count(preg_split('/\s+/u', trim($wort)) ?: []))];
                }
            }
        }
        if ($best === null) {
            return null;
        }
        unset($best['len']);

        return $best;
    }

    /** Rest des Texts nach den ersten $anzahl Wörtern, mit führendem Leerzeichen („Olive grün“ → „ grün“). */
    private static function restNachWoertern(string $text, int $anzahl): string
    {
        $woerter = preg_split('/\s+/u', trim($text)) ?: [];
        $rest = array_slice($woerter, $anzahl);

        return $rest === [] ? '' : ' ' . implode(' ', $rest);
    }

    private static function norm(string $s): string
    {
        return strtr(mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $s))), ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
    }
}
