<?php

namespace Platform\FoodAlchemist\Livewire\Preise;

use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
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
    public const W = 640;

    public const H = 240;

    public const RAND_L = 56;

    public const RAND_R = 12;

    public const RAND_O = 12;

    public const RAND_U = 26;

    /** Linienfarben (nur Tokens), Lead bekommt immer die erste. */
    private const FARBEN = ['var(--fa-accent)', 'var(--fa-info)', 'var(--fa-ok)', 'var(--fa-warn)', 'var(--fa-crit)', 'var(--fa-ink-2)'];

    public string $modus = 'la';

    public int $objektId = 0;

    public string $zeitraum = '12m';

    public ?string $von = null;

    public ?string $bis = null;

    public function mount(string $modus = 'la', int $objektId = 0): void
    {
        $this->modus = $modus === 'gp' ? 'gp' : 'la';
        $this->objektId = $objektId;
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
                $daten = $svc->verlauf($team, [$this->objektId], $von, $bis, $this->objektId);
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

    /**
     * Zeichen-Geometrie: Achsen, Stufenpfade, gestrichelte Trendlinien, Messpunkte mit Tooltip.
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
        $min = min($werte);
        $max = max($werte);
        $spanne = $max - $min;
        $pad = $spanne > 0 ? $spanne * 0.08 : max(abs($max) * 0.1, 0.01);   // flache Reihe: mittig, nicht am Rand
        $yMin = max(0.0, $min - $pad);
        $yMax = $max + $pad;
        $innenW = self::W - self::RAND_L - self::RAND_R;
        $innenH = self::H - self::RAND_O - self::RAND_U;

        $x = fn (int $t) => round(self::RAND_L + ($t1 > $t0 ? ($t - $t0) / ($t1 - $t0) : 0.5) * $innenW, 2);
        $y = fn (float $v) => round(self::RAND_O + (1 - ($yMax > $yMin ? ($v - $yMin) / ($yMax - $yMin) : 0.5)) * $innenH, 2);

        $achseY = [];
        foreach (range(0, 4) as $i) {
            $v = $yMin + ($yMax - $yMin) * $i / 4;
            $achseY[] = ['y' => $y($v), 'label' => number_format($v, 2, ',', '.')];
        }
        $achseX = [];
        foreach (range(0, 3) as $i) {
            $t = (int) round($t0 + ($t1 - $t0) * $i / 3);
            $achseX[] = ['x' => $x($t), 'label' => Carbon::createFromTimestamp($t)->format('d.m.y'), 'anker' => $i === 0 ? 'start' : ($i === 3 ? 'end' : 'middle')];
        }

        $svgLinien = [];
        $weitere = 0;
        foreach ($linien as $l) {
            // Lead immer in der ersten Farbe, alle anderen der Reihe nach (nie dieselbe wie der Lead)
            $farbe = $l['lead'] ? self::FARBEN[0] : self::FARBEN[1 + ($weitere++ % (count(self::FARBEN) - 1))];
            $pfad = '';
            $punkte = [];
            $vorher = null;
            foreach ($l['segmente'] as $s) {
                $x0 = $x($s['von_ts']);
                $x1 = $x($s['bis_ts']);
                $yy = $y($s['wert']);
                $pfad .= ($vorher !== null && $vorher === $s['von_ts'] ? 'V'.$yy.' H'.$x1.' ' : 'M'.$x0.' '.$yy.' H'.$x1.' ');
                $vorher = $s['bis_ts'];
                $punkte[] = ['x' => $x0, 'y' => $yy, 'tip' => sprintf('%s: %s %s je Basiseinheit · Gebinde %s € · ab %s (%s)',
                    $l['lieferant'] ?? '–', number_format($s['wert'], 2, ',', '.'), $daten['einheit'], number_format((float) $s['preis_gebinde'], 2, ',', '.'),
                    Carbon::createFromTimestamp($s['von_ts'])->format('d.m.Y'), $s['quelle'] === 'historie' ? 'alter Stand' : 'Preiszeile')];
            }
            $trend = null;
            if ($l['trend'] !== null) {
                $trend = ['x1' => $x($l['trend']['von_ts']), 'y1' => $y(max($yMin, $l['trend']['start_wert'])), 'x2' => $x($l['trend']['bis_ts']), 'y2' => $y(max($yMin, $l['trend']['ende_wert']))];
            }
            $svgLinien[] = [
                'la_id' => $l['la_id'], 'farbe' => $farbe, 'breite' => $l['lead'] ? 3 : 1.75, 'pfad' => trim($pfad), 'punkte' => $punkte, 'trend' => $trend,
                'lead' => $l['lead'], 'lieferant' => $l['lieferant'], 'artikel' => $l['artikel'],
                'aktuell' => end($l['segmente'])['wert'], 'trend_text' => self::trendText($l['trend'], (string) $daten['einheit']),
            ];
        }

        return ['w' => self::W, 'h' => self::H, 'links' => self::RAND_L, 'rechts' => self::W - self::RAND_R, 'oben' => self::RAND_O, 'unten' => self::H - self::RAND_U,
            'achse_y' => $achseY, 'achse_x' => $achseX, 'linien' => $svgLinien, 'einheit' => $daten['einheit']];
    }

    /** „+0,12 €/kg pro Monat · +3,1 % pro Jahr“ (Minuszeichen bei Fall); null ohne Trend. */
    public static function trendText(?array $trend, string $einheit): ?string
    {
        if ($trend === null) {
            return null;
        }
        $vz = fn (float $v) => $v < 0 ? '−' : '+';
        $text = $vz($trend['steigung_pro_monat']).number_format(abs($trend['steigung_pro_monat']), 2, ',', '.').' '.$einheit.' pro Monat';
        if ($trend['prozent_pro_jahr'] !== null) {
            $text .= ' · '.$vz((float) $trend['prozent_pro_jahr']).number_format(abs($trend['prozent_pro_jahr']), 1, ',', '.').' % pro Jahr';
        }

        return $text;
    }
}
