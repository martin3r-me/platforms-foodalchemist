<?php

namespace Platform\FoodAlchemist\Support;

/**
 * Zusatz-Klammern hinter dem GP-Namen (GP-Regelwerk v3.10.0, Regel 94 „Zusatz-Klammern“, Entscheid Dominique 13.11.2026).
 *
 * Feste Reihenfolge, jede Klammer einzeln mit „ / “: `/ (Marke) / (Land) / (Bio) / (MSC) / (Vegan) / (Glutenfrei) / (Laktosefrei)`;
 * `(ASC)` steht am Platz von `(MSC)`. Die Marke (bzw. der Fantasiename) kommt aus dem Feld `zusatz`, das Land aus `herkunft_land`,
 * die übrigen aus den Namensmarkern `bio`, `msc`, `asc`, `vegan`, `glutenfrei`, `laktosefrei`.
 *
 * Herkunft (Regel 94 Nr. 4, 823): nur rohe Fleisch-Teilstücke, nur belegte Länder AUSSERHALB der EU. Deutschland, EU-Länder und „regional“
 * bleiben ohne Klammer — der Renderer schreibt sie trotzdem, wenn das Feld gesetzt ist; die Prüfung meldet es ({@see self::herkunftHinweis()}).
 */
final class GpNamensKlammern
{
    /** Marker-Klammern in Render-Reihenfolge nach der Länder-Klammer: Eingabe-Schlüssel => Klammertext. */
    public const MARKER = ['bio' => 'Bio', 'msc' => 'MSC', 'asc' => 'ASC', 'vegan' => 'Vegan', 'glutenfrei' => 'Glutenfrei', 'laktosefrei' => 'Laktosefrei'];

    /** EU-Mitgliedsstaaten (deutsche Kurzform) + „EU“, „Deutschland“, „regional“: nie eine Länder-Klammer (Regel 94 Nr. 4). */
    public const OHNE_KLAMMER = ['Deutschland', 'EU', 'Europa', 'regional', 'Belgien', 'Bulgarien', 'Dänemark', 'Estland', 'Finnland', 'Frankreich',
        'Griechenland', 'Irland', 'Italien', 'Kroatien', 'Lettland', 'Litauen', 'Luxemburg', 'Malta', 'Niederlande', 'Österreich', 'Polen',
        'Portugal', 'Rumänien', 'Schweden', 'Slowakei', 'Slowenien', 'Spanien', 'Tschechien', 'Ungarn', 'Zypern'];

    /**
     * Länder außerhalb der EU, die als Herkunft roher Teilstücke vorkommen (Startliste, nicht abschließend) — nur zum ZURÜCKLESEN einer Klammer
     * aus einem Namen ({@see self::istLand()}); gerendert wird jedes gesetzte `herkunft_land`.
     */
    public const LAENDER = ['Argentinien', 'Uruguay', 'USA', 'Kanada', 'Brasilien', 'Paraguay', 'Chile', 'Mexiko', 'Australien', 'Neuseeland',
        'Japan', 'Südafrika', 'Namibia', 'Botswana', 'Schottland', 'England', 'Großbritannien', 'Vereinigtes Königreich', 'Schweiz', 'Norwegen',
        'Island', 'Türkei', 'Ukraine', 'Färöer', 'Vietnam', 'Thailand', 'Indien', 'Ecuador', 'Peru'];

    /**
     * Die Klammern in fester Reihenfolge (ohne führendes „ / “).
     *
     * @param  array<string, mixed>  $in  Felder wie in GpNamingService::renderGpName (zusatz bereits bereinigt übergeben)
     * @return list<string>
     */
    public static function klammern(array $in, string $zusatz): array
    {
        $out = [];
        if ($zusatz !== '') {
            $out[] = '(' . $zusatz . ')';
        }
        $land = self::land($in['herkunft_land'] ?? null);
        if ($land !== '') {
            $out[] = '(' . $land . ')';
        }
        foreach (self::MARKER as $schluessel => $text) {
            if (! empty($in[$schluessel])) {
                $out[] = '(' . $text . ')';
            }
        }

        return $out;
    }

    /** Land bereinigt: ohne Klammern/Schrägstrich, getrimmt. */
    public static function land(mixed $wert): string
    {
        $l = trim((string) preg_replace('/[()\/\r\n]+/u', ' ', (string) $wert));

        return trim((string) preg_replace('/\s{2,}/u', ' ', $l));
    }

    /** Steht ein Klammerinhalt für ein Land (EU oder Nicht-EU)? Für das Zurücklesen aus dem Namen. */
    public static function istLand(string $inhalt): bool
    {
        $n = self::norm($inhalt);
        foreach ([...self::LAENDER, ...self::OHNE_KLAMMER] as $l) {
            if (self::norm($l) === $n && ! in_array($n, ['eu', 'europa', 'regional'], true)) {
                return true;
            }
        }

        return false;
    }

    /** Ist ein Klammerinhalt ein Namensmarker (Bio, MSC, ASC, Vegan, Glutenfrei, Laktosefrei)? */
    public static function istMarker(string $inhalt): bool
    {
        return in_array(mb_strtolower(trim($inhalt)), array_map('mb_strtolower', self::MARKER), true);
    }

    /**
     * Hinweis zur Länder-Klammer (Regel 94 Nr. 4) oder null: EU/Deutschland/regional nie; nur Fleisch (WG 04), nur rohe Teilstücke.
     *
     * @param  array<string, mixed>  $in
     */
    public static function herkunftHinweis(array $in): ?string
    {
        $land = self::land($in['herkunft_land'] ?? null);
        if ($land === '') {
            return null;
        }
        foreach (self::OHNE_KLAMMER as $l) {
            if (self::norm($l) === self::norm($land)) {
                return "Regel 94: Herkunft »{$land}« bleibt ohne Klammer — nur belegte Länder außerhalb der EU stehen im Namen.";
            }
        }
        $wg = (string) ($in['warengruppe'] ?? $in['commodity_group_code'] ?? '');
        if ($wg !== '' && $wg !== '04') {
            return 'Regel 94: Die Länder-Klammer steht nur bei rohen Fleisch-Teilstücken (Warengruppe 04).';
        }

        return null;
    }

    /** Hinweis zur Siegel-Klammer: MSC und ASC schließen sich aus; nur Fisch und Meeresfrüchte (WG 05). */
    public static function siegelHinweis(array $in): ?string
    {
        if (empty($in['msc']) && empty($in['asc'])) {
            return null;
        }
        if (! empty($in['msc']) && ! empty($in['asc'])) {
            return 'Regel 94: (MSC) und (ASC) stehen am selben Platz — nur eines der beiden Siegel.';
        }
        $wg = (string) ($in['warengruppe'] ?? $in['commodity_group_code'] ?? '');

        return $wg !== '' && $wg !== '05' ? 'Regel 94: (MSC)/(ASC) nur bei Fisch und Meeresfrüchten (Warengruppe 05), wenn der Artikel das Siegel belegt.' : null;
    }

    private static function norm(string $s): string
    {
        return strtr(mb_strtolower(trim($s)), ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
    }
}
