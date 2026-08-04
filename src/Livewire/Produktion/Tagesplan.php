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

    /** Ausgabe-Modus: '' = normal (3 Panels), 'wall' = Küchen-Wandmodus (chrome-arm, groß). */
    #[Url(as: 'display')]
    public string $display = '';

    #[Url(as: 'ansicht')]
    public string $ansicht = 'posten';

    public ?int $anleitungZeileId = null;
    public ?int $grundZeileId = null;
    public string $grundModus = 'skip';
    public string $grundCode = '';
    public string $grundNotiz = '';
    public ?int $startOrderId = null;
    public string $startOrderVersion = '';
    public string $startOverrideReason = '';
    public array $startWarnings = [];
    public ?int $finishOrderId = null;
    public string $finishOrderVersion = '';
    public string $finishNote = '';
    public array $finishSummary = [];

    public ?string $fehler = null;

    /** Stufe 3 P3.4 — der zuletzt gerechnete Planungs-Vorschlag (Review vor Übernahme), oder null. */
    public ?array $vorschlag = null;

    public function mount(?string $display = null, ?int $tage = null, ?string $ansicht = null): void
    {
        abort_unless(config('foodalchemist.features.production_cockpit', true), 404);
        if (request()->routeIs('foodalchemist.produktion.wandmonitor')) {
            $this->display = $display ?: 'wall';
            $this->tage = $tage ?: 1;
            $this->ansicht = in_array($ansicht ?: $this->ansicht, ['posten', 'gericht'], true) ? ($ansicht ?: $this->ansicht) : 'posten';
        }
        if ($this->von === '') {
            $this->von = now()->toDateString();
        }
        if ($this->display === 'wall' && ! request()->has('tage')) {
            $this->tage = 1;
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
        [$von, $bis] = $this->zeitraum();
        $this->von = $von;
        $this->bis = $bis;
    }

    public function updatedBis(): void
    {
        [$von, $bis] = $this->zeitraum();
        $this->von = $von;
        $this->bis = $bis;
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

    public function produktionStarten(int $orderId, ProductionOrderService $svc, ProductionReadinessService $readiness): void
    {
        $this->fehler = null;
        try {
            $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
            [$von, $bis] = $this->zeitraum();
            $split = $readiness->split($team, $von, $bis);
            if ($split['blockers'] !== []) {
                $this->fehler = 'Produktionsstart gesperrt: bitte Blocker zuerst lösen.';
                return;
            }
            $orderVersion = \Platform\FoodAlchemist\Models\FoodAlchemistProductionOrder::query()
                ->where('team_id', $team->id)->whereKey($orderId)->value('updated_at');
            if ($split['warnings'] !== []) {
                $this->startOrderId = $orderId;
                $this->startOrderVersion = Carbon::parse($orderVersion)->toIso8601String();
                $this->startWarnings = $split['warnings'];
                $this->startOverrideReason = '';
                $this->dispatch('modal.open', name: 'tagesplan-start');
                return;
            }
            $svc->setStatus($team, $orderId, ProductionOrderStatus::InProgress, [
                'expected_updated_at' => Carbon::parse($orderVersion)->toIso8601String(),
            ]);
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function produktionStartenBestaetigen(ProductionOrderService $svc): void
    {
        if ($this->startOrderId === null || trim($this->startOverrideReason) === '') {
            $this->fehler = 'Bitte einen Override-Grund eintragen.';
            return;
        }
        $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
        $svc->setStatus($team, $this->startOrderId, ProductionOrderStatus::InProgress, [
            'expected_updated_at' => $this->startOrderVersion,
            'readiness_warnings' => $this->startWarnings,
            'override_reason' => $this->startOverrideReason,
        ]);
        $this->dispatch('modal.close', name: 'tagesplan-start');
        $this->startOrderId = null;
        $this->startWarnings = [];
    }

    public function fertigDialog(int $orderId): void
    {
        $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
        $order = \Platform\FoodAlchemist\Models\FoodAlchemistProductionOrder::query()
            ->where('team_id', $team->id)->whereKey($orderId)->firstOrFail();
        $lines = $order->lines()->where('is_struck', false)->get();
        $offen = $lines->whereNotIn('line_status', [ProductionLineStatus::Done, ProductionLineStatus::Skipped])->count();
        $blockiert = $lines->whereNotNull('blocked_reason')->count();
        $this->finishOrderId = $orderId;
        $this->finishOrderVersion = $order->updated_at?->toIso8601String() ?? '';
        $this->finishSummary = ['offen' => $offen, 'blockiert' => $blockiert];
        $this->finishNote = '';
        $this->dispatch('modal.open', name: 'tagesplan-fertig');
    }

    public function fertigSpeichern(ProductionOrderService $svc): void
    {
        if ($this->finishOrderId === null) {
            return;
        }
        $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
        $svc->setStatus($team, $this->finishOrderId, ProductionOrderStatus::Done, [
            'expected_updated_at' => $this->finishOrderVersion,
            'finish_note' => $this->finishNote,
        ]);
        $this->dispatch('modal.close', name: 'tagesplan-fertig');
        $this->finishOrderId = null;
        $this->finishSummary = [];
    }

    public function zeileStarten(int $lineId, ProductionOrderService $svc): void
    {
        $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
        $svc->setLineStatus($team, $lineId, ProductionLineStatus::InProgress);
    }

    public function grundDialog(int $lineId, string $modus): void
    {
        abort_unless(in_array($modus, ['skip', 'block'], true), 422);
        $this->grundZeileId = $lineId;
        $this->grundModus = $modus;
        $this->grundCode = '';
        $this->grundNotiz = '';
        $this->dispatch('modal.open', name: 'tagesplan-grund');
    }

    public function grundSpeichern(ProductionOrderService $svc): void
    {
        if ($this->grundZeileId === null || trim($this->grundCode) === '') {
            $this->fehler = 'Bitte einen Grund wählen.';
            return;
        }
        $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
        if ($this->grundModus === 'skip') {
            $svc->setLineStatus($team, $this->grundZeileId, ProductionLineStatus::Skipped, $this->grundCode);
        } else {
            $svc->setLineBlocked($team, $this->grundZeileId, $this->grundCode, $this->grundNotiz);
        }
        $this->dispatch('modal.close', name: 'tagesplan-grund');
        $this->grundZeileId = null;
    }

    public function entblocken(int $lineId, ProductionOrderService $svc): void
    {
        $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
        $svc->unblockLine($team, $lineId, 'im Cockpit gelöst');
    }

    public function oeffneAnleitung(int $lineId): void
    {
        $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
        $line = FoodAlchemistProductionOrderLine::query()->whereKey($lineId)
            ->whereHas('productionOrder', fn ($q) => $q->where('team_id', $team->id))->firstOrFail();
        $this->anleitungZeileId = (int) $line->id;
        $this->dispatch('modal.open', name: 'tagesplan-anleitung');
    }

    public function render(ProductionCapacityService $kap, ProductionReadinessService $readiness)
    {
        $team = Auth::user()?->currentTeamRelation;
        [$von, $bis] = $this->zeitraum();

        $auslastung = $team !== null ? $kap->auslastung($team, $von, $bis) : [];
        $zeilen = $team !== null ? $kap->tagesplanZeilen($team, $von, $bis, true) : collect();

        if ($this->postenFilter !== null) {
            $zeilen = $zeilen->where('station_id', $this->postenFilter);
            $auslastung = collect($auslastung)
                ->map(fn ($b) => array_values(array_filter($b, fn ($x) => $x['station_id'] === $this->postenFilter)))
                ->filter(fn ($b) => $b !== [])->all();
        }

        $readinessSplit = $team !== null ? $readiness->split($team, $von, $bis) : ['blockers' => [], 'warnings' => []];
        $minuten = [
            'offen' => $zeilen->where('line_status', 'open')->sum('arbeitszeit_min'),
            'laeuft' => $zeilen->where('line_status', 'in_progress')->sum('arbeitszeit_min'),
            'erledigt' => $zeilen->where('line_status', 'done')->sum('arbeitszeit_min'),
        ];

        return view('foodalchemist::livewire.produktion.tagesplan', [
            'von' => $von,
            'bis' => $bis,
            'auslastung' => $auslastung,
            'zeilenNachTag' => $zeilen->groupBy(fn ($z) => Carbon::parse($z->plan_date)->toDateString()),
            'anleitungZeile' => $this->anleitungZeileId !== null
                ? $zeilen->firstWhere('id', $this->anleitungZeileId) : null,
            'klaerfaelle' => array_merge($readinessSplit['blockers'], $readinessSplit['warnings']),
            'readinessSplit' => $readinessSplit,
            'letzteAenderungen' => $team !== null ? $kap->letzteAenderungen($team, $von, $bis) : collect(),
            'alsNaechstes' => $kap->alsNaechstes($zeilen),
            'kennzahlen' => [
                'offen' => $zeilen->where('line_status', 'open')->count(),
                'laeuft' => $zeilen->where('line_status', 'in_progress')->count(),
                'erledigt' => $zeilen->where('line_status', 'done')->count(),
                'uebersprungen' => $zeilen->where('line_status', 'skipped')->count(),
                'blockiert' => $zeilen->filter(fn ($z) => ! empty($z->blocked_reason))->count(),
                'minuten_offen' => (int) $minuten['offen'],
                'minuten_laeuft' => (int) $minuten['laeuft'],
                'minuten_erledigt' => (int) $minuten['erledigt'],
                'manntage' => round($zeilen->sum('arbeitszeit_min') / 480, 1),
                'ueberlast_tage' => collect($auslastung)->flatten(1)->where('stufe', 'ueberlast')->count(),
            ],
            'postenListe' => $team !== null
                ? FoodAlchemistProductionStation::visibleToTeam($team)->where('is_inactive', false)
                    ->orderBy('sort_order')->orderBy('name')->get(['id', 'name'])
                : collect(),
        ])->layout('platform::layouts.app');
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
