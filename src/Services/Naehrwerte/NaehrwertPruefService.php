<?php

namespace Platform\FoodAlchemist\Services\Naehrwerte;

/**
 * Nährwert-Prüfung je Lieferantenartikel (Plan 29.10.2026) — eine wiederverwendbare Schnittstelle für Aggregation, Trockenlauf,
 * Import, Necta, Handpflege und Portal: Artikelzeile (+ Geschwister desselben GP) → Befunde mit Regel, Schwere, Korrekturvorschlag.
 * Rechnet nur, schreibt nichts. Schwellen: config `foodalchemist.naehrwert_pruefung`.
 *
 * Zeile: Spalten aus foodalchemist_item_nutritionals (g/100 g, Natrium mg/100 g, kcal/kJ), fehlende Werte = null.
 * Kontext (optional): wg (Warengruppen-Code), sub (Unterkategorie), name (GP-Name), bezeichnung (Artikel).
 *
 * Regeln — hart (zählt nicht): H1 Wert unmöglich · H2 Summe > 100 g · H3 „davon“ > gesamt · H4 kcal passt nicht zu 4/4/9 ·
 * W1 Öl mit Fett < 90 g · W5 Wasser mit Energie/Fett/Zucker · A weicht von den Geschwistern ab. Hinweis (rechnet mit): H5 kJ ≠ 4,184 × kcal ·
 * W2 Öl mit Salz · W3 Gewürz mit Zucker > 40 g (Dominique 29.10.) · W4 Frischgemüse mit Fett · W6 Essig mit Fett/viel Energie.
 * Umrechnung: H6 Einheit — kcal passt NICHT zu den Roh-Makros, aber eindeutig zu Makros × 1000 bzw. × 100 (Dominique 29.10.:
 * automatisch je Artikel, nur bei eindeutiger Probe, generisch für alle Lieferanten). Alle übrigen Regeln prüfen die umgerechneten Werte.
 */
class NaehrwertPruefService
{
    /** Spalten, die die Prüfung liest. */
    public const FELDER = ['energy_kcal', 'energy_kj', 'fat', 'saturated_fat', 'carbs_absorbable', 'sugar', 'protein', 'sodium', 'roughage', 'alcohol'];

    /** Mengenspalten, die eine Einheiten-Umrechnung mitnimmt (alles außer der Energie). */
    public const MENGEN = ['fat', 'saturated_fat', 'carbs_absorbable', 'sugar', 'protein', 'sodium', 'roughage', 'alcohol'];

    /** H6 rechnet nur die Gramm-Felder um. Natrium (mg) steht bei den Elbfrost-Proben roh schon richtig (Veggie-Burger 440 mg, Pastinake 8 mg) — × 1000 machte daraus 20 g Salz je 100 g Pastinake (io 31.10.). */
    public const MENGEN_UMRECHNUNG = ['fat', 'saturated_fat', 'carbs_absorbable', 'sugar', 'protein', 'roughage', 'alcohol'];

    /**
     * Befunde EINES Artikels. `$geschwister` = die übrigen Artikelzeilen desselben GP (für Regel A); ohne sie entfällt A.
     *
     * @param  array<string, float|int|string|null>  $zeile
     * @param  list<array<string, float|int|string|null>>  $geschwister
     * @param  array{wg?: ?string, sub?: ?string, name?: ?string, bezeichnung?: ?string}  $kontext
     * @return list<NaehrwertBefund>
     */
    public function pruefe(array $zeile, array $geschwister = [], array $kontext = []): array
    {
        return $this->pruefeGruppe(array_merge([$zeile], $geschwister), $kontext, nurIndex: 0)[0] ?? [];
    }

    /**
     * Befunde für alle Artikel EINES GP in einem Durchgang (Median einmal je Feld).
     *
     * @param  list<array<string, float|int|string|null>>  $zeilen
     * @return array<int, list<NaehrwertBefund>> [Index in $zeilen => Befunde]
     */
    public function pruefeGruppe(array $zeilen, array $kontext = [], ?int $nurIndex = null): array
    {
        $norm = array_map(fn ($z) => $this->normiere($z), $zeilen);
        $out = [];
        $wirksam = [];
        foreach ($norm as $i => $z) {
            $befunde = [];
            if (($u = $this->einheit($z)) !== null) {
                $befunde[] = $u;
                $z = array_merge($z, $u->vorschlag['werte']);
            }
            $wirksam[$i] = $z;
            if ($nurIndex === null || $nurIndex === $i) {
                $out[$i] = array_merge($befunde, $this->einzel($z, $kontext));
            }
        }
        foreach ($this->geschwister($wirksam) as $i => $befunde) {
            if (isset($out[$i])) {
                $out[$i] = array_merge($out[$i], $befunde);
            }
        }

        return $out;
    }

    /**
     * Die Werte, mit denen gerechnet wird: bei eindeutiger Einheiten-Probe umgerechnet, sonst unverändert.
     *
     * @return array{werte: array<string, ?float>, faktor: ?int}
     */
    public function wirksameWerte(array $zeile): array
    {
        $z = $this->normiere($zeile);
        $u = $this->einheit($z);

        return ['werte' => $u === null ? $z : array_merge($z, $u->vorschlag['werte']), 'faktor' => $u?->vorschlag['faktor']];
    }

    /** Fingerabdruck der geprüften Rohwerte: eine Freigabe gilt nur, solange er gleich bleibt. */
    public static function werteHash(array $zeile): string
    {
        $w = [];
        foreach (self::FELDER as $f) {
            $w[$f] = isset($zeile[$f]) && $zeile[$f] !== '' ? round((float) $zeile[$f], 4) : null;
        }

        return hash('sha256', json_encode($w));
    }

    /** True, wenn mindestens ein Befund hart ist. @param list<NaehrwertBefund> $befunde */
    public static function istHart(array $befunde): bool
    {
        foreach ($befunde as $b) {
            if ($b->schwere === NaehrwertBefund::HART) {
                return true;
            }
        }

        return false;
    }

    /** H6: Einheiten-Probe. Nur eindeutig: Rohwerte passen NICHT zur Energie, genau EIN Faktor passt. */
    public function einheit(array $zeile): ?NaehrwertBefund
    {
        $z = $this->normiere($zeile);
        $c = config('foodalchemist.naehrwert_pruefung.einheit');
        $kcal = $z['energy_kcal'];
        if ($kcal === null || $kcal <= (float) $c['ab_kcal'] || ($z['fat'] === null && $z['protein'] === null && $z['carbs_absorbable'] === null)) {
            return null;
        }
        $passt = fn (float $at) => abs($at - $kcal) <= max((float) $c['toleranz_kcal'], (float) $c['toleranz_anteil'] * $kcal);
        $roh = $this->atwater($z);
        if ($passt($roh)) {
            return null;
        }
        $treffer = array_values(array_filter(array_map('intval', $c['faktoren']), fn (int $f) => $passt($roh * $f)));
        if (count($treffer) !== 1) {
            return null;                                          // keiner oder mehrdeutig → keine Umrechnung
        }
        $f = $treffer[0];
        $werte = [];
        foreach (self::MENGEN_UMRECHNUNG as $feld) {
            if ($z[$feld] !== null) {
                $werte[$feld] = round($z[$feld] * $f, 4);
            }
        }

        return new NaehrwertBefund('H6', NaehrwertBefund::UMRECHNUNG, 'makros', $this->kurz($z['fat']) . '/' . $this->kurz($z['protein']) . '/' . $this->kurz($z['carbs_absorbable']),
            "× {$f} ≈ " . round($roh * $f) . ' kcal', "Einheit: Fett/Eiweiß/Kohlenhydrate passen erst × {$f} zur Energie (" . round($kcal) . ' kcal)',
            ['faktor' => $f, 'werte' => $werte]);
    }

    /** @return list<NaehrwertBefund> Regeln, die nur die eine Zeile brauchen (auf die wirksamen Werte). */
    private function einzel(array $z, array $k): array
    {
        $c = config('foodalchemist.naehrwert_pruefung');
        $b = [];
        $hart = function (string $r, string $f, $w, string $e, string $g) use (&$b) {
            $b[] = new NaehrwertBefund($r, NaehrwertBefund::HART, $f, $w, $e, $g);
        };
        $hinweis = function (string $r, string $f, $w, string $e, string $g) use (&$b) {
            $b[] = new NaehrwertBefund($r, NaehrwertBefund::HINWEIS, $f, $w, $e, $g);
        };
        $wg = (string) ($k['wg'] ?? '');
        $sub = (string) ($k['sub'] ?? '');
        $name = (string) ($k['name'] ?? '');
        $text = $name . ' ' . ($k['bezeichnung'] ?? '');
        $salz = $z['sodium'] !== null ? $z['sodium'] * 0.0025 : null;

        // H1 unmöglich
        foreach (['fat', 'saturated_fat', 'carbs_absorbable', 'sugar', 'protein', 'roughage', 'alcohol'] as $f) {
            if ($z[$f] !== null && ($z[$f] < 0 || $z[$f] > 101)) {
                $hart('H1', $f, $z[$f], '0–100 g', 'Wert unmöglich: mehr als 100 g je 100 g oder negativ');
            }
        }
        if ($z['sodium'] !== null && ($z['sodium'] < 0 || $z['sodium'] > 40000)) {
            $hart('H1', 'sodium', $z['sodium'], '≤ 40.000 mg', 'Natrium entspricht mehr als 100 g Salz je 100 g');
        }
        if ($z['energy_kcal'] !== null && $z['energy_kcal'] > 905) {
            $hart('H1', 'energy_kcal', $z['energy_kcal'], '≤ 900 kcal', 'mehr Energie als reines Fett');
        }
        // H2 Summe
        $summe = array_sum(array_map(fn ($f) => (float) ($z[$f] ?? 0), ['fat', 'protein', 'carbs_absorbable', 'roughage', 'alcohol'])) + (float) ($salz ?? 0);
        if ($summe > (float) $c['summe_max_g']) {
            $hart('H2', 'summe', round($summe, 1), '≤ ' . $c['summe_max_g'] . ' g', 'Bestandteile ergeben mehr als 100 g je 100 g');
        }
        // H3 Teilmenge (LMIV)
        if (self::teilmengeVerletzt($z['sugar'], $z['carbs_absorbable'])) {
            $hart('H3', 'sugar', $z['sugar'], '≤ Kohlenhydrate ' . $this->kurz($z['carbs_absorbable']), '„davon Zucker“ größer als die Kohlenhydrate');
        }
        if (self::teilmengeVerletzt($z['saturated_fat'], $z['fat'])) {
            $hart('H3', 'saturated_fat', $z['saturated_fat'], '≤ Fett ' . $this->kurz($z['fat']), '„davon gesättigte Fettsäuren“ größer als das Fett');
        }
        // H4 Energie (nicht Getränke: Alkohol fehlt dort oft; nur wenn kcal und alle drei Makros deklariert)
        if ($wg !== '15' && $z['energy_kcal'] !== null && $z['fat'] !== null && $z['protein'] !== null && $z['carbs_absorbable'] !== null) {
            $at = $this->atwater($z);
            $d = abs($at - $z['energy_kcal']);
            if ($d > (float) $c['energie']['abstand_kcal'] && $d > (float) $c['energie']['anteil'] * max($at, $z['energy_kcal'])) {
                $hart('H4', 'energy_kcal', $z['energy_kcal'], '≈ ' . round($at) . ' kcal (4/4/9)', 'Energie passt nicht zu Fett, Eiweiß und Kohlenhydraten');
            }
        }
        // H5 kJ ↔ kcal (Hinweis: die Energie ist nicht Teil der Erdung)
        if ($z['energy_kcal'] !== null && $z['energy_kj'] !== null && $z['energy_kcal'] > (float) $c['kj']['ab_kcal']) {
            $q = $z['energy_kj'] / $z['energy_kcal'];
            if (($q < (float) $c['kj']['min'] || $q > (float) $c['kj']['max']) && abs($z['energy_kj'] - $z['energy_kcal'] * 4.184) > (float) $c['kj']['abstand_kj']) {
                $hinweis('H5', 'energy_kj', $z['energy_kj'], '≈ ' . round($z['energy_kcal'] * 4.184) . ' kJ', 'kJ passen nicht zu den kcal');
            }
        }
        // W1/W2 Öl
        if (str_starts_with($sub, '11.1') || str_starts_with($sub, '11.2')) {
            if ($z['fat'] !== null && $z['fat'] < (float) $c['oel_fett_min']) {
                $hart('W1', 'fat', $z['fat'], '≥ ' . $c['oel_fett_min'] . ' g', 'Öl mit zu wenig Fett');
            }
            if ($salz !== null && $salz > (float) $c['oel_salz_max']) {
                $hinweis('W2', 'salz', round($salz, 2), '≈ 0 g', 'Öl mit Salz');
            }
        }
        // W3 Gewürz-Zucker (nur Hinweis, Dominique 29.10.)
        if (str_starts_with($sub, '10.1') && $z['sugar'] !== null && $z['sugar'] > (float) $c['gewuerz_zucker_max']) {
            $hinweis('W3', 'sugar', $z['sugar'], '< ' . $c['gewuerz_zucker_max'] . ' g', 'Gewürz mit viel Zucker — vermutlich Kohlenhydrate statt Zucker eingetragen');
        }
        // W4 Frischgemüse
        if ($wg === '01' && str_contains($name, 'frisch') && $z['fat'] !== null && $z['fat'] > (float) $c['gemuese_fett_max']
            && ! preg_match('/avocado|oliv|gefüllt|frittiert|in öl|konfiert|chips|mit käse/iu', $text)) {
            $hinweis('W4', 'fat', $z['fat'], '< ' . $c['gemuese_fett_max'] . ' g', 'Frischgemüse mit viel Fett');
        }
        // W5 Wasser (ohne Aroma-/Sodawasser)
        if (str_starts_with($sub, '15.5') && ! preg_match('/soda|aroma|geschmack|pfirsich|grapefruit|zitrone|limette/iu', $text)) {
            $w = $c['wasser'];
            if (($z['energy_kcal'] ?? 0) > $w['kcal'] || ($z['fat'] ?? 0) > $w['fett'] || ($z['sugar'] ?? 0) > $w['zucker']) {
                $hart('W5', 'kcal/fett/zucker', $this->kurz($z['energy_kcal']) . '/' . $this->kurz($z['fat']) . '/' . $this->kurz($z['sugar']), '≈ 0', 'Wasser mit Energie, Fett oder Zucker');
            }
        }
        // W6 Essig (ohne Cremes/Reduktionen)
        if (str_starts_with($sub, '11.3') && ! preg_match('/creme|crema|reduktion|glace|sirup/iu', $name)
            && (($z['fat'] ?? 0) > $c['essig']['fett'] || ($z['energy_kcal'] ?? 0) > $c['essig']['kcal'])) {
            $hinweis('W6', 'fett/kcal', $this->kurz($z['fat']) . '/' . $this->kurz($z['energy_kcal']), 'Fett ≤ ' . $c['essig']['fett'] . ', kcal ≤ ' . $c['essig']['kcal'], 'Essig mit Fett oder viel Energie');
        }

        return $b;
    }

    /**
     * A: Abweichung von den Geschwister-Artikeln desselben GP (Median, Faktor UND Mindestabstand). Echte Nullen zählen mit.
     *
     * @param  array<int, array<string, ?float>>  $zeilen  wirksame Werte
     * @return array<int, list<NaehrwertBefund>>
     */
    private function geschwister(array $zeilen): array
    {
        $c = config('foodalchemist.naehrwert_pruefung.geschwister');
        $out = [];
        foreach ($c['abstand'] as $feld => $mindest) {
            $werte = array_filter(array_map(fn ($z) => $z[$feld], $zeilen), fn ($v) => $v !== null);
            if (count($werte) < (int) $c['min_artikel']) {
                continue;
            }
            $median = \Platform\FoodAlchemist\Services\NaehrwertAggregatService::median(array_values($werte));
            foreach ($werte as $i => $v) {
                if (abs($v - $median) > (float) $mindest && ($v > $median * $c['faktor'] || $v < $median / $c['faktor'])) {
                    $out[$i][] = new NaehrwertBefund('A', NaehrwertBefund::HART, $feld, $v, 'Median ' . $this->kurz($median) . ' (' . count($werte) . ' Artikel)',
                        'weicht deutlich von den übrigen Artikeln dieses Grundprodukts ab');
                }
            }
        }

        return $out;
    }

    /** LMIV: ein „davon“-Wert darf seinen Oberwert nicht übersteigen (5 % + 0,5 g Rundung). Auch für die Rezept-Ebene. */
    public static function teilmengeVerletzt(?float $davon, ?float $gesamt): bool
    {
        return $davon !== null && $gesamt !== null && $davon > $gesamt * 1.05 + 0.5;
    }

    private function atwater(array $z): float
    {
        return 4 * (float) ($z['protein'] ?? 0) + 4 * (float) ($z['carbs_absorbable'] ?? 0) + 9 * (float) ($z['fat'] ?? 0)
            + 2 * (float) ($z['roughage'] ?? 0) + 7 * (float) ($z['alcohol'] ?? 0);
    }

    /** @return array<string, ?float> */
    private function normiere(array $z): array
    {
        $out = [];
        foreach (self::FELDER as $f) {
            $out[$f] = isset($z[$f]) && $z[$f] !== '' ? (float) $z[$f] : null;
        }

        return $out;
    }

    private function kurz(?float $x): string
    {
        return $x === null ? '–' : rtrim(rtrim(number_format($x, 2, ',', ''), '0'), ',');
    }
}
