<?php

namespace Platform\FoodAlchemist\Livewire\Produktion;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Carbon;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Platform\FoodAlchemist\Enums\ProductionOrderStatus;
use Platform\FoodAlchemist\Services\ProductionCapacityService;
use Platform\FoodAlchemist\Services\ProductionOrderService;
use Platform\FoodAlchemist\Services\ProductionReadinessService;

/**
 * Spec 18/30 — Produktion: Browser-Liste der Produktionsaufträge.
 *
 * Spec 30 E4: die Liste filtert und paginiert jetzt SERVERSEITIG. Vorher lud sie die volle
 * Menge des Teams in den Speicher und filterte in PHP, ohne Pagination — offener
 * Audit-Befund MVP-033. Dazu Zeitraum-Presets, Spalten-Ansichten und eine KPI-Zeile nach
 * dem Muster der reifen Browser (Gerichte/GPs).
 */
class Browser extends Component
{
    use WithPagination;

    #[Url(as: 'auftrag')]
    public ?int $orderId = null;

    #[Url(as: 's')]
    public string $statusFilter = '';

    #[Url(as: 'von')]
    public ?string $von = null;

    #[Url(as: 'bis')]
    public ?string $bis = null;

    #[Url(as: 'q')]
    public string $suche = '';

    #[Url(as: 'zeitraum')]
    public string $zeitraum = '';

    #[Url(as: 'ansicht')]
    public string $ansicht = 'standard';

    #[Url(as: 'zeilen')]
    public int $perPage = 50;

    /** Hauptseiten-Dashboard: Küchenchef-Fenster, bewusst NICHT der Koch-Detail-Editor. */
    public int $dashboardTage = 7;

    /** Starttag des Leitstands. Unabhängig vom Listenfilter, damit Steuerung und Suche getrennt bleiben. */
    public ?string $dashboardVon = null;

    /** Endtag des Leitstands. Optional: wenn leer, gilt das Schnellfenster ab Starttag. */
    public ?string $dashboardBis = null;

    /**
     * Spalten-Katalog. Die Reihenfolge hier ist zugleich Anzeige- und `<td>`-Reihenfolge —
     * Kopf und Zellen laufen über denselben Katalog, sonst versetzt sich die Tabelle, sobald
     * eine Ansicht eine Spalte auslässt (Muster: Verkauf\Browser).
     */
    public const SPALTEN = [
        'ziele' => ['Ziele', ''],
        'ansaetze' => ['Ansätze', 'text-right'],
        'portionen' => ['Portionen', 'text-right'],
        'zeit' => ['Arbeitszeit', 'text-right'],
        'posten' => ['Posten', ''],
        'datum' => ['Datum', ''],
        'status' => ['Status', ''],
        'einkauf' => ['Einkauf', ''],
    ];

    public const ANSICHTEN = [
        'standard' => ['Standard', ['ziele', 'ansaetze', 'datum', 'status', 'einkauf']],
        'kueche' => ['Küche', ['ansaetze', 'portionen', 'zeit', 'posten', 'datum', 'status']],
        'einkauf' => ['Einkauf', ['ziele', 'datum', 'status', 'einkauf']],
    ];

    /** Zeitraum-Presets — häufiger gebraucht als zwei getippte Daten. */
    public const ZEITRAEUME = [
        'tage3' => '3 Tage',
        'tage7' => '7 Tage',
        'tage14' => '14 Tage',
        'heute' => 'heute',
        'woche' => 'diese Woche',
        'naechste' => 'nächste Woche',
        'vergangen' => 'vergangen',
    ];

    private const DASHBOARD_FENSTER = [3, 7, 14, 30];

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedSuche(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    /** Toggle-Semantik wie in allen Browsern: derselbe Wert nochmal = Filter aus. */
    public function waehleStatus(string $wert): void
    {
        $this->statusFilter = $this->statusFilter === $wert ? '' : $wert;
        $this->resetPage();
    }

    public function waehleZeitraum(string $key): void
    {
        $this->zeitraum = $this->zeitraum === $key ? '' : $key;
        [$this->von, $this->bis] = $this->zeitraumGrenzen($this->zeitraum);
        $this->resetPage();
    }

    public function waehleDashboardFenster(int $tage): void
    {
        if (in_array($tage, self::DASHBOARD_FENSTER, true)) {
            $this->dashboardTage = $tage;
            $this->dashboardBis = $this->dashboardStart()->addDays($tage - 1)->toDateString();
        }
    }

    public function dashboardTagVerschieben(int $tage): void
    {
        $aktuellVon = $this->dashboardStart();
        $aktuellBis = $this->dashboardEnd($aktuellVon);
        $delta = max(-14, min(14, $tage));
        $this->dashboardVon = $aktuellVon->addDays($delta)->toDateString();
        $this->dashboardBis = $aktuellBis->addDays($delta)->toDateString();
    }

    public function dashboardHeute(): void
    {
        $tage = max(1, min(60, (int) $this->dashboardTage));
        $this->dashboardVon = now()->toDateString();
        $this->dashboardBis = now()->addDays($tage - 1)->toDateString();
    }

    public function waehleAnsicht(string $key): void
    {
        if (array_key_exists($key, self::ANSICHTEN)) {
            $this->ansicht = $key;
        }
    }

    public function neuerAuftrag(): void
    {
        $this->dispatch('produktion-editor.oeffnen');
    }

    /**
     * Zeilen-Klick wählt fürs Detail-Panel, der Name öffnet den Editor (Muster: Gerichte).
     *
     * Die Auswahl läuft über das EVENT, nicht über einen `:key`-Remount des Panels: ein
     * Remount würfe bei jedem Klick den Panel-eigenen Zustand (Hinweis/Fehler) weg. Der
     * `order-id`-Prop deckt nur den Deep-Link `?auftrag=…` beim Seitenaufruf ab.
     */
    public function waehle(int $id): void
    {
        $this->orderId = $id;
        $this->dispatch('production-order-selected', id: $id);
    }

    #[On('produktion-gespeichert')]
    public function aktualisiere(int $id): void
    {
        $this->orderId = $id;
        $this->dispatch('production-order-selected', id: $id);
    }

    /** Gelöschter Auftrag: Auswahl fallen lassen, sonst zeigt das Panel eine Leiche. */
    #[On('produktion-geloescht')]
    public function verwerfeAuswahl(): void
    {
        $this->orderId = null;
        $this->dispatch('production-order-selected', id: 0);
    }

    #[On('produktion-status-geaendert')]
    public function aktualisiereListe(): void
    {
        // Re-render holt den neuen Status (Cross-Component-Refresh, kein State nötig).
    }

    /** @return array{0: ?string, 1: ?string} */
    private function zeitraumGrenzen(string $key): array
    {
        $heute = now()->startOfDay();

        return match ($key) {
            'tage3' => [$heute->toDateString(), $heute->copy()->addDays(2)->toDateString()],
            'tage7' => [$heute->toDateString(), $heute->copy()->addDays(6)->toDateString()],
            'tage14' => [$heute->toDateString(), $heute->copy()->addDays(13)->toDateString()],
            'heute' => [$heute->toDateString(), $heute->toDateString()],
            'woche' => [$heute->copy()->startOfWeek()->toDateString(), $heute->copy()->endOfWeek()->toDateString()],
            'naechste' => [$heute->copy()->addWeek()->startOfWeek()->toDateString(), $heute->copy()->addWeek()->endOfWeek()->toDateString()],
            'vergangen' => [null, $heute->copy()->subDay()->toDateString()],
            default => [null, null],
        };
    }

    /** @return list<string> sichtbare Spalten-Keys in Katalog-Reihenfolge */
    public function spalten(): array
    {
        $menge = (self::ANSICHTEN[$this->ansicht] ?? self::ANSICHTEN['standard'])[1];

        return array_values(array_filter(array_keys(self::SPALTEN), fn ($k) => in_array($k, $menge, true)));
    }

    public function render(ProductionOrderService $svc, ProductionCapacityService $kap, ProductionReadinessService $readiness)
    {
        $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');

        $filters = ['status' => $this->statusFilter, 'von' => $this->von, 'bis' => $this->bis, 'suche' => $this->suche];
        $auftraege = $svc->paginateBrowser($team, $filters, $this->perPage);

        // Kein N+1: Zeilen der GELISTETEN Aufträge in einer Query, Einkaufs-Indikator gebündelt.
        $auftraege->getCollection()->load([
            'lines:id,production_order_id,ansaetze,manual_ansaetze,is_manual_ansaetze,is_struck,portionen,arbeitszeit_min,station_id',
            'lines.station:id,name',
        ]);

        // KPI-Zeile: „wie ist die Lage" — bewusst UNABHÄNGIG von den Filtern, sonst
        // beantwortet sie nur noch „was habe ich gerade gefiltert".
        $heute = now()->toDateString();
        $heuteMinuten = collect($kap->auslastung($team, $heute, $heute))->flatten(1)->sum('geplant_min');

        $dashboard = $this->dashboard($team, $kap, $readiness);

        return view('foodalchemist::livewire.produktion.browser', [
            'auftraege' => $auftraege,
            'indikatoren' => $svc->einkaufsIndikatoren($team, $auftraege->getCollection()->pluck('id')->all()),
            'statusFaelle' => ProductionOrderStatus::cases(),
            'statusCounts' => $svc->statusCounts($team, $filters),
            'gesamtCount' => $svc->browserGesamt($team, $filters),
            'spalten' => $this->spalten(),
            'spaltenKatalog' => self::SPALTEN,
            'ansichten' => self::ANSICHTEN,
            'zeitraeume' => self::ZEITRAEUME,
            'kpiOffen' => $svc->statusCounts($team, [])[ProductionOrderStatus::Planned->value] ?? 0,
            'kpiHeuteMinuten' => (int) $heuteMinuten,
            'kpiHeuteAuftraege' => $svc->browserGesamt($team, ['von' => $heute, 'bis' => $heute]),
            'dashboard' => $dashboard,
        ])->layout('platform::layouts.app');
    }

    /** @return array<string, mixed> */
    private function dashboard($team, ProductionCapacityService $kap, ProductionReadinessService $readiness): array
    {
        $start = $this->dashboardStart();
        $ende = $this->dashboardEnd($start);
        $tage = (int) $start->diffInDays($ende) + 1;
        $von = $start->toDateString();
        $bis = $ende->toDateString();
        $tageListe = collect(range(0, $tage - 1))->map(fn ($i) => $start->copy()->addDays($i)->toDateString())->values();

        $zeilen = $kap->tagesplanZeilen($team, $von, $bis, true);
        $auslastung = $kap->auslastung($team, $von, $bis);
        $readinessSplit = $readiness->split($team, $von, $bis);

        $zeilenNachTag = $zeilen->groupBy(fn ($z) => Carbon::parse($z->plan_date)->toDateString());
        $buckets = collect($auslastung)->flatten(1);
        $stationen = collect($auslastung)->flatten(1)->pluck('station')->unique()->values();
        $matrix = $stationen->mapWithKeys(function (string $station) use ($tageListe, $auslastung) {
            return [$station => $tageListe->mapWithKeys(function (string $tag) use ($station, $auslastung) {
                $bucket = collect($auslastung[$tag] ?? [])->firstWhere('station', $station);

                return [$tag => $bucket];
            })->all()];
        })->all();

        $offen = $zeilen->reject(fn ($z) => in_array($z->line_status, ['done', 'skipped'], true));
        $manntage = $tageListe->map(function (string $tag) use ($zeilenNachTag) {
            $minuten = (int) $zeilenNachTag->get($tag, collect())->sum('arbeitszeit_min');

            return [
                'tag' => $tag,
                'minuten' => $minuten,
                'wert' => round($minuten / 480, 1),
            ];
        })->values();
        $maxManntage = max(1, (float) $manntage->max('wert'));
        $produktionAmpeln = [
            'puenktlich' => $buckets->where('stufe', 'ok')->count(),
            'verspaetet' => $buckets->where('stufe', 'eng')->count(),
            'kritisch' => $buckets->where('stufe', 'ueberlast')->count(),
        ];
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

        return [
            'fenster' => $tage,
            'von' => $von,
            'bis' => $bis,
            'tage' => $tageListe,
            'zeilen' => $zeilen,
            'zeilenNachTag' => $zeilenNachTag,
            'auslastung' => $auslastung,
            'matrix' => $matrix,
            'naechstes' => $kap->alsNaechstes($zeilen, 8),
            'events' => $kap->letzteAenderungen($team, $start->copy()->subDays(2)->toDateString(), $bis, 6),
            'readiness' => $readinessSplit,
            'manntage' => $manntage,
            'maxManntage' => $maxManntage,
            'produktionAmpeln' => $produktionAmpeln,
            'performance' => $performance,
            'kpis' => [
                'speisen' => $zeilen->count(),
                'offen' => $offen->count(),
                'minuten' => (int) $zeilen->sum('arbeitszeit_min'),
                'ueberlast' => $buckets->where('stufe', 'ueberlast')->count(),
                'klaerfaelle' => count($readinessSplit['blockers']) + count($readinessSplit['warnings']),
            ],
        ];
    }

    private function dashboardStart(): Carbon
    {
        try {
            return $this->dashboardVon ? Carbon::parse($this->dashboardVon)->startOfDay() : now()->startOfDay();
        } catch (\Throwable) {
            $this->dashboardVon = null;

            return now()->startOfDay();
        }
    }

    private function dashboardEnd(Carbon $start): Carbon
    {
        try {
            $ende = $this->dashboardBis ? Carbon::parse($this->dashboardBis)->startOfDay() : $start->copy()->addDays(max(1, min(60, $this->dashboardTage)) - 1);
        } catch (\Throwable) {
            $this->dashboardBis = null;
            $ende = $start->copy()->addDays(max(1, min(60, $this->dashboardTage)) - 1);
        }

        if ($ende->lt($start)) {
            $ende = $start->copy();
            $this->dashboardBis = $ende->toDateString();
        }

        if ((int) $start->diffInDays($ende) >= 60) {
            $ende = $start->copy()->addDays(59);
            $this->dashboardBis = $ende->toDateString();
        }

        $this->dashboardTage = (int) $start->diffInDays($ende) + 1;

        return $ende;
    }
}
