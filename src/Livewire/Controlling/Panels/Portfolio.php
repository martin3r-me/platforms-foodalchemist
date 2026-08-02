<?php

namespace Platform\FoodAlchemist\Livewire\Controlling\Panels;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\FoodAlchemist\Services\PortfolioService;

/**
 * Spec 33 · P4 — die Mehrbetriebs-Sicht: wer fährt gerade was.
 *
 * **Eine Matrix, zwei Brillen.** Der Umschalter wechselt nur die Zeilenachse (Betrieb ⇄ Kunde),
 * nicht die Fläche. Zwei getrennte Ansichten für dieselbe Frage würden auseinanderdriften,
 * sobald an einer etwas ergänzt wird.
 *
 * **Der Stichtag ist ein Regler, kein Filter.** „Was läuft heute" ist die Standardfrage, aber
 * „was lief im Juli" und „was läuft ab September" sind dieselbe Abfrage mit anderem Datum —
 * und beantworten die Planungsfrage gleich mit.
 *
 * Beobachten hier, **schalten an der Ausgabe** (dieselbe Trennung wie Einkauf ↔ Controlling):
 * die Zeilen springen in den jeweiligen Editor, geschrieben wird dort über den eigenen Service.
 */
class Portfolio extends Component
{
    /** `betrieb` | `kunde` */
    public string $brille = 'betrieb';

    /** Leer = heute. */
    public string $stichtag = '';

    public function brilleSetzen(string $b): void
    {
        if (in_array($b, ['betrieb', 'kunde'], true)) {
            $this->brille = $b;
        }
    }

    public function heute(): void
    {
        $this->stichtag = '';
    }

    public function render(PortfolioService $portfolio)
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null) {
            return view('foodalchemist::livewire.controlling.panels.portfolio', [
                'leer' => true, 'matrix' => [], 'arten' => PortfolioService::ARTEN,
                'ohneZuordnung' => [], 'luecken' => [], 'konflikte' => [], 'stichtagAnzeige' => null,
            ]);
        }

        $tag = $this->stichtag !== '' ? $this->stichtag : null;
        $zeilen = $portfolio->uebersicht($team, $tag);

        // Matrix: Zeilenachse × Ausgabeform. Nur LAUFENDE Ausgaben füllen eine Zelle — eine
        // archivierte Karte im Betrieb ist keine Antwort auf „was läuft dort".
        $matrix = [];
        foreach ($zeilen as $z) {
            if (! $z['laeuft']) {
                continue;
            }
            $key = $this->brille === 'betrieb' ? $z['outlet_id'] : $z['kunde_key'];
            if ($key === null) {
                continue;   // steht im eigenen Block „ohne Zuordnung"
            }
            $matrix[$key]['label'] ??= $this->brille === 'betrieb' ? ($z['outlet_name'] ?? '—') : ($z['kunde'] ?? '—');
            $matrix[$key]['zellen'][$z['art']][] = $z;
        }

        // Lücken einreihen, damit ein Standort ohne jede laufende Ausgabe nicht einfach fehlt —
        // genau der Fall, den die Übersicht sichtbar machen soll.
        foreach ($portfolio->luecken($team, $this->brille, $tag) as $l) {
            $matrix[$l['schluessel']]['label'] ??= $l['zuordnung'];
            $matrix[$l['schluessel']]['zellen'] ??= [];
        }
        ksort($matrix);

        return view('foodalchemist::livewire.controlling.panels.portfolio', [
            'leer' => false,
            'arten' => PortfolioService::ARTEN,
            'matrix' => $matrix,
            'zeilen' => $zeilen,
            'konflikte' => array_values(array_filter(
                $portfolio->konflikte($team, $tag), fn ($k) => $k['brille'] === $this->brille,
            )),
            'ohneZuordnung' => $portfolio->ohneZuordnung($team, $tag),
            'stichtagAnzeige' => ($tag !== null ? \Illuminate\Support\Carbon::parse($tag) : now())->format('d.m.Y'),
        ]);
    }
}
