<?php

namespace Platform\FoodAlchemist\Services\GpAbgleich;

use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\GpNamingService;

/**
 * Merkmals-Diff Artikel ↔ GP (Abgleich-Dienst, S4; Regelwerk §7.4a/§8.19/§6.6). Je Merkmal `gleich | abweichend | unbekannt`.
 * „abweichend“ nur, wenn beide Seiten einen Wert tragen und er verschieden ist — oder wenn eine Seite eine Geschmackszutat/einen Fantasienamen trägt,
 * die der anderen fehlt („Käse: Chili“ ≠ „Käse“). Unbekannt ist kein Widerspruch (Datenlücke).
 *
 * Geprüft werden: Zustand (frisch/TK/trocken/konserviert), Geschmack (flavor), Fantasiename (zusatz), Prozentwerte (Kakao, Frucht, vol, Fett — Fett nicht bei
 * Molkerei-Handelsklassen, §7.4a), Zucker (gezuckert/ungezuckert) und Bio.
 */
class MerkmalsDiff
{
    private const BASIS_ZUSTAENDE = ['frisch', 'TK', 'trocken', 'konserviert'];

    /** §5 Ausnahme 3 (v3.7.1): Merkmal der Rezeptur-Marke — eine Abweichung heißt „eigenes GP je Hersteller“ (GpAbgleichService::auswerten). */
    public const MERKMAL_REZEPTUR_MARKE = 'Marke (Rezepturware)';

    public function __construct(private readonly GpNamingService $naming) {}

    /**
     * @param  array<string,mixed>  $felder  Felder des Artikels (flavor, zusatz, condition, …) aus {@see LaFelderService}
     * @param  list<string>  $artikelTexte  Bezeichnung, Verkehrs-/Vermarktungsname, Zutaten (für Prozent/Zucker/Zusatz)
     * @return array{eintraege: list<array{merkmal: string, artikel: ?string, gp: ?string, urteil: string}>, abweichend: bool, abweichungen: list<string>}
     */
    public function vergleiche(array $felder, array $artikelTexte, bool $artikelBio, FoodAlchemistGp $gp): array
    {
        $text = mb_strtolower(implode(' | ', array_filter(array_map('trim', $artikelTexte))));
        if ($gp->istNonFood()) {
            return $this->vergleicheNonFood($text, $gp);
        }
        $gpFelder = $this->naming->felderAusName((string) $gp->name, true);
        $e = [];

        // Zustand
        $az = $this->naming->normalisiereZustand($felder['condition'] ?? null);
        $gz = $this->naming->normalisiereZustand($gp->condition ?: ($gpFelder['condition'] ?? null));
        $az = in_array($az, self::BASIS_ZUSTAENDE, true) ? $az : null;
        $gz = in_array($gz, self::BASIS_ZUSTAENDE, true) ? $gz : null;
        $e[] = $this->eintrag('Zustand', $az, $gz, $az !== null && $gz !== null ? ($az === $gz ? 'gleich' : 'abweichend') : 'unbekannt');

        // Geschmack
        $af = $this->norm((string) ($felder['flavor'] ?? ''));
        $gf = $this->norm((string) ($gp->flavor ?: ($gpFelder['flavor'] ?? '')));
        $e[] = $this->eintrag('Geschmack', $af ?: null, $gf ?: null, $af === '' && $gf === '' ? 'gleich' : ($af === $gf ? 'gleich' : 'abweichend'));

        // Fantasiename (Klammer-Zusatz): steht im GP ein Zusatz, muss er im Artikeltext vorkommen; trägt der Artikel einen, muss er im GP stehen
        $gzu = trim((string) ($gp->zusatz ?: ($gpFelder['zusatz'] ?? '')));
        $azu = trim((string) ($felder['zusatz'] ?? ''));
        if ($gzu !== '') {
            $e[] = $this->eintrag('Fantasiename', $azu ?: null, $gzu, mb_stripos($text, mb_strtolower($gzu)) !== false ? 'gleich' : 'abweichend');
        } elseif ($azu !== '') {
            $e[] = $this->eintrag('Fantasiename', $azu, null, 'abweichend');
        }

        // Prozentwerte (Kakao, Frucht, vol, Fett)
        foreach (['Kakao' => 'kakao', 'Frucht' => 'frucht', 'vol' => 'vol'] as $label => $wort) {
            $aw = $this->prozent($text, $wort);
            $gw = $this->prozent(mb_strtolower((string) $gp->name), $wort);
            if ($aw !== null || $gw !== null) {
                $e[] = $this->eintrag($label . '-%', $aw, $gw, $aw !== null && $gw !== null ? (abs((float) $aw - (float) $gw) < 0.01 ? 'gleich' : 'abweichend') : 'unbekannt');
            }
        }
        $gpMilch = ($gp->commodity_group_code === '06') && ! preg_match('/käse|kaese|cheese/iu', (string) $gp->name);   // Handelsklassen (§7.4a) — kein Widerspruch
        $afett = $this->prozent($text, 'fett');
        $gfett = $this->prozent(mb_strtolower((string) $gp->name), 'fett');
        if ($afett !== null || $gfett !== null) {
            $e[] = $this->eintrag('Fett-%', $afett, $gfett, $gpMilch || $afett === null || $gfett === null ? 'unbekannt' : (abs((float) $afett - (float) $gfett) < 0.01 ? 'gleich' : 'abweichend'));
        }

        // Zucker
        $azk = $this->zucker($text);
        $gzk = $this->zucker(mb_strtolower((string) $gp->name));
        if ($azk !== null || $gzk !== null) {
            $e[] = $this->eintrag('Zucker', $azk, $gzk, $azk !== null && $gzk !== null ? ($azk === $gzk ? 'gleich' : 'abweichend') : 'unbekannt');
        }

        // Bio
        // Getränke (WG 15, §5 Ausnahme 1 + §7.1a): Marke (seit 28.10. als Klammer im Namen), Einzelgebinde und Füllmenge sind Teil der Ware (vol-% steht oben)
        $getraenk = (string) ($felder['commodity_group_code'] ?? '') === '15' || (string) $gp->commodity_group_code === '15';
        if ($getraenk || self::istMarkenSuessware($felder)) {
            $marke = trim((string) ($felder['marke'] ?? ''));
            if ($marke !== '') {
                $imGp = str_contains($this->norm((string) $gp->name), $this->norm($marke));
                $e[] = $this->eintrag('Marke', $marke, $imGp ? $marke : null, $imGp ? 'gleich' : 'abweichend');
            }
        }
        if ($getraenk) {
            $af = $this->fuellmenge($text . ' ' . implode(' ', array_map('strval', [$felder['pflichtangabe'] ?? '', $felder['form'] ?? '', $felder['processing'] ?? ''])));
            $gf = $this->fuellmenge((string) $gp->name);
            if ($af !== null || $gf !== null) {
                $e[] = $this->eintrag('Gebinde/Füllmenge', $af, $gf, $af !== null && $gf !== null ? ($this->gleicheFuellmenge($af, $gf) ? 'gleich' : 'abweichend') : 'unbekannt');
            }
        }

        // §5 Ausnahme 3 (v3.7.1): bei Rezepturware unterscheiden Hersteller/Marke (Rezeptur und Allergene). Entscheidend ist die GP-SPALTE `rezepturware`,
        // nicht der Prompt; verglichen wird die Marke aus MarkeAusArtikel mit der Klammer des GP. Fehlt eine Seite: unbekannt (kein Teilen ohne Beleg).
        if ($gp->rezepturware === true) {
            $am = trim((string) ($felder['marke_regel']['marke'] ?? ''));
            $gm = trim((string) $gp->zusatz);
            if ($am !== '' || $gm !== '') {
                $e[] = $this->eintrag(self::MERKMAL_REZEPTUR_MARKE, $am !== '' ? $am : null, $gm !== '' ? $gm : null,
                    $am !== '' && $gm !== '' ? ($this->norm($am) === $this->norm($gm) ? 'gleich' : 'abweichend') : 'unbekannt');
            }
        }

        // Bio steht im Feld ODER (Bestands-GP ohne Backfill) als Marker im Namen („…, BIO“ / „/ (Bio)“)
        $gbio = mb_strtolower((string) $gp->bio) === 'bio' || ! empty($gpFelder['bio']) || preg_match('/(?<![\p{L}])bio(?![\p{L}])/iu', (string) $gp->name) === 1;
        $e[] = $this->eintrag('Bio', $artikelBio ? 'bio' : 'konventionell', $gbio ? 'bio' : 'konventionell', $artikelBio === $gbio ? 'gleich' : 'abweichend');

        $abw = array_values(array_filter($e, fn ($x) => $x['urteil'] === 'abweichend'));

        return [
            'eintraege' => $e,
            'abweichend' => $abw !== [],
            'abweichungen' => array_map(fn ($x) => $x['merkmal'] . ': Artikel ' . ($x['artikel'] ?? '—') . ' ≠ GP ' . ($x['gp'] ?? '—'), $abw),
        ];
    }

    /**
     * §5 Ausnahme 2 (Entscheid Dominique 25.10.2026): Markenartikel bei Süßwaren und Eis behalten die Marke im GP-Namen (Ferrero, KitKat, Mars, Langnese …).
     * Erkennung: Marke am Artikel UND Unterkategorie Süßwaren (WG 09) bzw. Eis (Unterkategorie/Hauptzutat). Rohware (Schokolade, Kuvertüre, Zucker) zählt nicht:
     * dafür gilt §5/§8.19 — die Unterkategorie „Süßwaren“ trägt nur den fertigen Markenartikel, Zutaten stehen in den Zucker-/Backzutaten-Gruppen.
     *
     * @param  array<string,mixed>  $felder
     */
    public static function istMarkenSuessware(array $felder): bool
    {
        if (trim((string) ($felder['marke'] ?? '')) === '') {
            return false;
        }
        $sub = mb_strtolower(trim((string) ($felder['sub_category'] ?? '')));
        $haupt = mb_strtolower(trim((string) ($felder['hauptzutat'] ?? '')));

        return str_contains($sub, 'süßwar') || str_contains($sub, 'suesswar')
            || preg_match('/(?<![\p{L}])(eis|speiseeis|eiscreme)(?![\p{L}])/u', $sub . ' ' . $haupt) === 1;
    }

    /** Non-Food (§8.18): nur Größe/Maß entscheidet; kein Zustand, Bio, Zucker, Allergene. */
    private function vergleicheNonFood(string $text, FoodAlchemistGp $gp): array
    {
        $gm = $this->masse((string) $gp->name);
        $am = $this->masse($text);
        $urteil = $gm !== [] && $am !== [] ? (array_intersect($gm, $am) !== [] ? 'gleich' : 'abweichend') : 'unbekannt';
        $e = [$this->eintrag('Größe', $am !== [] ? implode(' / ', $am) : null, $gm !== [] ? implode(' / ', $gm) : null, $urteil)];
        $abw = array_values(array_filter($e, fn ($x) => $x['urteil'] === 'abweichend'));

        return [
            'eintraege' => $e,
            'abweichend' => $abw !== [],
            'abweichungen' => array_map(fn ($x) => $x['merkmal'] . ': Artikel ' . ($x['artikel'] ?? '—') . ' ≠ GP ' . ($x['gp'] ?? '—'), $abw),
        ];
    }

    /** Maße mit Einheit, kanonisch in mm/ml/g („33 cm“ → „330 mm“, „0,9 l“ → „900 ml“). @return list<string> */
    private function masse(string $text): array
    {
        preg_match_all('/(\d+(?:[.,]\d+)?)\s*(mm|cm|ml|l|g|kg)(?![\p{L}])/iu', mb_strtolower($text), $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as $t) {
            $v = (float) str_replace(',', '.', $t[1]);
            [$v, $u] = match ($t[2]) {
                'cm' => [$v * 10, 'mm'], 'l' => [$v * 1000, 'ml'], 'kg' => [$v * 1000, 'g'], default => [$v, $t[2]],
            };
            $out[] = rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.') . ' ' . $u;
        }

        return array_values(array_unique($out));
    }

    /** Menge gleich UND (Gebinde-Wort gleich oder auf einer Seite nicht genannt — der Artikeltext nennt „Flasche“ oft nicht). */
    private function gleicheFuellmenge(string $a, string $b): bool
    {
        $ma = preg_match('/^(.*?)\s*([\d.]+ ml)$/u', $a, $x) === 1 ? [trim($x[1]), $x[2]] : ['', $a];
        $mb = preg_match('/^(.*?)\s*([\d.]+ ml)$/u', $b, $y) === 1 ? [trim($y[1]), $y[2]] : ['', $b];

        return $ma[1] === $mb[1] && ($ma[0] === $mb[0] || $ma[0] === '' || $mb[0] === '');
    }

    /** Einzelgebinde + Füllmenge eines Getränks („Flasche 0,33 l“ → „flasche 330 ml“); ohne Gebinde-Wort nur die Menge in ml/l. */
    private function fuellmenge(string $text): ?string
    {
        $t = mb_strtolower($text);
        if (preg_match('/(flasche|dose|fass|bag[- ]in[- ]box)?\s*(\d+(?:[.,]\d+)?)\s*(ml|l|cl)(?![\p{L}])/iu', $t, $m) !== 1) {
            return null;
        }
        $v = (float) str_replace(',', '.', $m[2]);
        $ml = match ($m[3]) {
            'l' => $v * 1000, 'cl' => $v * 10, default => $v,
        };

        return trim($m[1] . ' ' . rtrim(rtrim(number_format($ml, 1, '.', ''), '0'), '.') . ' ml');
    }

    /** @return array{merkmal: string, artikel: ?string, gp: ?string, urteil: string} */
    private function eintrag(string $merkmal, ?string $artikel, ?string $gp, string $urteil): array
    {
        return ['merkmal' => $merkmal, 'artikel' => $artikel, 'gp' => $gp, 'urteil' => $urteil];
    }

    private function norm(string $s): string
    {
        $s = mb_strtolower(trim($s));
        foreach (['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss'] as $a => $b) {
            $s = str_replace($a, $b, $s);
        }

        return trim((string) preg_replace('/[^a-z0-9]+/u', ' ', $s));
    }

    /** Prozentwert mit Bezugswort („45 % Fett“, „70 % Kakao“, „90 % Frucht“, „40 vol-%“), Dezimalkomma → Punkt; null = nicht genannt. */
    private function prozent(string $text, string $wort): ?string
    {
        if ($wort === 'vol') {
            if (preg_match('/(\d+(?:[.,]\d+)?)\s*vol\.?\s*-?\s*%/iu', $text, $m) === 1 || preg_match('/(\d+(?:[.,]\d+)?)\s*%\s*vol/iu', $text, $m) === 1) {
                return str_replace(',', '.', $m[1]);
            }

            return null;
        }
        if (preg_match('/(\d+(?:[.,]\d+)?)\s*%\s*(?:' . preg_quote($wort, '/') . ')/iu', $text, $m) === 1) {
            return str_replace(',', '.', $m[1]);
        }
        if (preg_match('/' . preg_quote($wort, '/') . '[a-zäöü]*\s*(?:anteil)?\s*(\d+(?:[.,]\d+)?)\s*%/iu', $text, $m) === 1) {
            return str_replace(',', '.', $m[1]);
        }

        return null;
    }

    private function zucker(string $text): ?string
    {
        if (preg_match('/ungezuckert|ungesüßt|ohne zucker|zuckerfrei|ohne zuckerzusatz/iu', $text) === 1) {
            return 'ungezuckert';
        }
        if (preg_match('/(?<![a-zäöü])gezuckert|(?<![a-zäöü])gesüßt|mit zucker/iu', $text) === 1) {
            return 'gezuckert';
        }

        return null;
    }
}
