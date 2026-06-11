<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Collection;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\LeadLaStrategie;

/**
 * M1-05 / V-27: Strategie-Schicht der Lead-LA-Wahl — wird von LeadLaService (M3-06)
 * als ERSTE Sortier-Stufe konsumiert (vor der GL-03-Kette; Stufen sind Sortier-
 * Kriterien, keine Filter — GL-03 I3).
 *
 * Kandidaten: Objekte/Arrays mit `supplier_item_id`, `supplier_id`,
 * `vergleichspreis` (float|null). NULL-Preise sortieren IMMER ans Ende
 * (GL-03 A-2 NULLS LAST, engine-agnostisch weil PHP-seitig — 07 §7 Regel 5).
 * Determinismus-Tiebreaker: supplier_item_id ASC (GL-03 I1).
 *
 * Stamm-Lieferanten (Strategie stamm_lieferant) kommen als Parameter —
 * Datenquelle ist die M1-06-Matrix (StammLieferantService).
 */
class LeadLaStrategieResolver
{
    public function __construct(private TeamSettingsService $settings)
    {
    }

    /**
     * @param Collection<int, object> $kandidaten
     * @param array<int> $stammSupplierIds Stamm-Lieferanten des Teams (für WG, M1-06)
     */
    public function sortiere(Team $team, Collection $kandidaten, array $stammSupplierIds = []): Collection
    {
        $strategie = $this->settings->leadLaStrategie($team);
        $prioritaeten = $this->settings->leadLaPrioritaeten($team);

        return $kandidaten
            ->sortBy(fn (object $la) => $this->rang($la, $strategie, $stammSupplierIds, $prioritaeten))
            ->values();
    }

    /** @return array Sortier-Tupel: [Strategie-Stufe, Preis-NULL?, Preis, Tiebreaker] */
    private function rang(object $la, LeadLaStrategie $strategie, array $stammSupplierIds, array $prioritaeten): array
    {
        $preis = $la->vergleichspreis ?? null;
        $stufe = match ($strategie) {
            LeadLaStrategie::GuenstigsterPreis => 0,
            LeadLaStrategie::StammLieferant => in_array((int) $la->supplier_id, array_map('intval', $stammSupplierIds), true) ? 0 : 1,
            LeadLaStrategie::PrioritaetsKette => ($pos = array_search((int) $la->supplier_id, array_map('intval', $prioritaeten), true)) !== false
                ? $pos
                : PHP_INT_MAX,
        };

        return [
            $stufe,
            $preis === null ? 1 : 0,          // NULLS LAST (GL-03 A-2)
            $preis ?? PHP_FLOAT_MAX,
            (int) ($la->supplier_item_id ?? 0), // Determinismus (GL-03 I1)
        ];
    }
}
