<?php

namespace Platform\FoodAlchemist\Livewire\Produktion;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Platform\FoodAlchemist\Enums\ProductionLineStatus;
use Platform\FoodAlchemist\Enums\ProductionOrderStatus;
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

    /** Gewählter Tag fürs rechte Tagesdetail-Panel. */
    #[Url(as: 'tag')]
    public ?string $selectedDay = null;

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

    /** Wandmonitor: flüchtige Step-Checks je geöffneter Anleitungszeile. Dauerhaft ist die Zeile selbst. */
    public array $anleitungStepStatus = [];

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

        if ($this->modus === 'editor' && $this->display !== 'wall') {
            $this->dispatch('modal.open', name: 'tagesplan-editor');
        }
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

    /** Tag ins Detail-Panel wählen — zweiter Klick auf denselben Tag schließt es. */
    public function waehleTag(?string $tag): void
    {
        $this->selectedDay = $this->selectedDay === $tag ? null : $tag;
    }

    /** Schließt den Fullscreen-Editor und räumt den Deep-Link zurück aufs Dashboard. */
    public function editorSchliessen()
    {
        return redirect(route('foodalchemist.produktion.tagesplan', array_filter([
            'von' => $this->von,
            'bis' => $this->bis,
            'tage' => $this->tage,
            'posten' => $this->postenFilter,
            'ansicht' => $this->ansicht,
            'tag' => $this->selectedDay,
        ])), navigate: true);
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

            $this->zeileSetzenMitWallStart($team, $zeile, $ziel, $svc);
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
    }

    /** Wandmonitor: eine zusammengefasste Mise-en-Place-Komponente gesammelt abhaken. */
    public function abhakenMise(int $lineId, ProductionOrderService $svc, ProductionCapacityService $kap): void
    {
        $this->fehler = null;
        try {
            $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
            [$von, $bis] = $this->zeitraum();
            $zeilen = $kap->tagesplanZeilen($team, $von, $bis, true);
            $referenz = $zeilen->first(fn ($z) => (int) $z->id === $lineId);
            if ($referenz === null) {
                throw new \RuntimeException('Mise-en-Place-Zeile nicht im aktuellen Tagesfenster.');
            }

            $gruppe = $zeilen->filter(fn ($z) => $referenz->recipe_id !== null
                ? (int) $z->recipe_id === (int) $referenz->recipe_id
                : (string) $z->name === (string) $referenz->name);
            $ziel = $gruppe->every(fn ($z) => $z->line_status === ProductionLineStatus::Done->value)
                ? ProductionLineStatus::Open
                : ProductionLineStatus::Done;

            foreach ($gruppe as $z) {
                if ($z->line_status === $ziel->value) {
                    continue;
                }
                $this->zeileSetzenMitWallStart($team, FoodAlchemistProductionOrderLine::findOrFail($z->id), $ziel, $svc);
            }
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
    }

    private function zeileSetzenMitWallStart($team, FoodAlchemistProductionOrderLine $zeile, ProductionLineStatus $ziel, ProductionOrderService $svc): int
    {
        $lineId = (int) $zeile->id;
        if ($this->display === 'wall' && in_array($ziel, [ProductionLineStatus::InProgress, ProductionLineStatus::Done], true)) {
            $order = $zeile->productionOrder;
            $status = $order?->status instanceof ProductionOrderStatus
                ? $order->status
                : ($order?->status !== null ? ProductionOrderStatus::from((string) $order->status) : null);

            if ($order !== null && $status === ProductionOrderStatus::Planned) {
                $svc->setStatus($team, (int) $order->id, ProductionOrderStatus::InProgress);

                $neu = FoodAlchemistProductionOrderLine::query()
                    ->where('production_order_id', $order->id)
                    ->when($zeile->recipe_id !== null, fn ($q) => $q->where('recipe_id', $zeile->recipe_id))
                    ->when($zeile->recipe_id === null, fn ($q) => $q->whereKey($lineId))
                    ->orderBy('position')
                    ->first();

                $lineId = (int) ($neu?->id ?? $lineId);
            }
        }

        $svc->setLineStatus($team, $lineId, $ziel);

        return $lineId;
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

    public function anleitungStarten(ProductionOrderService $svc): void
    {
        $this->fehler = null;
        try {
            if ($this->anleitungLineId === null) {
                return;
            }

            $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
            $this->anleitungLineId = $this->zeileSetzenMitWallStart($team, FoodAlchemistProductionOrderLine::findOrFail($this->anleitungLineId), ProductionLineStatus::InProgress, $svc);
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function anleitungStepUmschalten(int $index, ProductionOrderService $svc, ProductionCapacityService $kap): void
    {
        $this->fehler = null;
        try {
            $anleitung = $this->aktuelleAnleitung($kap);
            if ($anleitung === null || ! array_key_exists($index, $anleitung['arbeitsschritte'])) {
                return;
            }

            $lineId = (int) $anleitung['line_id'];
            $schritte = collect($this->anleitungStepStatus[$lineId] ?? [])->map(fn ($i) => (int) $i);
            $schritte = $schritte->contains($index)
                ? $schritte->reject(fn ($i) => $i === $index)
                : $schritte->push($index);

            $this->anleitungStepStatus[$lineId] = $schritte->unique()->sort()->values()->all();
            $this->anleitungAlsErledigtWennVollstaendig($anleitung, $svc);
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function anleitungAlleStepsUmschalten(ProductionOrderService $svc, ProductionCapacityService $kap): void
    {
        $this->fehler = null;
        try {
            $anleitung = $this->aktuelleAnleitung($kap);
            if ($anleitung === null) {
                return;
            }

            $lineId = (int) $anleitung['line_id'];
            $alle = array_keys($anleitung['arbeitsschritte']);
            $erledigt = collect($this->anleitungStepStatus[$lineId] ?? [])->intersect($alle)->count();
            if ($alle !== [] && $erledigt === count($alle)) {
                $this->anleitungStepStatus[$lineId] = [];
                if (($anleitung['line_status'] ?? null) === ProductionLineStatus::Done->value) {
                    $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
                    $this->anleitungLineId = $this->zeileSetzenMitWallStart($team, FoodAlchemistProductionOrderLine::findOrFail($lineId), ProductionLineStatus::Open, $svc);
                }

                return;
            }

            $this->anleitungStepStatus[$lineId] = $alle;
            $this->anleitungAlsErledigtWennVollstaendig($anleitung, $svc);
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
    }

    private function anleitungAlsErledigtWennVollstaendig(array $anleitung, ProductionOrderService $svc): void
    {
        $lineId = (int) $anleitung['line_id'];
        $alle = array_keys($anleitung['arbeitsschritte']);
        if ($alle === []) {
            return;
        }

        $erledigt = collect($this->anleitungStepStatus[$lineId] ?? [])->intersect($alle)->count();
        if ($erledigt !== count($alle) || ($anleitung['line_status'] ?? null) === ProductionLineStatus::Done->value) {
            return;
        }

        $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
        $this->anleitungLineId = $this->zeileSetzenMitWallStart($team, FoodAlchemistProductionOrderLine::findOrFail($lineId), ProductionLineStatus::Done, $svc);
    }

    private function aktuelleAnleitung(ProductionCapacityService $kap): ?array
    {
        if ($this->anleitungLineId === null) {
            return null;
        }

        $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
        [$von, $bis] = $this->zeitraum();
        $zeilen = $kap->tagesplanZeilen($team, $von, $bis, true);

        return $this->anleitungAufloesen($zeilen);
    }

    public function render(ProductionCapacityService $kap)
    {
        $team = Auth::user()?->currentTeamRelation;
        [$von, $bis] = $this->zeitraum();

        $auslastung = $team !== null ? $kap->auslastung($team, $von, $bis) : [];
        $istWall = $this->display === 'wall';
        $zeilen = $team !== null ? $kap->tagesplanZeilen($team, $von, $bis, $istWall) : collect();

        if ($this->postenFilter !== null) {
            $zeilen = $zeilen->where('station_id', $this->postenFilter);
            $auslastung = collect($auslastung)
                ->map(fn ($b) => array_values(array_filter($b, fn ($x) => $x['station_id'] === $this->postenFilter)))
                ->filter(fn ($b) => $b !== [])->all();
        }

        // Wandmonitor-Extras nur im Wall-Modus rechnen (Readiness zieht eine zweite, teurere Abfrage).
        $readiness = ($istWall && $team !== null) ? app(ProductionReadinessService::class)->findings($team, $von, $bis) : [];
        $miseEnPlace = $istWall ? $this->miseEnPlace($zeilen) : collect();
        $wallPostenGruppen = $istWall ? $this->wallPostenGruppen($zeilen) : collect();
        $anleitung = $istWall ? $this->anleitungAufloesen($zeilen) : null;

        $zeilenNachTag = $zeilen->groupBy(fn ($z) => Carbon::parse($z->plan_date)->toDateString());
        $tagDetail = $this->tagDetail($zeilenNachTag, $auslastung, $von, $bis);

        return view('foodalchemist::livewire.produktion.tagesplan', [
            'modus' => $this->modus,
            'istWall' => $istWall,
            'von' => $von,
            'bis' => $bis,
            'auslastung' => $auslastung,
            'zeilenNachTag' => $zeilenNachTag,
            'dashboard' => $this->dashboard($zeilen, $auslastung, $von, $bis),
            'tagDetail' => $tagDetail,
            'readiness' => $readiness,
            'miseEnPlace' => $miseEnPlace,
            'wallPostenGruppen' => $wallPostenGruppen,
            'anleitung' => $anleitung,
            'postenListe' => $team !== null
                ? FoodAlchemistProductionStation::visibleToTeam($team)->where('is_inactive', false)
                    ->orderBy('sort_order')->orderBy('name')->get(['id', 'name'])
                : collect(),
        ])->layout($istWall ? 'foodalchemist::layouts.kiosk' : 'platform::layouts.app');
    }

    /**
     * Wandmonitor: Küchenlesbare Struktur statt flacher Komponentenliste.
     * Pro Posten erst Auftrag/Gericht, darunter die abhakbaren Rezept- und Basisrezept-Zeilen.
     *
     * @return \Illuminate\Support\Collection<string|int, \Illuminate\Support\Collection<int, object>>
     */
    private function wallPostenGruppen($zeilen): \Illuminate\Support\Collection
    {
        return $zeilen
            ->groupBy(fn ($z) => $z->station_id === null ? '_none' : (int) $z->station_id)
            ->map(fn ($postenZeilen) => $postenZeilen
                ->groupBy(fn ($z) => implode('|', [
                    (int) ($z->order_id ?? 0),
                    (string) ($z->gericht_label ?: ((bool) ($z->is_basisrezept ?? false) ? 'Basisrezepte ohne Gericht' : $z->name)),
                    (string) ($z->liefertag ?? ''),
                ]))
                ->map(function ($gruppe) {
                    $first = $gruppe->first();
                    $teile = $gruppe
                        ->sortBy(fn ($z) => [
                            (bool) ($z->is_basisrezept ?? false) ? 1 : 0,
                            (int) ($z->position ?? 0),
                            (int) ($z->id ?? 0),
                        ])
                        ->values();

                    return (object) [
                        'auftrag' => $first->auftrag,
                        'liefertag' => $first->liefertag,
                        'gericht' => $first->gericht_label ?: ((bool) ($first->is_basisrezept ?? false) ? 'Basisrezepte ohne Gericht' : $first->name),
                        'hat_gericht' => $first->gericht_label !== null,
                        'minuten' => (int) $gruppe->sum('arbeitszeit_min'),
                        'gesamt' => $gruppe->count(),
                        'offen' => $gruppe->reject(fn ($z) => in_array($z->line_status, ['done', 'skipped'], true))->count(),
                        'erledigt' => $gruppe->filter(fn ($z) => $z->line_status === 'done')->count(),
                        'sicherheit' => [
                            'allergene' => $gruppe->flatMap(fn ($z) => $z->sicherheit['allergene'] ?? [])->unique('key')->values(),
                            'diaet' => $gruppe->flatMap(fn ($z) => $z->sicherheit['diaet'] ?? [])->unique()->values(),
                            'warnungen' => $gruppe->flatMap(fn ($z) => $z->sicherheit['warnungen'] ?? [])->unique()->values(),
                        ],
                        'zeilen' => $teile,
                    ];
                })
                ->sortBy(fn ($gruppe) => [
                    (string) $gruppe->liefertag,
                    (string) $gruppe->auftrag,
                    (string) $gruppe->gericht,
                ])
                ->values());
    }

    private function tagDetail($zeilenNachTag, array $auslastung, string $von, string $bis): ?array
    {
        if ($this->selectedDay === null) {
            return null;
        }

        try {
            $tag = Carbon::parse($this->selectedDay)->toDateString();
        } catch (\Throwable) {
            return null;
        }

        if (Carbon::parse($tag)->lt(Carbon::parse($von)) || Carbon::parse($tag)->gt(Carbon::parse($bis))) {
            return null;
        }

        $zeilen = $zeilenNachTag->get($tag, collect());
        $buckets = collect($auslastung[$tag] ?? []);

        return [
            'tag' => $tag,
            'zeilen' => $zeilen,
            'posten' => $zeilen->groupBy(fn ($z) => $z->station_id === null ? '_none' : (int) $z->station_id),
            'auslastung' => $buckets,
            'minuten' => (int) $zeilen->sum('arbeitszeit_min'),
        ];
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
                    'erledigt' => $grp->filter(fn ($z) => $z->line_status === 'done')->count(),
                    'sicherheit' => [
                        'allergene' => $grp->flatMap(fn ($z) => $z->sicherheit['allergene'] ?? [])->unique('key')->values(),
                        'diaet' => $grp->flatMap(fn ($z) => $z->sicherheit['diaet'] ?? [])->unique()->values(),
                        'warnungen' => $grp->flatMap(fn ($z) => $z->sicherheit['warnungen'] ?? [])->unique()->values(),
                    ],
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
        $treffer = $zeilen->first(fn ($z) => (int) $z->id === (int) $this->anleitungLineId);
        if ($treffer === null) {
            return null;   // nicht im aktuellen (team-strikten) Fenster → nichts zeigen
        }
        $line = FoodAlchemistProductionOrderLine::find($this->anleitungLineId);

        return [
            'line_id' => (int) $treffer->id,
            'name' => $treffer->name,
            'auftrag' => $treffer->auftrag,
            'line_status' => $treffer->line_status,
            'gesamt_kg' => $treffer->gesamt_kg,
            'arbeitszeit_min' => $treffer->arbeitszeit_min !== null ? (int) $treffer->arbeitszeit_min : null,
            'started_at' => $treffer->started_at ? Carbon::parse($treffer->started_at)->toIso8601String() : null,
            'equipment' => collect($treffer->equipment ?? [])->map(fn ($e) => [
                'name' => $e->name,
                'gruppe' => $e->group_name,
                'notiz' => $e->note,
            ])->values()->all(),
            'schritte' => $treffer->schritte ?? ($line?->steps_snapshot ?? []),
            'zubereitung' => $treffer->zubereitung ?? $line?->zubereitung,
            'zutaten' => $treffer->zutaten ?? ($line?->zutaten ?? []),
            'sicherheit' => $treffer->sicherheit ?? ['allergene' => [], 'diaet' => [], 'warnungen' => [], 'konfidenz' => 'unknown'],
            'arbeitsschritte' => $this->arbeitsschritte($treffer->schritte ?? ($line?->steps_snapshot ?? []), $treffer->zubereitung ?? $line?->zubereitung),
            'step_erledigt' => collect($this->anleitungStepStatus[(int) $treffer->id] ?? [])->map(fn ($i) => (int) $i)->unique()->values()->all(),
        ];
    }

    private function arbeitsschritte(array $schritte, ?string $zubereitung): array
    {
        if ($schritte !== []) {
            return collect($schritte)
                ->values()
                ->map(fn ($s, $i) => ['index' => (int) $i, 'text' => (string) ($s['text'] ?? ''), 'heading' => false])
                ->filter(fn ($s) => trim($s['text']) !== '')
                ->values()
                ->mapWithKeys(fn ($s) => [$s['index'] => $s])
                ->all();
        }

        return collect(preg_split('/\R+/', (string) $zubereitung))
            ->map(fn ($line) => trim($line))
            ->filter()
            ->values()
            ->reject(fn ($line) => str_starts_with($line, '##'))
            ->map(fn ($line) => trim(preg_replace('/^#+\s*/', '', $line)))
            ->filter()
            ->values()
            ->mapWithKeys(fn ($line, $i) => [(int) $i => ['index' => (int) $i, 'text' => $line, 'heading' => false]])
            ->all();
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
