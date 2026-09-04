<?php

namespace Platform\FoodAlchemist\Livewire\Produktion;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Url;
use Livewire\Component;
use Platform\Core\Models\ContextFile;
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

    /** Wandmonitor: Filter innerhalb der Posten-Lanes — alle | gerichte | basis. */
    public string $wallGruppenFilter = 'alle';

    /** Wandmonitor: Zeile, deren Anleitung gerade im Overlay offen ist (null = zu). */
    public ?int $anleitungLineId = null;

    /** Wandmonitor: Gericht-/Arbeitsblock, dessen enthaltene Rezepte gerade offen sind. */
    public ?string $wallGerichtKey = null;

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
        return $this->redirect(route('foodalchemist.produktion.tagesplan', array_filter([
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

    public function wallGruppenFilterSetzen(string $filter): void
    {
        $this->wallGruppenFilter = in_array($filter, ['alle', 'gerichte', 'basis'], true) ? $filter : 'alle';
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

    public function oeffneGericht(string $key): void
    {
        $this->wallGerichtKey = $key;
        $this->dispatch('modal.open', name: 'wall-gericht');
    }

    public function gerichtSchliessen(): void
    {
        $this->wallGerichtKey = null;
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
        $wallGericht = $istWall ? $this->wallGerichtAufloesen($wallPostenGruppen) : null;

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
            'wallGericht' => $wallGericht,
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
                    $z->liefertag !== null ? Carbon::parse($z->liefertag)->toDateString() : '',
                ]))
                ->map(function ($gruppe, $key) use ($postenZeilen) {
                    $first = $gruppe->first();
                    $teile = $gruppe
                        ->sortBy(fn ($z) => [
                            (bool) ($z->is_basisrezept ?? false) ? 1 : 0,
                            (int) ($z->position ?? 0),
                            (int) ($z->id ?? 0),
                        ])
                        ->values();
                    $arbeitsZeilen = $this->wallGerichtArbeitsZeilen($teile, $postenZeilen);

                    return (object) [
                        'key' => (string) $key,
                        'auftrag' => $first->auftrag,
                        'liefertag' => $first->liefertag,
                        'gericht' => $first->gericht_label ?: ((bool) ($first->is_basisrezept ?? false) ? 'Basisrezepte ohne Gericht' : $first->name),
                        'hat_gericht' => $first->gericht_label !== null,
                        'minuten' => (int) $arbeitsZeilen->sum('arbeitszeit_min'),
                        'gesamt' => $arbeitsZeilen->count(),
                        'offen' => $arbeitsZeilen->reject(fn ($z) => in_array($z->line_status, ['done', 'skipped'], true))->count(),
                        'erledigt' => $arbeitsZeilen->filter(fn ($z) => $z->line_status === 'done')->count(),
                        'sicherheit' => [
                            'allergene' => $arbeitsZeilen->flatMap(fn ($z) => $z->sicherheit['allergene'] ?? [])->unique('key')->values(),
                            'diaet' => $arbeitsZeilen->flatMap(fn ($z) => $z->sicherheit['diaet'] ?? [])->unique()->values(),
                            'warnungen' => $arbeitsZeilen->flatMap(fn ($z) => $z->sicherheit['warnungen'] ?? [])->unique()->values(),
                        ],
                        'anrichten' => $this->wallGerichtAnrichten($teile),
                        'anrichten_schritte' => $this->wallGerichtAnrichtenSchritte($teile),
                        'regeneration' => $this->wallGerichtRegeneration($teile),
                        'darreichung' => $this->wallGerichtDarreichung($teile),
                        'behaelter' => $this->wallGerichtBehaelter($teile),
                        'rezept_uebersicht' => $this->wallGerichtRezeptUebersicht($arbeitsZeilen),
                        'zeilen' => $arbeitsZeilen,
                    ];
                })
                ->sortBy(fn ($gruppe) => [
                    (string) $gruppe->liefertag,
                    (string) $gruppe->auftrag,
                    (string) $gruppe->gericht,
                ])
                ->values());
    }

    private function wallGerichtArbeitsZeilen(\Illuminate\Support\Collection $gruppe, \Illuminate\Support\Collection $postenZeilen): \Illuminate\Support\Collection
    {
        $orderIds = $gruppe->pluck('order_id')->filter()->map(fn ($id) => (int) $id)->unique()->values();
        $hatUnterzeilen = $gruppe->contains(fn ($z) => (int) ($z->tiefe ?? 0) > 0);
        $minTiefe = (int) $gruppe->min(fn ($z) => (int) ($z->tiefe ?? 0));
        $arbeitsZeilen = $gruppe
            ->reject(fn ($z) => (bool) ($z->ist_verkaufsrezept ?? false)
                || ($hatUnterzeilen
                    && ! (bool) ($z->is_basisrezept ?? false)
                    && (int) ($z->tiefe ?? 0) === $minTiefe))
            ->values();
        $bekannteLineIds = $arbeitsZeilen->pluck('id')->map(fn ($id) => (int) $id)->all();
        $referenzQuellen = $gruppe->values();

        for ($i = 0; $i < 6; $i++) {
            $refRecipeIds = $arbeitsZeilen
                ->concat($referenzQuellen)
                ->flatMap(fn ($z) => collect((array) ($z->zutaten ?? []))
                    ->filter(fn ($zu) => ($zu['typ'] ?? null) === 'sub' && ! empty($zu['ref_recipe_id']))
                    ->pluck('ref_recipe_id'))
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values();

            if ($refRecipeIds->isEmpty()) {
                break;
            }

            $referenzen = $postenZeilen
                ->filter(fn ($z) => $orderIds->contains((int) ($z->order_id ?? 0))
                    && $refRecipeIds->contains((int) ($z->recipe_id ?? 0))
                    && ! in_array((int) ($z->id ?? 0), $bekannteLineIds, true))
                ->values();

            if ($referenzen->isEmpty()) {
                break;
            }

            $arbeitsZeilen = $arbeitsZeilen->concat($referenzen)->values();
            $bekannteLineIds = $arbeitsZeilen->pluck('id')->map(fn ($id) => (int) $id)->all();
            $referenzQuellen = $referenzen;
        }

        if ($arbeitsZeilen->isEmpty()) {
            $arbeitsZeilen = $gruppe->values();
        }

        return $arbeitsZeilen
            ->unique(fn ($z) => (int) ($z->id ?? 0))
            ->sortBy(fn ($z) => [
                (bool) ($z->ist_verkaufsrezept ?? false) ? 0 : 1,
                (int) ($z->position ?? 0),
                (int) ($z->id ?? 0),
            ])
            ->values();
    }

    /**
     * Anrichte-Anleitung fürs Wand-Gericht (§3.3).
     *
     * Reihenfolge der Quellen: eingefrorene Anrichte-SCHRITTE (seit 2026-09-04, mit Fotos)
     * → `plating_text` als deren Spiegel bzw. für Alt-Aufträge → Zubereitung als letzter
     * Notnagel. Die Schritte liefert {@see self::wallGerichtAnrichtenSchritte}; hier bleibt
     * der Text für die kompakte Kachel.
     */
    private function wallGerichtAnrichten(\Illuminate\Support\Collection $zeilen): ?string
    {
        $vk = $zeilen->first(fn ($z) => (bool) ($z->ist_verkaufsrezept ?? false));

        $schritte = (array) ($vk->anrichte_schritte ?? []);
        if ($schritte !== []) {
            return collect($schritte)
                ->map(fn ($s) => trim(($s['nr'] ?? '') . '. ' . ($s['text'] ?? '')))
                ->filter()->implode("\n");
        }

        $text = trim((string) ($vk->plating_text ?? ''));
        if ($text !== '') {
            return $text;
        }

        $fallback = trim((string) ($vk->zubereitung ?? ''));

        return $fallback !== '' ? $fallback : null;
    }

    /**
     * Anrichte-Schritte samt Fotos fürs Wand-Gericht — das ist der Grund, warum das
     * Anrichten überhaupt eine Schrittfolge bekam: der Pass soll den Aufbau SEHEN.
     *
     * @return list<array<string, mixed>>
     */
    private function wallGerichtAnrichtenSchritte(\Illuminate\Support\Collection $zeilen): array
    {
        $vk = $zeilen->first(fn ($z) => (bool) ($z->ist_verkaufsrezept ?? false));

        return $this->normalisierteAnleitungsSchritte((array) ($vk->anrichte_schritte ?? []));
    }

    /**
     * Regenerations-Programm je Komponente fürs Wand-Gericht (§3.2).
     *
     * Bis 2026-09-04 kam die Regeneration allein aus {@see self::wallGerichtDarreichung} —
     * also aus den Skalaren der Standard-Darreichung, die kein Schreibpfad füllt. Am Pass
     * stand sie deshalb leer, obwohl sie im Editor gepflegt ist.
     *
     * @return list<array<string, mixed>>
     */
    private function wallGerichtRegeneration(\Illuminate\Support\Collection $zeilen): array
    {
        return $zeilen
            ->map(fn ($z) => (array) ($z->regenerationen ?? []))
            ->first(fn (array $r) => $r !== []) ?? [];
    }

    /**
     * Was fuer diese Gruppe gepackt werden muss — ueber ALLE Zeilen, nicht nur die erste.
     *
     * BEFUND: `wallGerichtDarreichung()` nimmt bewusst die erste Zeile mit Inhalt — fuer Geschirr
     * und Servierform ist das richtig, die gehoeren dem Gericht. Der BEHAELTER-Bedarf verteilt
     * sich aber ueber mehrere Zeilen: Abfuellen haengt an jeder Produktionszeile, Regenerieren
     * und Ausgabe an der Gericht-Zeile. Wer hier `first()` nimmt, zeigt am Pass genau einen
     * Behaelter und verschweigt den Rest.
     *
     * Gezaehlt wird nur die BASIS-Variante — die Alternativen sind ein Angebot an die Kueche,
     * keine zweite Wahrheit. `bereits_gezaehlt` faellt raus: eine durchgaengige Komponente reist
     * im eigenen Abfuellbehaelter mit und ist an dessen Zeile schon gezaehlt.
     *
     * @return array<int, array{label:string, wert:string}>
     */
    private function wallGerichtBehaelter(\Illuminate\Support\Collection $zeilen): array
    {
        $summe = [];
        $ohne = 0;

        foreach ($zeilen as $z) {
            $block = ((array) ($z->darreichung ?? []))['behaelter_bedarf'] ?? null;
            if (! is_array($block)) {
                continue;
            }
            $kandidaten = array_filter(
                [$block['abfuellen'] ?? null, ...($block['je_komponente'] ?? [])],
                fn ($e) => is_array($e) && ! ($e['bereits_gezaehlt'] ?? false)
            );
            foreach ($kandidaten as $e) {
                if (! ($e['berechenbar'] ?? false)) {
                    $ohne++;

                    continue;
                }
                $basis = $e['varianten'][0] ?? null;
                if ($basis === null || ($basis['behaelter'] ?? null) === null) {
                    continue;
                }
                $summe[$basis['behaelter']] = ($summe[$basis['behaelter']] ?? 0) + (int) $basis['anzahl'];
            }
        }

        if ($summe === [] && $ohne === 0) {
            return [];
        }

        $raus = [];
        foreach ($summe as $name => $anzahl) {
            $raus[] = ['label' => 'Behälter', 'wert' => "{$anzahl}× {$name}"];
        }
        if ($ohne > 0) {
            $raus[] = ['label' => 'Behälter', 'wert' => "{$ohne} Position(en) nicht bemessbar"];
        }

        return $raus;
    }

    /**
     * Der Behaelterbedarf DIESER einen Zeile — fuer die Anleitung am Wandmonitor.
     *
     * Die Gericht-Kachel summiert ueber die ganze Gruppe (`wallGerichtBehaelter`); wer vor dem
     * Posten steht, braucht aber die Zeile, die er gerade abarbeitet: worin fuellt er DIESE
     * Charge ab, worin wird sie regeneriert. Deshalb Zeile statt Gruppe — und deshalb mit der
     * ALTERNATIVE: gewaehlt wird in der Kueche, nicht vom Rechner (§3.4e).
     *
     * @return array<int, array{zweck:string, wert:?string, zusatz:?string, hinweis:?string}>
     */
    private function wallZeileBehaelter(object $zeile): array
    {
        $block = ((array) ($zeile->darreichung ?? []))['behaelter_bedarf'] ?? null;
        if (! is_array($block)) {
            return [];
        }

        $zusammen = $block['zusammen'] ?? [];
        $raus = [];
        $regeneriert = false;

        // Durchgaengig = derselbe Behaelter beim Abfuellen und Regenerieren. Dann ist es EIN
        // Stapel, der aus dem Kuehlhaus direkt in den Ofen geht — zweimal zeigen waere falsch.
        if (($zusammen['durchgaengig'] ?? false) && ($zusammen['behaelter'] ?? null) !== null) {
            return [[
                'zweck' => 'Abfüllen & Regenerieren',
                'wert' => $zusammen['anzahl'].'× '.$zusammen['behaelter'],
                'zusatz' => null,
                'hinweis' => 'durchgängig, kein Umfüllen',
            ]];
        }

        if (is_array($block['abfuellen'] ?? null)) {
            $raus[] = $this->behaelterZeile('Abfüllen', $block['abfuellen']);
        }

        foreach ($block['je_komponente'] ?? [] as $k) {
            if (! is_array($k) || ($k['bereits_gezaehlt'] ?? false)) {
                continue;   // reist im eigenen Abfuellbehaelter mit
            }
            $zweck = ucfirst((string) ($k['zweck'] ?? '')).(($k['label'] ?? null) !== null ? ' · '.$k['label'] : '');
            $raus[] = $this->behaelterZeile(trim($zweck), $k);
            $regeneriert = $regeneriert || ($k['zweck'] ?? null) === 'regenerieren';
        }

        // Verschiedene Behaelter fuer Abfuellen und Regenerieren heissen: jemand fuellt am
        // Einsatztag um. Das ist ein Arbeitsschritt, der heute nirgends steht — und der am
        // Posten auffallen muss, sonst sucht die Fruehschicht die Suppe im falschen Geschirr.
        if ($regeneriert && ($zusammen['hinweis'] ?? '') !== '') {
            $raus[] = ['zweck' => 'Umfüllen', 'wert' => $zusammen['hinweis'], 'zusatz' => null, 'hinweis' => null];
        }

        return array_values(array_filter($raus));
    }

    /** @return array{zweck:string, wert:?string, zusatz:?string, hinweis:?string} */
    private function behaelterZeile(string $zweck, array $ergebnis): array
    {
        if (! ($ergebnis['berechenbar'] ?? false)) {
            // Der Grund gehoert an die Wand, nicht in ein Log: nur so faellt am Posten auf,
            // dass eine Angabe fehlt, statt dass der Behaelter stillschweigend verschwindet.
            return ['zweck' => $zweck, 'wert' => null, 'zusatz' => null,
                'hinweis' => $ergebnis['grund'] ?? 'nicht bemessbar'];
        }

        $basis = $ergebnis['varianten'][0] ?? null;
        if ($basis === null) {
            return ['zweck' => $zweck, 'wert' => null, 'zusatz' => null, 'hinweis' => 'nicht bemessbar'];
        }

        $alt = $ergebnis['varianten'][1] ?? null;

        return [
            'zweck' => $zweck,
            'wert' => isset($basis['stueck_je_behaelter'])
                ? $basis['anzahl'].'× '.$basis['behaelter'].' (à '.$basis['stueck_je_behaelter'].' Stk.)'
                : $basis['anzahl'].'× '.$basis['behaelter'],
            'zusatz' => $alt !== null ? 'oder '.$alt['anzahl'].'× '.$alt['behaelter'] : null,
            'hinweis' => ($basis['konfidenz'] ?? 'hoch') !== 'hoch' ? 'geschätzt' : null,
        ];
    }

    private function wallGerichtDarreichung(\Illuminate\Support\Collection $zeilen): array
    {
        $darreichung = $zeilen
            ->map(fn ($z) => (array) ($z->darreichung ?? []))
            ->first(fn (array $d) => $d !== []);

        if (! is_array($darreichung)) {
            return [];
        }

        // Spec 51: Regeneration kommt hier NICHT mehr her. Sie steht in `regen_snapshot`
        // (je Komponente) und wird von wallGerichtRegeneration() gerendert — zwei Quellen
        // nebeneinander waren genau die Drift, die dieser Spec abstellt.
        $labels = [
            'geschirr' => 'Geschirr',
            'vehikel' => 'Servierform',
            'behaelter_warm' => 'Behälter warm',
            'behaelter_kalt' => 'Behälter kalt',
        ];

        return collect($labels)
            ->map(function (string $label, string $key) use ($darreichung) {
                $wert = $darreichung[$key] ?? null;
                if ($wert === null || $wert === '') {
                    return null;
                }

                return ['label' => $label, 'wert' => (string) $wert];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function wallGerichtRezeptUebersicht(\Illuminate\Support\Collection $zeilen): array
    {
        return $zeilen
            ->map(function ($z) {
                return [
                    'line_id' => (int) $z->id,
                    'typ' => (bool) ($z->is_basisrezept ?? false) ? 'Basisrezept' : 'Produkt/Rezept',
                    'name' => (string) ($z->rezept_label ?: $z->name),
                    'erledigt' => $z->line_status === 'done',
                    'menge' => $z->gesamt_kg !== null
                        ? rtrim(rtrim(number_format((float) $z->gesamt_kg, 3, ',', '.'), '0'), ',') . ' kg'
                        : null,
                    'zeit' => $z->arbeitszeit_min !== null ? $z->arbeitszeit_min . ' min' : 'ohne Zeit',
                ];
            })
            ->values()
            ->all();
    }

    private function wallGerichtAufloesen(\Illuminate\Support\Collection $postenGruppen): ?object
    {
        if ($this->wallGerichtKey === null) {
            return null;
        }

        return $postenGruppen
            ->flatten(1)
            ->first(fn ($gruppe) => (string) ($gruppe->key ?? '') === (string) $this->wallGerichtKey);
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
                $gerichtKey = $first->gericht_label !== null
                    ? implode('|', [
                        (int) ($first->order_id ?? 0),
                        (string) $first->gericht_label,
                        $first->liefertag !== null ? Carbon::parse($first->liefertag)->toDateString() : '',
                    ])
                    : null;

                return (object) [
                    'name' => $first->name,
                    'ist_basisrezept' => (bool) ($first->is_basisrezept ?? false),
                    'ist_gericht' => $gerichtKey !== null,
                    'gericht_key' => $gerichtKey,
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
        $zutaten = $treffer->zutaten ?? ($line?->zutaten ?? []);

        return [
            'line_id' => (int) $treffer->id,
            'name' => $treffer->name,
            'auftrag' => $treffer->auftrag,
            'line_status' => $treffer->line_status,
            'gesamt_kg' => $treffer->gesamt_kg,
            'arbeitszeit_min' => $treffer->arbeitszeit_min !== null ? (int) $treffer->arbeitszeit_min : null,
            'standzeit_min' => ($line?->standzeit_min ?? null) !== null ? (int) $line->standzeit_min : null,
            'durchlaufzeit_min' => ($treffer->arbeitszeit_min === null && ($line?->standzeit_min ?? null) === null)
                ? null
                : (int) ((int) ($treffer->arbeitszeit_min ?? 0) + (int) ($line?->standzeit_min ?? 0)),
            'started_at' => $treffer->started_at ? Carbon::parse($treffer->started_at)->toIso8601String() : null,
            'equipment' => collect($treffer->equipment ?? [])->map(fn ($e) => [
                'name' => $e->name,
                'gruppe' => $e->group_name,
                'notiz' => $e->note,
            ])->values()->all(),
            'schritte' => $this->normalisierteAnleitungsSchritte($treffer->schritte ?? ($line?->steps_snapshot ?? [])),
            'zubereitung' => $treffer->zubereitung ?? $line?->zubereitung,
            'zutaten' => $zutaten,
            'behaelter' => $this->wallZeileBehaelter($treffer),
            'sub_rezepte' => $this->anleitungSubRezepte($treffer, (array) $zutaten, $zeilen),
            'sicherheit' => $treffer->sicherheit ?? ['allergene' => [], 'diaet' => [], 'warnungen' => [], 'konfidenz' => 'unknown'],
            'arbeitsschritte' => $this->arbeitsschritte($treffer->schritte ?? ($line?->steps_snapshot ?? []), $treffer->zubereitung ?? $line?->zubereitung),
            'step_erledigt' => collect($this->anleitungStepStatus[(int) $treffer->id] ?? [])->map(fn ($i) => (int) $i)->unique()->values()->all(),
        ];
    }

    /** @var array<string,ContextFile> Memo path→ContextFile (N+1-Vermeidung im Wandmonitor-Poll). */
    private array $ctxFileByPath = [];

    /** @var array<string,ContextFile> Memo token→ContextFile. */
    private array $ctxFileByToken = [];

    /** Alle ContextFiles der Anleitungs-Medien EINMAL vorladen statt je Medium (N+1). */
    private function preloadContextFiles(array $schritte): void
    {
        $this->ctxFileByPath = [];
        $this->ctxFileByToken = [];
        $paths = [];
        $tokens = [];
        foreach ($schritte as $schritt) {
            foreach (['fotos', 'photos', 'medien', 'media'] as $feld) {
                if (! is_array($schritt[$feld] ?? null)) {
                    continue;
                }
                foreach ($schritt[$feld] as $medium) {
                    $src = trim((string) ($medium['src'] ?? $medium['url'] ?? ''));
                    if ($src === '' || str_starts_with($src, 'data:')) {
                        continue;
                    }
                    $path = parse_url($src, PHP_URL_PATH);
                    $basename = is_string($path) ? basename($path) : '';
                    if ($basename === '' || $basename === '/') {
                        continue;
                    }
                    $paths[] = $basename;
                    $tokens[] = pathinfo($basename, PATHINFO_FILENAME);
                }
            }
        }
        if ($paths === [] && $tokens === []) {
            return;
        }
        $files = ContextFile::query()
            ->where(function ($q) use ($paths, $tokens) {
                $q->whereIn('path', array_values(array_unique($paths)))
                    ->orWhereIn('token', array_values(array_unique($tokens)));
            })
            ->orderBy('id')
            ->get();
        foreach ($files as $file) {
            if (! isset($this->ctxFileByPath[$file->path])) {
                $this->ctxFileByPath[$file->path] = $file;
            }
            if ($file->token !== null && ! isset($this->ctxFileByToken[$file->token])) {
                $this->ctxFileByToken[$file->token] = $file;
            }
        }
    }

    private function normalisierteAnleitungsSchritte(array $schritte): array
    {
        $this->preloadContextFiles($schritte);

        return collect($schritte)
            ->map(function (array $schritt) {
                foreach (['fotos', 'photos', 'medien', 'media'] as $feld) {
                    if (! is_array($schritt[$feld] ?? null)) {
                        continue;
                    }

                    $schritt[$feld] = collect($schritt[$feld])
                        ->map(function (array $medium) {
                            $src = $medium['src'] ?? $medium['url'] ?? null;
                            $normalisiert = $this->normalisierteMedienUrl($src);

                            if ($normalisiert !== null) {
                                $medium['src'] = $normalisiert;
                                $medium['url'] = $normalisiert;
                            }

                            return $medium;
                        })
                        ->values()
                        ->all();
                }

                return $schritt;
            })
            ->values()
            ->all();
    }

    private function normalisierteMedienUrl(?string $src): ?string
    {
        $src = trim((string) $src);
        if ($src === '' || str_starts_with($src, 'data:')) {
            return $src !== '' ? $src : null;
        }

        $path = parse_url($src, PHP_URL_PATH);

        // Snapshot-Fotos frieren eine KURZLEBIGE signierte ContextFile-URL ein
        // (core: 60-Min-TTL). Nach Ablauf → 403/404 → Broken Image im Wandmodus.
        // Deshalb den Datei-Token aus dem Pfad zurück auf die ContextFile auflösen
        // und deren FRISCH signierte URL ausgeben — überlebt beliebig alte Snapshots
        // und ist Disk-agnostisch (local wie hetzner/S3).
        $basename = is_string($path) ? basename($path) : '';
        if ($basename !== '' && $basename !== '/') {
            $file = $this->ctxFileByPath[$basename]
                ?? ($this->ctxFileByToken[pathinfo($basename, PATHINFO_FILENAME)] ?? null);
            if ($file !== null) {
                return $file->url;
            }
        }

        // Fallback ohne ContextFile-Treffer: Legacy-public-Disk-Rewrites beibehalten.
        if (str_starts_with($src, 'storage/')) {
            return Storage::disk('public')->url(substr($src, strlen('storage/')));
        }

        $host = parse_url($src, PHP_URL_HOST);
        if (is_string($path) && str_starts_with($path, '/storage/')
            && in_array($host, ['localhost', '127.0.0.1'], true)) {
            return Storage::disk('public')->url(substr($path, strlen('/storage/')));
        }

        return $src;
    }

    private function anleitungSubRezepte(object $zeile, array $zutaten, $zeilen): array
    {
        return collect($zutaten)
            ->filter(fn ($z) => ($z['typ'] ?? null) === 'sub' && ! empty($z['ref_recipe_id']))
            ->map(function ($z) use ($zeile, $zeilen) {
                $refId = (int) $z['ref_recipe_id'];
                $line = $zeilen->first(fn ($kandidat) => (int) ($kandidat->order_id ?? 0) === (int) ($zeile->order_id ?? 0)
                    && (int) ($kandidat->recipe_id ?? 0) === $refId);

                return [
                    'line_id' => $line !== null ? (int) $line->id : null,
                    'name' => (string) ($line?->rezept_label ?: ($line?->name ?? ($z['name'] ?? 'Basisrezept'))),
                    'typ' => $line !== null && ! (bool) ($line->is_basisrezept ?? false) ? 'Produkt/Rezept' : 'Basisrezept',
                    'erledigt' => $line !== null && $line->line_status === 'done',
                    'menge' => $line?->gesamt_kg !== null
                        ? rtrim(rtrim(number_format((float) $line->gesamt_kg, 3, ',', '.'), '0'), ',') . ' kg'
                        : (isset($z['menge']) ? rtrim(rtrim(number_format((float) $z['menge'], 3, ',', '.'), '0'), ',') . ' ' . ($z['einheit'] ?? '') : null),
                    'zeit' => $line?->arbeitszeit_min !== null ? $line->arbeitszeit_min . ' min' : null,
                ];
            })
            ->values()
            ->all();
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
