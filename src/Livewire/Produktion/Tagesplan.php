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

    public ?string $fehler = null;

    /** Stufe 3 P3.4 — der zuletzt gerechnete Planungs-Vorschlag (Review vor Übernahme), oder null. */
    public ?array $vorschlag = null;

    public function mount(): void
    {
        if ($this->von === '') {
            $this->von = now()->toDateString();
        }
    }

    public function verschiebe(int $tage): void
    {
        $this->von = Carbon::parse($this->von)->addDays($tage)->toDateString();
    }

    public function heute(): void
    {
        $this->von = now()->toDateString();
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
        $von = Carbon::parse($this->von)->toDateString();
        $bis = Carbon::parse($this->von)->addDays(max(1, min(60, $this->tage)) - 1)->toDateString();
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

    public function render(ProductionCapacityService $kap)
    {
        $team = Auth::user()?->currentTeamRelation;
        $von = Carbon::parse($this->von)->toDateString();
        $bis = Carbon::parse($this->von)->addDays(max(1, min(60, $this->tage)) - 1)->toDateString();

        $auslastung = $team !== null ? $kap->auslastung($team, $von, $bis) : [];
        $zeilen = $team !== null ? $kap->tagesplanZeilen($team, $von, $bis) : collect();

        if ($this->postenFilter !== null) {
            $zeilen = $zeilen->where('station_id', $this->postenFilter);
            $auslastung = collect($auslastung)
                ->map(fn ($b) => array_values(array_filter($b, fn ($x) => $x['station_id'] === $this->postenFilter)))
                ->filter(fn ($b) => $b !== [])->all();
        }

        return view('foodalchemist::livewire.produktion.tagesplan', [
            'von' => $von,
            'bis' => $bis,
            'auslastung' => $auslastung,
            'zeilenNachTag' => $zeilen->groupBy(fn ($z) => Carbon::parse($z->plan_date)->toDateString()),
            'postenListe' => $team !== null
                ? FoodAlchemistProductionStation::visibleToTeam($team)->where('is_inactive', false)
                    ->orderBy('sort_order')->orderBy('name')->get(['id', 'name'])
                : collect(),
        ])->layout('platform::layouts.app');
    }
}
