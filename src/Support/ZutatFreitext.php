<?php

namespace Platform\FoodAlchemist\Support;

/**
 * Freie Zutatenbezeichnungen für den Bestands-Abgleich (Kurator/Dominique 27.10.): Internetrezept, Kundenzettel, Foto, Kochbuch, alte
 * GP-Namen. Tokens kommen bereits normalisiert aus der TokenEngine (klein, ä→ae, Satzzeichen weg); hier nur, was dort fehlt:
 *  - {@see lemma()}: Plural → Singular (Lorbeerblaetter → lorbeerblatt, Zwiebeln → zwiebel, Pfefferkoerner → pfefferkorn), §6.1
 *  - {@see varianten()}: Freitext ohne Menge/Einheit vorn, ohne Füllwörter („gutes“, „zum Braten“), Klammer einmal ohne, einmal als Wörter
 *  - {@see hauptzutatPasst()}: Schutz gegen einen Treffer mit anderer Hauptzutat (Apfelessig ≠ Apfelmus, Rübenkraut ≠ Goldsaft) — lieber offen
 */
final class ZutatFreitext
{
    private const EINHEITEN = 'g|gr|kg|mg|ml|cl|dl|l|ltr|el|tl|essl|teel|stk|st|stueck|stück|prise|prisen|msp|bund|zweig|zweige|zehe|zehen|scheibe|scheiben|dose|dosen|pck|packung|becher|glas|tasse|tassen|handvoll|blatt|blätter|blaetter|würfel|wuerfel';

    private const FUELLWOERTER = ['gut', 'gute', 'guter', 'gutes', 'guten', 'etwas', 'ca', 'circa', 'evtl', 'eventuell', 'reichlich', 'wenig', 'einige', 'beliebig'];

    /** Singular-/Grundform eines (TokenEngine-)Tokens; kurze Tokens bleiben. */
    public static function lemma(string $t): string
    {
        if (mb_strlen($t) > 4) {
            foreach (['en', 'er', 'n', 'e', 's'] as $endung) {
                if (str_ends_with($t, $endung) && ! ($endung === 's' && str_ends_with($t, 'ss'))) {   // Erdnuss bleibt erdnuss
                    $t = mb_substr($t, 0, -mb_strlen($endung));
                    break;
                }
            }
        }

        return strtr($t, ['ae' => 'a', 'oe' => 'o', 'ue' => 'u']);   // Umlaut-Plural: blaett → blatt, koern → korn
    }

    /**
     * Bereinigte Varianten des Freitexts (ohne das Original), in Prüfreihenfolge.
     *
     * @return list<string>
     */
    public static function varianten(string $text): array
    {
        $s = trim($text);
        $s = (string) preg_replace('/^\s*(?:ca\.?\s*)?[\d.,\/½¼¾\s-]+\s*(?:(?:' . self::EINHEITEN . ')\.?(?![\p{L}]))?\s*/iu', '', $s);   // „2 EL “, „500 g “
        $s = (string) preg_replace('/\s+(?:zum|zur|für|zu|nach)\s+.*$/iu', '', $s);                                               // „zum Braten“, „nach Geschmack“
        $s = (string) preg_replace('/(?<![\p{L}])(?:' . implode('|', self::FUELLWOERTER) . ')\.?(?![\p{L}])/iu', ' ', $s);
        $ohneKlammer = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/\([^)]*\)/u', ' ', $s)));
        $klammerAlsWort = trim((string) preg_replace('/\s+/u', ' ', strtr($s, ['(' => ' ', ')' => ' '])));
        // „Öl (Raps)“ → „Rapsöl“: ein Wort mit einem Bestimmungswort in Klammern ist das Kompositum
        $kompositum = preg_match('/^(\p{L}+)\s*\((\p{L}+)\)$/u', $s, $m) === 1 ? $m[2] . mb_strtolower($m[1]) : '';
        // „frisches Rapsöl“: ohne §9-Zustandswort (das Zustands-Gate prüft weiter gegen den Originaltext)
        $ohneZustand = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/(?<![\p{L}])(?:frisch|tiefgek(?:ü|ue)hlt|tk|trocken|getrocknet|konserviert)\p{L}*/iu', ' ', $ohneKlammer)));

        // Regelwerk Basisrezepte §2: Küchen-Verarbeitung („Schalotten, in Brunoise“, „Karotten geschnitten“) → Roh-Form
        $ohneVerarbeitung = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/(?<![\p{L}])(?:(?:in|als)\s+)?(?:brunoise|w(?:ü|ue)rfel\p{L}*|gew(?:ü|ue)rfelt|gehackt|geschnitten|gerieben|ger(?:ö|oe)stet|blanchiert|gestiftelt|gemahlen|p(?:ü|ue)riert|scheiben|streifen)(?![\p{L}])/iu', ' ', $ohneZustand)), " \t,");

        return array_values(array_unique(array_filter([$kompositum, $ohneKlammer, $klammerAlsWort, $ohneZustand, $ohneVerarbeitung], fn ($v) => $v !== '' && $v !== trim($text))));
    }

    /**
     * Passt die Hauptzutat des GP zur Zutat? Ja, wenn ein tragendes Wort einer Hauptzutat-Alternative („Rapsöl / Pflanzenöl“ → zwei) in der
     * Zutat vorkommt — gleich, als Wortanfang/-ende eines Kompositums (Pfeffer ⊂ Pfefferkörner, Mehl ⊂ Weizenmehl), gleiches Lemma oder
     * langer gemeinsamer Anfang. Apfelmus ↔ Apfelessig teilen nur „apfel“ (5 von 8) → nein.
     *
     * Gezählt wird nur das Kopfwort einer Alternative (erstes tragende Wort, ohne Füll-, Form-, Farb- und Eigenschaftswörter), damit ein
     * geteiltes Nebenwort nicht reicht: „Lachs-Filet“ ≠ „Kabeljaufilet“, „Hähnchenkeule ohne Knochen“ ≠ „Rehrücken ohne Knochen“.
     *
     * @param  list<string>  $zutatTokens   TokenEngine-Tokens der Zutat
     * @param  list<list<string>>  $alternativen  tragende TokenEngine-Tokens je Hauptzutat-Alternative des GP (Aufrufer filtert Form/Zustand)
     */
    public static function hauptzutatPasst(array $zutatTokens, array $alternativen): bool
    {
        $alternativen = array_values(array_filter($alternativen));
        if ($alternativen === [] || $zutatTokens === []) {
            return true;   // nichts zu prüfen
        }
        // Eine Hauptzutat mit Bindewort („Zwiebel in Aceto Balsamico“, „Karamell mit Fleur de Sel“) ist kein Kompositum — dort zählt nur das Kopfwort
        $alternativen = array_values(array_filter(array_map(fn ($alt) => ['phrase' => array_intersect($alt, self::BINDEWOERTER) !== [],
            'w' => array_values(array_filter($alt, fn ($t) => mb_strlen($t) >= 3 && ! in_array($t, self::NEBENWOERTER, true)))], $alternativen),
            fn ($a) => $a['w'] !== []));
        if ($alternativen === []) {
            return true;   // keine tragenden Wörter
        }
        foreach ($alternativen as ['phrase' => $phrase, 'w' => $alt]) {
            // Kopfwort = erstes tragende Wort („Lachs-Filet“ → lachs, „Pfeffer weiß“ → pfeffer); (a) dazu die Hauptzutat als ein Wort
            // („Rinder-Flank-Steak“ → rinderflanksteak ⊃ flanksteak, „Mangalitza-Speckfett“ ⊃ speckfett)
            foreach (array_unique($phrase ? [$alt[0]] : [$alt[0], implode('', $alt)]) as $i => $t) {
                foreach ($zutatTokens as $q) {
                    if (($i === 0 || mb_strlen($q) >= 6) && self::wortPasst($t, $q)) {   // Kompositum nur mit tragendem Wort (nicht „filet“ aus dem Decompound)
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private const BINDEWOERTER = ['in', 'im', 'mit', 'und', 'aus', 'auf', 'an', 'am', 'von', 'vom', 'nach'];

    /** Füll- und Farbwörter in Hauptzutaten („ohne Knochen und Haut“, „Bohnen weiß“, „Schwarze Olive“) — kein Kopfwort. */
    private const NEBENWOERTER = ['ohne', 'mit', 'und', 'oder', 'aus', 'von', 'vom', 'der', 'die', 'das', 'den', 'dem', 'des', 'nach', 'art',
        'weiss', 'weisse', 'weisser', 'schwarz', 'schwarze', 'schwarzer', 'rot', 'rote', 'roter', 'gruen', 'gruene', 'gruener', 'gelb', 'gelbe',
        'gelber', 'braun', 'braune', 'dunkel', 'dunkle', 'dunkler', 'hell', 'helle', 'heller', 'bunt', 'bunte', 'lila', 'blau', 'blaue',
        'saure', 'saurer', 'suesse', 'suesser', 'scharfe', 'scharfer', 'milde', 'milder', 'junge', 'junger', 'grosse', 'kleine', 'echte', 'echter'];

    private static function wortPasst(string $t, string $q): bool
    {
        [$lt, $lq] = [self::lemma($t), self::lemma($q)];
        if ($t === $q || $lt === $lq) {
            return true;
        }
        foreach ([[$t, $q], [$lt, $lq]] as [$a, $b]) {
            if (min(mb_strlen($a), mb_strlen($b)) >= 4 && (str_starts_with($b, $a) || str_starts_with($a, $b) || str_ends_with($b, $a) || str_ends_with($a, $b))) {
                return true;   // Kompositum: Pfeffer ⊂ Pfefferkoerner, Bohne ⊂ Delikatessbohnen, Mehl ⊂ Weizenmehl
            }
        }
        $min = min(mb_strlen($t), mb_strlen($q));
        $p = 0;
        while ($p < $min && mb_substr($t, $p, 1) === mb_substr($q, $p, 1)) {
            $p++;
        }

        return $p >= 6 && $p >= 0.75 * $min;
    }
}
