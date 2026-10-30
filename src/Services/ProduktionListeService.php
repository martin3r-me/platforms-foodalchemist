<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Collection;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionOrder;

/**
 * Spec 91 PR 4 · Listen-Kennzahlen der Produktionsaufträge, gemeinsam für den Produktions-Browser und
 * foodalchemist.production_orders.GET. ProductionOrderService liegt knapp unter der Größen-Grenze (Spec 85), daher eigene Klasse.
 */
class ProduktionListeService
{
    public function __construct(private ProductionOrderService $auftraege) {}

    /** Kein N+1: Zeilen der GELISTETEN Aufträge in einer Query. */
    public function zeilenLaden(Collection $auftraege): void
    {
        $auftraege->load([
            'lines:id,production_order_id,ansaetze,manual_ansaetze,is_manual_ansaetze,is_struck,portionen,arbeitszeit_min,station_id',
            'lines.station:id,name',
        ]);
    }

    /** @return array<int, string> Auftrag-Id => entwurf | geaendert | freigegeben (Einkaufs-Indikator) */
    public function indikatoren(Collection $auftraege): array
    {
        return $auftraege->mapWithKeys(fn ($order) => [
            (int) $order->id => $order->procurement_released_at === null
                ? 'entwurf'
                : ($this->auftraege->materialbedarfVeraltet($order) ? 'geaendert' : 'freigegeben'),
        ])->all();
    }

    /** Spalten des Browsers für einen Auftrag mit geladenen Zeilen; gestrichene Zeilen zählen nicht. */
    public function kennzahlen(FoodAlchemistProductionOrder $a): array
    {
        $aktiv = $a->lines->reject(fn ($l) => (bool) $l->is_struck);

        return [
            'ziele' => collect($a->targets ?? [])->pluck('label')->filter()->values()->all(),
            'ansaetze' => round((float) $aktiv->sum('ansaetze_effektiv'), 2),
            'portionen' => (int) $aktiv->sum('portionen'),
            'arbeitszeit_min' => (int) $aktiv->sum('arbeitszeit_min'),
            'posten' => $aktiv->pluck('station.name')->filter()->unique()->values()->all(),
            'ohne_posten' => $aktiv->whereNull('station_id')->count(),
        ];
    }
}
