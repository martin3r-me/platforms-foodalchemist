<?php

namespace Platform\FoodAlchemist\Livewire\Produktion;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Platform\FoodAlchemist\Enums\ProductionLineStatus;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionOrderLine;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionStation;
use Platform\FoodAlchemist\Services\ProductionCapacityService;
use Platform\FoodAlchemist\Services\ProductionOrderService;
use Platform\FoodAlchemist\Services\ProductionPlanService;
use Platform\FoodAlchemist\Services\ProductionReadinessService;

/**
 * Spec 30 E3 — Tagesplan: was steht an welchem Tag an welchem Posten an, über ALLE Aufträge.
 *
 * Der Auftrag bleibt ein Punkt (ein Liefertag), aber seine Zeilen dürfen davor liegen
 * (`vorlauf_tage`). Diese Sicht ist deshalb eine Abfrage über Zeilen, keine neue
 * Auftrags-Struktur — Spec-18-Nicht-Ziel „Mehrtages-Produktionszeiträume" bleibt gewahrt.
 *
 * Jede Zeile trägt ihren Auftrag und den Liefertag als Kontext mit: am Posten weiß sonst
 * niemand, wofür der Fond eigentlich ist.
 */
class Tagesplan extends Component
{
    #[Url(as: 'von')]
    public string $von = '';

    #[Url(as: 'bis')]
    public ?string $bis = null;

    #[Url(as: 'tage')]
    public int $tage = 14;

    #[Url(as: 'posten')]
    public ?int $postenFilter = null;

    /** Gewählter Auftrag fürs Detail-Panel (3-Panel-Layout wie im Produktions-Browser). */
    #[Url(as: 'auftrag')]
    public ?int $orderId = null;

    /** Ausgabe-Modus: '' = normal (3 Panels), 'wall' = Küchen-Wandmodus (chrome-arm, groß). */
    #[Url(as: 'display')]
    public string $display = '';

    #[Url(as: 'ansicht')]
    public string $ansicht = 'posten';

    /** Dashboard = Leitstand, Editor = Koch-/Planungsarbeitsplatz. Wird über die Route gesetzt. */
    public string $modus = 'dashboard';

    public ?string $fehler = null;

    /** Stufe 3 P3.4 — der zuletzt gerechnete Planungs-Vorschlag (Review vor Übernahme), oder null. */
    public ?array $vorschlag = null;

    /** Wandmonitor (Spec 35): Ansicht der Wand — 'lanes' (nach Posten) | 'mise' (zusammengefasst). */
    public string $wallAnsicht = 'lanes';

    /** Wandmonitor: Zeile, deren Anleitung gerade im Overlay offen ist (null = zu). */
    public ?int $anleitungLineId = null;

    private const DASHBOARD_FENSTER = [3, 7, 14, 30];

    public function mount(): void
    {
        $this->modus = request()->routeIs('foodalchemist.produktion.tagesplan.editor')
            || request()->routeIs('foodalchemist.produktion.wandmonitor')
                ? 'editor'
                : 'dashboard';

        if (request()->routeIs('foodalchemist.produktion.wandmonitor')) {
            $this->display = 'wall';
            if (! request()->has('tage')) {
                $this->tage = 1;
            }
        }

        if ($this->von === '') {
            $this->von = now()->toDateString();
        }
        [$this->von, $this->bis] = $this->zeitraum();
    }

    public function verschiebe(int $tage): void
    {
        [$von, $bis] = $this->zeitraum();
        $this->von = Carbon::parse($von)->addDays($tage)->toDateString();
        $this->bis = Carbon::parse($bis)->addDays($tage)->toDateString();
    }

    public function heute(): void
    {
        $tage = max(1, min(60, $this->tage));
        $this->von = now()->toDateString();
        $this->bis = now()->addDays($tage - 1)->toDateString();
    }

    public function updatedTage(): void
    {
        $this->tage = max(1, min(60, (int) $this->tage));
        $this->bis = Carbon::parse($this->von ?: now()->toDateString())->addDays($this->tage - 1)->toDateString();
    }

    public function updatedVon(): void
    {
        [$this->von, $this->bis] = $this->zeitraum();
    }

    public function updatedBis(): void
    {
        [$this->von, $this->bis] = $this->zeitraum();
    }

    public function waehleDashboardFenster(int $tage): void
    {
        if (in_array($tage, self::DASHBOARD_FENSTER, true)) {
            $this->tage = $tage;
            $this->bis = Carbon::parse($this->von ?: now()->toDateString())->addDays($tage - 1)->toDateString();
        }
    }

    public function dashboardTagVerschieben(int $tage): void
    {
        $delta = max(-14, min(14, $tage));
        $this->verschiebe($delta);
    }

    public function dashboardHeute(): void
    {
        $this->heute();
    }

    public function postenWaehlen(?int $id): void
    {
        $this->postenFilter = $this->postenFilter === $id ? null : $id;
    }

    /** Auftrag ins Detail-Panel wählen — zweiter Klick auf dieselbe Zeile schließt es. */
    public function waehleAuftrag(?int $id): void
    {
        $this->orderId = $this->orderId === $id ? null : $id;
    }

    /** Stufe 3 P3.4 — Planungs-Vorschlag über das aktuelle Fenster rechnen (schreibt nichts). */
    public function vorschlagen(ProductionPlanService $planer): void
    {
        $this->fehler = null;
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null) {
            $this->fehler = 'Kein Team zugeordnet.';

            return;
        }
        [$von, $bis] = $this->zeitraum();
        $this->vorschlag = $planer->schlage($team, $von, $bis);
    }

    public function vorschlagUebernehmen(ProductionPlanService $planer): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->vorschlag === null) {
            return;
        }
        $planer->uebernehmen($team, $this->vorschlag['vorschlag']);
        $this->vorschlag = null;
    }

    public function vorschlagVerwerfen(): void
    {
        $this->vorschlag = null;
    }

    /** Zeile auf einen anderen Tag ziehen — der Vorlauf folgt dem Liefertag, nicht umgekehrt. */
    public function vorlaufSetzen(int $lineId, string $wert, ProductionOrderService $svc): void
    {
        $this->fehler = null;
        try {
            $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
            $svc->assignLine($team, $lineId, ['vorlauf_tage' => (int) $wert]);
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
    }

    /**
     * Spec 30 E6 — Zeile abhaken. Der Tagesplan IST die Küchen-Sicht: nach Posten gefiltert
     * arbeitet man hier die Positionen des Tages ab. Ein zweiter Klick nimmt den Haken zurück
     * (Checkliste, kein Beleg-Lebenszyklus).
     */
    public function abhaken(int $lineId, ProductionOrderService $svc): void
    {
        $this->fehler = null;
        try {
            $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
            $zeile = FoodAlchemistProductionOrderLine::findOrFail($lineId);
            $ziel = $zeile->line_status === ProductionLineStatus::Done
                ? ProductionLineStatus::Open
                : ProductionLineStatus::Done;
            $svc->setLineStatus($team, $lineId, $ziel);
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
    }

    /** Wandmonitor (Spec 35): zwischen Posten-Lanes und zusammengefasster Mise-en-Place umschalten. */
    public function wallAnsichtSetzen(string $a): void
    {
        $this->wallAnsicht = in_array($a, ['lanes', 'mise'], true) ? $a : 'lanes';
    }

    /** Wandmonitor: Anleitung (Schritte + Zutaten) einer Zeile im Overlay öffnen. */
    public function oeffneAnleitung(int $lineId): void
    {
        $this->anleitungLineId = $lineId;
        $this->dispatch('modal.open', name: 'wall-anleitung');
    }

    public function anleitungSchliessen(): void
    {
        $this->anleitungLineId = null;
    }

    public function render(ProductionCapacityService $kap)
    {
        $team = Auth::user()?->currentTeamRelation;
        [$von, $bis] = $this->zeitraum();

        $auslastung = $team !== null ? $kap->auslastung($team, $von, $bis) : [];
        $zeilen = $team !== null ? $kap->tagesplanZeilen($team, $von, $bis) : collect();

        if ($this->postenFilter !== null) {
            $zeilen = $zeilen->where('station_id', $this->postenFilter);
            $auslastung = collect($auslastung)
                ->map(fn ($b) => array_values(array_filter($b, fn ($x) => $x['station_id'] === $this->postenFilter)))
                ->filter(fn ($b) => $b !== [])->all();
        }

        // Wandmonitor-Extras nur im Wall-Modus rechnen (Readiness zieht eine zweite, teurere Abfrage).
        $istWall = $this->display === 'wall';
        $readiness = ($istWall && $team !== null) ? app(ProductionReadinessService::class)->findings($team, $von, $bis) : [];
        $miseEnPlace = $istWall ? $this->miseEnPlace($zeilen) : collect();
        $anleitung = $istWall ? $this->anleitungAufloesen($zeilen) : null;

        return view('foodalchemist::livewire.produktion.tagesplan', [
            'modus' => $this->modus,
            'istWall' => $istWall,
            'von' => $von,
            'bis' => $bis,
            'auslastung' => $auslastung,
            'zeilenNachTag' => $zeilen->groupBy(fn ($z) => Carbon::parse($z->plan_date)->toDateString()),
            'dashboard' => $this->dashboard($zeilen, $auslastung, $von, $bis),
            'readiness' => $readiness,
            'miseEnPlace' => $miseEnPlace,
            'anleitung' => $anleitung,
            'postenListe' => $team !== null
                ? FoodAlchemistProductionStation::visibleToTeam($team)->where('is_inactive', false)
                    ->orderBy('sort_order')->orderBy('name')->get(['id', 'name'])
                : collect(),
        ])->layout('platform::layouts.app');
    }

    /**
     * Wunsch #3 „Zusammenfassen": gleiche Komponente (recipe_id) über ALLE Gerichte/Aufträge des
     * Fensters bündeln — Mise-en-Place-Sicht. Der Fond für drei Events ist EIN Produktionsposten,
     * nicht drei. Read-only Aggregat; abgehakt wird weiter je Zeile in den Lanes (in_progress-Gate).
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function miseEnPlace($zeilen): \Illuminate\Support\Collection
    {
        return $zeilen->groupBy(fn ($z) => $z->recipe_id !== null ? 'r:' . $z->recipe_id : 't:' . $z->name)
            ->map(function ($grp) {
                $first = $grp->first();

                return (object) [
                    'name' => $first->name,
                    'ist_basisrezept' => (bool) ($first->is_basisrezept ?? false),
                    'auftraege' => $grp->pluck('auftrag')->filter()->unique()->values(),
                    'anzahl' => $grp->count(),
                    'ansaetze' => (float) $grp->sum('ansaetze_effektiv'),
                    'minuten' => (int) $grp->sum('arbeitszeit_min'),
                    'stationen' => $grp->pluck('station')->filter()->unique()->values(),
                    'offen' => $grp->reject(fn ($z) => in_array($z->line_status, ['done', 'skipped'], true))->count(),
                    'gesamt' => $grp->count(),
                    'erste_line_id' => $first->id,
                ];
            })
            ->sortByDesc(fn ($m) => [$m->anzahl, $m->minuten])->values();
    }

    /** Wandmonitor: die im Overlay gewählte Zeile team-sicher (gegen die Fenster-Zeilen) auflösen + Schritte/Zutaten laden. */
    private function anleitungAufloesen($zeilen): ?array
    {
        if ($this->anleitungLineId === null) {
            return null;
        }
        $treffer = $zeilen->firstWhere('id', $this->anleitungLineId);
        if ($treffer === null) {
            return null;   // nicht im aktuellen (team-strikten) Fenster → nichts zeigen
        }
        $line = FoodAlchemistProductionOrderLine::find($this->anleitungLineId);
        if ($line === null) {
            return null;
        }

        return [
            'name' => $treffer->name,
            'auftrag' => $treffer->auftrag,
            'schritte' => $line->steps_snapshot ?? [],
            'zubereitung' => $line->zubereitung,
            'zutaten' => $line->zutaten ?? [],
        ];
    }

    /** @return array<string, mixed> */
    private function dashboard($zeilen, array $auslastung, string $von, string $bis): array
    {
        $start = Carbon::parse($von)->startOfDay();
        $ende = Carbon::parse($bis)->startOfDay();
        $tage = (int) $start->diffInDays($ende) + 1;
        $tageListe = collect(range(0, $tage - 1))->map(fn ($i) => $start->copy()->addDays($i)->toDateString())->values();
        $zeilenNachTag = $zeilen->groupBy(fn ($z) => Carbon::parse($z->plan_date)->toDateString());
        $buckets = collect($auslastung)->flatten(1);
        $stationen = $buckets->pluck('station')->filter()->unique()->values();

        $matrix = $stationen->mapWithKeys(function (string $station) use ($tageListe, $auslastung) {
            return [$station => $tageListe->mapWithKeys(function (string $tag) use ($station, $auslastung) {
                return [$tag => collect($auslastung[$tag] ?? [])->firstWhere('station', $station)];
            })->all()];
        })->all();

        $manntage = $tageListe->map(function (string $tag) use ($zeilenNachTag) {
            $minuten = (int) $zeilenNachTag->get($tag, collect())->sum('arbeitszeit_min');

            return [
                'tag' => $tag,
                'minuten' => $minuten,
                'wert' => round($minuten / 480, 1),
            ];
        })->values();

        $performance = $buckets->groupBy('station')->map(function ($items, string $station) {
            $minuten = (int) collect($items)->sum('geplant_min');
            $kapazitaet = (int) collect($items)->sum(fn ($b) => (int) ($b['kapazitaet_min'] ?? 0));

            return [
                'station' => $station,
                'minuten' => $minuten,
                'kapazitaet' => $kapazitaet,
                'prozent' => $kapazitaet > 0 ? min(200, (int) round($minuten / $kapazitaet * 100)) : null,
                'kritisch' => collect($items)->where('stufe', 'ueberlast')->count(),
                'eng' => collect($items)->where('stufe', 'eng')->count(),
            ];
        })->sortByDesc(fn ($p) => ($p['kritisch'] * 1000000) + ($p['eng'] * 10000) + $p['minuten'])->values();

        $offen = $zeilen->reject(fn ($z) => in_array($z->line_status, ['done', 'skipped'], true));

        return [
            'fenster' => $tage,
            'von' => $von,
            'bis' => $bis,
            'tage' => $tageListe,
            'zeilen' => $zeilen,
            'zeilenNachTag' => $zeilenNachTag,
            'auslastung' => $auslastung,
            'matrix' => $matrix,
            'naechstes' => $zeilen->sortBy('plan_date')->take(8)->values(),
            'manntage' => $manntage,
            'maxManntage' => max(1, (float) $manntage->max('wert')),
            'produktionAmpeln' => [
                'puenktlich' => $buckets->where('stufe', 'ok')->count(),
                'verspaetet' => $buckets->where('stufe', 'eng')->count(),
                'kritisch' => $buckets->where('stufe', 'ueberlast')->count(),
            ],
            'performance' => $performance,
            'kpis' => [
                'speisen' => $zeilen->count(),
                'offen' => $offen->count(),
                'minuten' => (int) $zeilen->sum('arbeitszeit_min'),
                'ueberlast' => $buckets->where('stufe', 'ueberlast')->count(),
                'posten' => $buckets->pluck('station_id')->filter()->unique()->count(),
            ],
        ];
    }

    /** @return array{0:string,1:string} */
    private function zeitraum(): array
    {
        try {
            $von = Carbon::parse($this->von ?: now()->toDateString())->startOfDay();
        } catch (\Throwable) {
            $von = now()->startOfDay();
        }

        try {
            $bis = $this->bis ? Carbon::parse($this->bis)->startOfDay() : $von->copy()->addDays(max(1, min(60, $this->tage)) - 1);
        } catch (\Throwable) {
            $bis = $von->copy()->addDays(max(1, min(60, $this->tage)) - 1);
        }

        if ($bis->lt($von)) {
            $bis = $von->copy();
        }
        if ((int) $von->diffInDays($bis) >= 60) {
            $bis = $von->copy()->addDays(59);
        }

        $this->von = $von->toDateString();
        $this->bis = $bis->toDateString();
        $this->tage = (int) $von->diffInDays($bis) + 1;

        return [$this->von, $this->bis];
    }
}
