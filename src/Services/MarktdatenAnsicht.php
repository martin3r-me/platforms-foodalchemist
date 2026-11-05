<?php

namespace Platform\FoodAlchemist\Services;

/**
 * Ansicht „Markt in Zahlen“ aus den gelesenen Reihen ({@see MarktdatenService::lesen}): Kacheln mit Sparkline, Warengruppen-
 * Balken, „Die Schere“ (Vorjahres-% über 12 Monate). Reine Rechnung, kein Abruf, keine KI.
 *
 * Grundsatz (Dominique 05.11.): ein Element erscheint NUR, wenn seine Daten vollständig sind — sonst gar nicht, kein Platzhalter.
 * Die SVG-Geometrie wird hier gerechnet (feste viewBox), die Blade-Vorlage zeichnet nur; io baut beim Modul-Deploy kein JS/CSS.
 */
class MarktdatenAnsicht
{
    /** Kacheln in fester Reihenfolge. ton: wofür „steigt“ steht — einkauf (teurer = rot), umsatz (mehr = grün), verkauf (neutral). */
    public const KACHELN = [
        'gaststaettenpreise' => ['titel' => 'Restaurantpreise', 'ton' => 'verkauf'],
        'grosshandel_nahrungsmittel' => ['titel' => 'Großhandel Nahrung', 'ton' => 'einkauf'],
        'kantinenpreise' => ['titel' => 'Kantinen und Mensen', 'ton' => 'verkauf'],
        'caterer_umsatz_real' => ['titel' => 'Caterer-Umsatz real', 'ton' => 'umsatz'],
    ];

    /** Linien der Schere: Strichart unterscheidet, nicht nur die Farbe. */
    public const SCHERE = [
        'grosshandel_nahrungsmittel' => ['titel' => 'Einkauf (Großhandel Nahrung)', 'strich' => 'voll'],
        'gaststaettenpreise' => ['titel' => 'Restaurantpreise', 'strich' => 'gestrichelt'],
        'kantinenpreise' => ['titel' => 'Kantinen und Mensen', 'strich' => 'gepunktet'],
    ];

    public const MONATE = 12;

    public const MIN_WARENGRUPPEN = 3;

    /** Bewegungen unter dieser Schwelle (Prozentpunkte) gelten als „gleich“ und bleiben farblich neutral. */
    public const NEUTRAL_PP = 0.5;

    /**
     * @param  array  $marktdaten  Rückgabe von MarktdatenService::lesen
     * @return array{kacheln: list<array>, balken: ?array, schere: ?array}
     */
    public static function bauen(array $marktdaten): array
    {
        $alle = [];
        foreach ($marktdaten['reihen'] as $r) {
            $alle[$r['serie']] = $r;
            foreach ($r['unterreihen'] ?? [] as $u) {
                $alle[$u['serie']] = $u;
            }
        }
        foreach ($marktdaten['alle_reihen'] ?? [] as $r) {
            $alle[$r['serie']] ??= $r;
        }

        return ['kacheln' => self::kacheln($alle), 'balken' => self::balken($alle), 'schere' => self::schere($alle)];
    }

    /** Monatsschlüssel „2026-09“ → zurück um n Monate. */
    public static function monatZurueck(string $monat, int $n): string
    {
        return \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $monat.'-01')->subMonthsNoOverflow($n)->format('Y-m');
    }

    /** @return array<string, float> monat → Wert, nur Monate mit Wert */
    private static function werte(array $reihe): array
    {
        $w = [];
        foreach ($reihe['punkte'] ?? [] as $p) {
            if ($p['wert'] !== null) {
                $w[$p['monat']] = ['wert' => (float) $p['wert'], 'einheit' => $p['einheit']];
            }
        }

        return $w;
    }

    /** Die letzten $n Monate bis einschließlich $bis — nur wenn ALLE einen Wert mit gleicher Basis haben, sonst null. */
    private static function lueckenlos(array $reihe, string $bis, int $n): ?array
    {
        $w = self::werte($reihe);
        $out = [];
        $basis = $w[$bis]['einheit'] ?? null;
        for ($i = $n - 1; $i >= 0; $i--) {
            $m = self::monatZurueck($bis, $i);
            if (! isset($w[$m]) || $w[$m]['einheit'] !== $basis) {
                return null;
            }
            $out[$m] = $w[$m]['wert'];
        }

        return $out;
    }

    /** @return array<string, float> monat → Vorjahres-% (gleiche Basis), nur wo beide Monate einen Wert haben */
    public static function vorjahrReihe(array $reihe): array
    {
        $w = self::werte($reihe);
        $out = [];
        foreach ($w as $m => $p) {
            $vj = $w[self::monatZurueck($m, 12)] ?? null;
            if ($vj !== null && $vj['wert'] > 0 && $vj['einheit'] === $p['einheit']) {
                $out[$m] = round(($p['wert'] / $vj['wert'] - 1) * 100, 1);
            }
        }
        ksort($out);

        return $out;
    }

    public static function farbe(string $ton, ?float $pct): string
    {
        if ($pct === null || abs($pct) < self::NEUTRAL_PP || $ton === 'verkauf') {
            return 'neutral';
        }

        return ($ton === 'einkauf') === ($pct > 0) ? 'schlecht' : 'gut';
    }

    private static function kacheln(array $alle): array
    {
        $out = [];
        foreach (self::KACHELN as $serie => $meta) {
            $r = $alle[$serie] ?? null;
            $monat = $r['aktuell']['monat'] ?? null;
            if ($r === null || $monat === null || ($r['aktuell']['wert'] ?? null) === null || $r['vorjahr_prozent'] === null) {
                continue;
            }
            $verlauf = self::lueckenlos($r, $monat, self::MONATE);
            if ($verlauf === null) {
                continue;   // keine halbe Sparkline
            }
            $out[] = $meta + ['serie' => $serie, 'monat' => $monat, 'vorjahr_prozent' => (float) $r['vorjahr_prozent'],
                'farbe' => self::farbe($meta['ton'], (float) $r['vorjahr_prozent']), 'basis' => $r['aktuell']['einheit'],
                'tabelle' => $r['tabelle'], 'sparkline' => self::sparkline(array_values($verlauf)),
                'von' => array_key_first($verlauf), 'bis' => array_key_last($verlauf)];
        }

        return $out;
    }

    /** Polyline in viewBox 0 0 120 32 (2 px Rand oben/unten), plus Endpunkt. */
    public static function sparkline(array $werte, int $breite = 120, int $hoehe = 32): array
    {
        $min = min($werte);
        $max = max($werte);
        $spanne = $max - $min ?: 1.0;
        $n = count($werte);
        $punkte = [];
        foreach ($werte as $i => $v) {
            $x = round($n === 1 ? $breite / 2 : $i * $breite / ($n - 1), 1);
            $y = round(2 + ($hoehe - 4) * (1 - ($v - $min) / $spanne), 1);
            $punkte[] = $x.','.$y;
        }

        return ['punkte' => implode(' ', $punkte), 'ende' => explode(',', end($punkte)), 'breite' => $breite, 'hoehe' => $hoehe];
    }

    private static function balken(array $alle): ?array
    {
        $eltern = $alle['grosshandel_nahrungsmittel'] ?? null;
        $monat = $eltern['aktuell']['monat'] ?? null;
        if ($monat === null) {
            return null;
        }
        $zeilen = [];
        foreach ($alle as $serie => $r) {
            if (($r['gruppe'] ?? null) !== 'grosshandel_nahrungsmittel' || ($r['abschnitt'] ?? null) === 'verbraucher') {
                continue;
            }
            if ($r['vorjahr_prozent'] === null || ($r['aktuell']['monat'] ?? null) !== $monat) {
                continue;
            }
            $zeilen[] = ['serie' => $serie, 'name' => $r['name'], 'pct' => (float) $r['vorjahr_prozent'], 'farbe' => self::farbe('einkauf', (float) $r['vorjahr_prozent'])];
        }
        if (count($zeilen) < self::MIN_WARENGRUPPEN) {
            return null;
        }
        usort($zeilen, fn ($a, $b) => $b['pct'] <=> $a['pct'] ?: strcmp($a['name'], $b['name']));
        $skala = max(1.0, max(array_map(fn ($z) => abs($z['pct']), $zeilen)));
        foreach ($zeilen as &$z) {
            // Breite in % der halben Spur; Nulllinie in der Mitte
            $z['breite'] = round(abs($z['pct']) / $skala * 50, 1);
            $z['links'] = $z['pct'] >= 0 ? 50.0 : round(50 - $z['breite'], 1);
        }

        return ['monat' => $monat, 'zeilen' => $zeilen, 'skala' => $skala, 'tabelle' => $eltern['tabelle']];
    }

    private static function schere(array $alle): ?array
    {
        $reihen = [];
        foreach (array_keys(self::SCHERE) as $serie) {
            if (! isset($alle[$serie])) {
                return null;
            }
            $reihen[$serie] = self::vorjahrReihe($alle[$serie]);
        }
        $gemeinsam = array_values(array_intersect(...array_map('array_keys', array_values($reihen))));
        sort($gemeinsam);
        if ($gemeinsam === []) {
            return null;
        }
        $bis = end($gemeinsam);
        $monate = [];
        for ($i = self::MONATE - 1; $i >= 0; $i--) {
            $m = self::monatZurueck($bis, $i);
            if (! in_array($m, $gemeinsam, true)) {
                return null;   // keine halbe Linie
            }
            $monate[] = $m;
        }
        $alleWerte = [];
        foreach ($reihen as $r) {
            foreach ($monate as $m) {
                $alleWerte[] = $r[$m];
            }
        }
        // y-Achse: symmetrisch genug, dass 0 sichtbar ist; auf ganze Prozent gerundet
        $min = floor(min(0, min($alleWerte)));
        $max = ceil(max(0, max($alleWerte)));
        if ($max - $min < 2) {
            $max += 1;
            $min -= 1;
        }
        [$b, $h, $l, $o, $u] = [600, 220, 44, 12, 28];   // viewBox, Rand links/oben/unten
        $x = fn (int $i) => round($l + $i * ($b - $l - 10) / (self::MONATE - 1), 1);
        $y = fn (float $v) => round($o + ($h - $o - $u) * ($max - $v) / ($max - $min), 1);
        $linien = [];
        foreach ($reihen as $serie => $r) {
            $pkt = [];
            foreach ($monate as $i => $m) {
                $pkt[] = $x($i).','.$y($r[$m]);
            }
            $linien[] = self::SCHERE[$serie] + ['serie' => $serie, 'punkte' => implode(' ', $pkt), 'letzter' => $r[$bis]];
        }
        $schritt = ($max - $min) > 8 ? 2 : 1;
        $ticks = [];
        for ($v = $min; $v <= $max; $v += $schritt) {
            $ticks[] = ['wert' => $v, 'y' => $y((float) $v)];
        }

        return ['von' => $monate[0], 'bis' => $bis, 'linien' => $linien, 'ticks' => $ticks, 'null_y' => $y(0.0),
            'x_labels' => [['text' => $monate[0], 'x' => $x(0)], ['text' => $bis, 'x' => $x(self::MONATE - 1)]],
            'breite' => $b, 'hoehe' => $h, 'links' => $l, 'unten' => $h - $u];
    }
}
