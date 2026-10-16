<?php

namespace Platform\FoodAlchemist\Livewire\Preise;

use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\PreisVerlaufService;

/**
 * Preisverlauf als Liniendiagramm — am Lieferantenartikel (`modus = la`: eine Linie) und am Grundprodukt (`modus = gp`: eine Linie je LA, Lead hervorgehoben).
 * Zeitraum 3 M / 6 M / 12 M / Alles oder von–bis. Reines SVG (kein JS, wie die Trend-Sparkline im Signale-Panel); die Geometrie wird hier
 * gerechnet, damit sie im Test greifbar ist. Daten und Trend liefert {@see PreisVerlaufService}.
 */
class Verlauf extends Component
{
    /** Linienfarben (nur Tokens), Lead bekommt immer die erste. */
    private const FARBEN = ['var(--fa-accent)', 'var(--fa-info)', 'var(--fa-ok)', 'var(--fa-warn)', 'var(--fa-crit)', 'var(--fa-ink-2)'];

    public string $modus = 'la';

    public int $objektId = 0;

    public string $zeitraum = '12m';

    public ?string $von = null;

    public ?string $bis = null;

    /** Nur modus la: alle Artikel des Grundprodukts statt nur Lead-Vergleich. */
    public bool $alleArtikel = false;

    public function mount(string $modus = 'la', int $objektId = 0): void
    {
        $this->modus = $modus === 'gp' ? 'gp' : 'la';
        $this->objektId = $objektId;
    }

    /** Ein Preis wurde angelegt, geändert oder gelöscht: neu zeichnen (das Ereignis löst das Neurendern aus). */
    #[On('preise-geaendert')]
    public function neuLaden(): void
    {
    }

    public function alleArtikelUmschalten(): void
    {
        $this->alleArtikel = ! $this->alleArtikel;
    }

    public function zeitraumSetzen(string $z): void
    {
        $this->zeitraum = in_array($z, ['3m', '6m', '12m', 'alles', 'frei'], true) ? $z : '12m';
    }

    public function render()
    {
        $team = Auth::user()?->currentTeamRelation;
        $daten = null;
        $fehler = null;
        try {
            [$von, $bis] = app(PreisVerlaufService::class)->fenster($this->zeitraum, $this->von, $this->bis);
            $svc = app(PreisVerlaufService::class);
            if ($this->modus === 'gp') {
                $gp = FoodAlchemistGp::query()->visibleToTeam($team)->find($this->objektId);
                $daten = $gp !== null ? $svc->verlaufFuerGp($team, $gp, $von, $bis) : null;
            } elseif ($this->objektId > 0) {
                $daten = $svc->verlaufFuerLa($team, $this->objektId, $von, $bis, $this->alleArtikel);
            }
        } catch (\Throwable) {
            $fehler = 'Der Zeitraum ist ungültig. Bitte Daten im Format TT.MM.JJJJ wählen.';
        }

        return view('foodalchemist::livewire.preise.verlauf', [
            'daten' => $daten,
            'diagramm' => $daten !== null && $daten['linien'] !== [] ? self::diagramm($daten) : null,
            'fehler' => $fehler,
        ]);
    }

    private const MONATE = ['Jan', 'Feb', 'Mär', 'Apr', 'Mai', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez'];

    /**
     * Zeichen-Geometrie in NORMIERTEN Koordinaten (0–1000 in beide Richtungen, das SVG streckt sich auf die Fläche; Linien mit
     * non-scaling-stroke). Die Beschriftung (Achsen, Einheit) setzt die View als HTML mit FA-Typografie an Prozent-Positionen, damit
     * die Schrift nie mit dem Diagramm skaliert. Stufenlinie = durchgehende Treppe mit senkrechtem Strich beim Preiswechsel; echte Lücken
     * (kein Preis) werden als gestrichelter Übergang gezeichnet.
     *
     * @param  array<string,mixed>  $daten  Ergebnis von {@see PreisVerlaufService::verlauf}
     * @return array<string,mixed>
     */
    public static function diagramm(array $daten): array
    {
        $linien = $daten['linien'];
        $ts = [];
        $werte = [];
        foreach ($linien as $l) {
            foreach ($l['segmente'] as $s) {
                array_push($ts, $s['von_ts'], $s['bis_ts']);
                $werte[] = $s['wert'];
            }
            if ($l['trend'] !== null) {
                array_push($werte, $l['trend']['start_wert'], $l['trend']['ende_wert']);
            }
        }
        $t0 = min($ts);
        $t1 = max($ts);
        [$ticksY, $yMin, $yMax] = self::runderAchsenwert(min($werte), max($werte));

        $x = fn (int $t) => round(($t1 > $t0 ? ($t - $t0) / ($t1 - $t0) : 0.5) * 1000, 2);
        $y = fn (float $v) => round((1 - ($v - $yMin) / ($yMax - $yMin)) * 1000, 2);
        $dezimal = ($ticksY[1] ?? 1) - $ticksY[0] < 0.01 ? 3 : 2;

        $achseY = array_map(fn (float $v) => ['y' => $y($v), 'label' => number_format($v, $dezimal, ',', '.')], $ticksY);
        $achseX = self::zeitTicks($t0, $t1, $x);

        $svgLinien = [];
        $weitere = 0;
        $mitFokus = in_array(true, array_column($linien, 'fokus'), true);
        foreach ($linien as $l) {
            // Hauptlinie (am Artikel der Artikel selbst, am Grundprodukt der Lead) immer in der ersten Farbe, alle anderen der Reihe nach
            $haupt = $mitFokus ? $l['fokus'] : $l['lead'];
            $farbe = $haupt ? self::FARBEN[0] : self::FARBEN[1 + ($weitere++ % (count(self::FARBEN) - 1))];
            $pfad = '';
            $luecken = '';
            $punkte = [];
            $vorher = null;   // [bis_ts, y]
            foreach ($l['segmente'] as $s) {
                $x0 = $x($s['von_ts']);
                $x1 = $x($s['bis_ts']);
                $yy = $y($s['wert']);
                if ($vorher !== null && $vorher[0] === $s['von_ts']) {
                    $pfad .= 'V'.$yy.' H'.$x1.' ';                                  // senkrechter Strich beim Preiswechsel
                } else {
                    if ($vorher !== null) {
                        $luecken .= 'M'.$x($vorher[0]).' '.$vorher[1].' L'.$x0.' '.$yy.' ';   // kein Preis dazwischen: gestrichelt
                    }
                    $pfad .= 'M'.$x0.' '.$yy.' H'.$x1.' ';
                }
                $vorher = [$s['bis_ts'], $yy];
                $punkte[] = [
                    'x' => $x0, 'breite' => max(0.5, round($x1 - $x0, 2)),
                    'tip' => sprintf('%s · %s: %s %s je Basiseinheit · Gebinde %s € · ab %s',
                        $l['lieferant'] ?? '–', $l['artikel'] ?? '', number_format($s['wert'], 2, ',', '.'), $daten['einheit'], number_format((float) $s['preis_gebinde'], 2, ',', '.'),
                        Carbon::createFromTimestamp($s['von_ts'])->format('d.m.Y')).($s['quelle'] === 'historie' ? ' (alter Stand)' : ''),
                ];
            }
            $trend = null;
            if ($l['trend'] !== null) {
                $trend = ['x1' => $x($l['trend']['von_ts']), 'y1' => $y(min($yMax, max($yMin, $l['trend']['start_wert']))),
                    'x2' => $x($l['trend']['bis_ts']), 'y2' => $y(min($yMax, max($yMin, $l['trend']['ende_wert'])))];
            }
            // Lieferstatus: Markierung am Tag, an dem der Artikel eingestellt wurde bzw. zu laufen aufhört (nur im sichtbaren Zeitraum)
            $ls = $l['lieferstatus'] ?? null;
            $marker = $ls !== null && $ls['seit_ts'] !== null && $ls['seit_ts'] >= $t0 && $ls['seit_ts'] <= $t1
                ? ['x' => $x((int) $ls['seit_ts']), 'label' => $ls['kurz'], 'art' => $ls['status']] : null;
            $svgLinien[] = [
                'lieferstatus' => $ls, 'marker' => $marker,
                'la_id' => $l['la_id'], 'farbe' => $farbe, 'breite' => $haupt ? 2.5 : 1.75, 'gedaempft' => $mitFokus && ! $haupt, 'fokus' => $l['fokus'], 'pfad' => trim($pfad), 'luecken' => trim($luecken), 'punkte' => $punkte, 'trend' => $trend,
                'lead' => $l['lead'], 'lieferant' => $l['lieferant'], 'artikel' => $l['artikel'], 'staende' => $l['staende'], 'ohne_wechsel' => $l['ohne_wechsel'] ?? false,
                'aktuell' => end($l['segmente'])['wert'], 'trend_text' => self::trendText($l['trend'], (string) $daten['einheit']),
            ];
        }

        return ['achse_y' => $achseY, 'achse_x' => $achseX, 'linien' => $svgLinien, 'einheit' => $daten['einheit'], 'hoehe' => self::HOEHE_PX];
    }

    /** Höhe der Zeichenfläche in Pixeln (Dominique: etwa 220–260 px im Modal). */
    public const HOEHE_PX = 240;

    /**
     * Runde Achsenwerte: 4–5 Ticks mit „schönen“ Schritten (1 / 2 / 2,5 / 5 × 10ⁿ), die Achse reicht von Tick zu Tick.
     *
     * @return array{0:list<float>, 1:float, 2:float}  Ticks, Minimum, Maximum der Achse
     */
    public static function runderAchsenwert(float $min, float $max): array
    {
        if ($max - $min < 1e-9) {
            $mitte = $max;
            $min = $mitte * 0.9;
            $max = $mitte * 1.1;
            if ($mitte == 0.0) {
                $max = 1.0;
            }
        }
        $roh = ($max - $min) / 4;
        $potenz = 10 ** floor(log10($roh));
        $schritt = $potenz;
        foreach ([1, 2, 2.5, 5, 10] as $f) {
            $schritt = $f * $potenz;
            if ($schritt >= $roh) {
                break;
            }
        }
        $von = max(0.0, floor($min / $schritt) * $schritt);
        $bis = ceil($max / $schritt) * $schritt;
        $ticks = [];
        for ($v = $von; $v <= $bis + $schritt * 1e-6; $v += $schritt) {
            $ticks[] = round($v, 6);
        }

        return [$ticks, (float) $ticks[0], (float) end($ticks)];
    }

    /**
     * Zeit-Ticks je nach Spanne: unter ~6 Wochen wöchentlich („12.10.“), bis ~8 Monate monatlich, bis ~2 Jahre je Quartal, darüber je Jahr
     * (Format „Jan 26“, bei Wochen „TT.MM.“). Mindestens ein Tick; sonst Anfang und Ende.
     *
     * @return list<array{x:float, label:string, y?:null}>
     */
    public static function zeitTicks(int $t0, int $t1, \Closure $x): array
    {
        $von = Carbon::createFromTimestamp($t0);
        $bis = Carbon::createFromTimestamp($t1);
        $tage = max(1, $von->diffInDays($bis));
        $ticks = [];
        if ($tage < 45) {
            for ($d = $von->copy()->startOfDay(); $d->lte($bis); $d->addDays(7)) {
                $ticks[] = ['x' => $x($d->getTimestamp()), 'label' => $d->format('d.m.')];
            }
        } else {
            $schrittMonate = $tage <= 245 ? 1 : ($tage <= 740 ? 3 : 12);
            $m = $von->copy()->startOfMonth();
            if ($m->lt($von)) {
                $m->addMonth();
            }
            if ($schrittMonate === 3) {
                while ($m->month % 3 !== 1) {
                    $m->addMonth();
                }
            } elseif ($schrittMonate === 12) {
                while ($m->month !== 1) {
                    $m->addMonth();
                }
            }
            for (; $m->lte($bis); $m->addMonths($schrittMonate)) {
                $ticks[] = ['x' => $x($m->getTimestamp()), 'label' => self::MONATE[$m->month - 1].' '.$m->format('y')];
            }
        }
        if ($ticks === []) {
            $ticks = [['x' => 0.0, 'label' => $von->format('d.m.y')], ['x' => 1000.0, 'label' => $bis->format('d.m.y')]];
        }

        return $ticks;
    }

    /** „+0,12 €/kg pro Monat · +3,1 % pro Jahr“ (Minuszeichen bei Fall); null ohne Trend. */
    public static function trendText(?array $trend, string $einheit): ?string
    {
        if ($trend === null) {
            return null;
        }
        $vz = fn (float $v) => $v < 0 ? '−' : '+';
        // Kleine Steigungen mit 3 Stellen („+0,004“), damit nicht „+0,00“ neben „+0,7 % pro Jahr“ steht
        $dez = abs($trend['steigung_pro_monat']) >= 0.01 ? 2 : 3;
        $text = $vz($trend['steigung_pro_monat']).number_format(abs($trend['steigung_pro_monat']), $dez, ',', '.').' '.$einheit.' pro Monat';
        if ($trend['prozent_pro_jahr'] !== null) {
            $text .= ' · '.$vz((float) $trend['prozent_pro_jahr']).number_format(abs($trend['prozent_pro_jahr']), 1, ',', '.').' % pro Jahr';
        }

        return $text;
    }
}
