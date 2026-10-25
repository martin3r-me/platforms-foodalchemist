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

    public function __construct(private readonly GpNamingService $naming) {}

    /**
     * @param  array<string,mixed>  $felder  Felder des Artikels (flavor, zusatz, condition, …) aus {@see LaFelderService}
     * @param  list<string>  $artikelTexte  Bezeichnung, Verkehrs-/Vermarktungsname, Zutaten (für Prozent/Zucker/Zusatz)
     * @return array{eintraege: list<array{merkmal: string, artikel: ?string, gp: ?string, urteil: string}>, abweichend: bool, abweichungen: list<string>}
     */
    public function vergleiche(array $felder, array $artikelTexte, bool $artikelBio, FoodAlchemistGp $gp): array
    {
        $text = mb_strtolower(implode(' | ', array_filter(array_map('trim', $artikelTexte))));
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
        $gbio = mb_strtolower((string) $gp->bio) === 'bio';
        $e[] = $this->eintrag('Bio', $artikelBio ? 'bio' : 'konventionell', $gbio ? 'bio' : 'konventionell', $artikelBio === $gbio ? 'gleich' : 'abweichend');

        $abw = array_values(array_filter($e, fn ($x) => $x['urteil'] === 'abweichend'));

        return [
            'eintraege' => $e,
            'abweichend' => $abw !== [],
            'abweichungen' => array_map(fn ($x) => $x['merkmal'] . ': Artikel ' . ($x['artikel'] ?? '—') . ' ≠ GP ' . ($x['gp'] ?? '—'), $abw),
        ];
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
            return preg_match('/(\d+(?:[.,]\d+)?)\s*vol\.?\s*-?\s*%/iu', $text, $m) === 1 ? str_replace(',', '.', $m[1]) : null;
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
