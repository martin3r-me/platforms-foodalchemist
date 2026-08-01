<?php

namespace Platform\FoodAlchemist\Livewire\Produktion;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Platform\FoodAlchemist\Enums\ProductionOrderStatus;
use Platform\FoodAlchemist\Services\ProductionCapacityService;
use Platform\FoodAlchemist\Services\ProductionOrderService;

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
        'heute' => 'heute',
        'woche' => 'diese Woche',
        'naechste' => 'nächste Woche',
        'vergangen' => 'vergangen',
    ];

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

    public function render(ProductionOrderService $svc, ProductionCapacityService $kap)
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
        ])->layout('platform::layouts.app');
    }
}
