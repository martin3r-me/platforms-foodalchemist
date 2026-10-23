<?php

namespace Platform\FoodAlchemist\Livewire\Controlling\Panels;

use Livewire\Component;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Services\PurchaseAnomalyService;

/**
 * Spec 32 — Preis-Ausreißer im Einkaufsjournal, endlich sichtbar.
 *
 * {@see PurchaseAnomalyService} rechnet seit Einkauf E2 eine robuste Theil-Sen-Trendlinie je
 * (Lieferant + Artikel) und meldet Punkte, die um Faktor N davon abweichen — war aber nur über
 * MCP abrufbar. In der Oberfläche fehlte damit ausgerechnet der Befund, der die anderen Zahlen
 * verzerrt: eine Fehlbuchung mit 1,00 €/kg statt 12,60 €/kg zieht Ist-Wareneinsatz und
 * Einsparpotenzial mit sich.
 *
 * **Bewusst ohne Korrektur-Knopf** (Knäcke-Brot-vs-Rucola-Lehre aus dem Service-Kopf): ein
 * Muster mit vielen Treffern kann ein echter stabiler Preis sein, den der Trend-Fit falsch
 * einschätzt. Geflaggt wird zur fachlichen Prüfung — korrigiert wird an der Quelle.
 */
class Ausreisser extends Component
{
    use \Platform\FoodAlchemist\Livewire\Controlling\Concerns\ControllingTeam;

    /** Abweichungsfaktor gegen die Trendlinie; unter 2 wird jede normale Schwankung ein „Fund". */
    public float $faktor = 3.0;

    public function render(PurchaseAnomalyService $anomalien)
    {
        $team = $this->team();
        // Spec 85 PR-E: Team-Brille. Jeder Standort wird mit SEINEM Preisverlauf geprüft (nie eine gemeinsame Serie), die Treffer tragen den Standort.
        $treffer = [];
        $jeStandort = [];
        if ($team !== null) {
            $standorte = app(\Platform\FoodAlchemist\Services\StandortService::class);
            $teams = \Platform\Core\Models\Team::whereIn('id', $this->leseTeamIds($team))->get();
            foreach ($teams as $t) {
                $r = $standorte->alsEigen($t, fn () => $anomalien->detect($t, max(1.5, (float) $this->faktor)));
                foreach ($r as $x) {
                    $treffer[] = $x + ['team_id' => (int) $t->id, 'standort' => (string) $t->name];
                }
                $jeStandort[] = ['team_id' => (int) $t->id, 'standort' => (string) $t->name, 'anzahl' => count($r)];
            }
            usort($treffer, fn ($a, $b) => $b['factor'] <=> $a['factor']);
        }

        return view('foodalchemist::livewire.controlling.panels.ausreisser', [
            'treffer' => $treffer,
            'jeStandort' => count($jeStandort) > 1 ? $jeStandort : [],
            'lieferanten' => $team !== null
                ? FoodAlchemistSupplier::visibleToTeam($team)->pluck('name', 'id')
                : collect(),
        ]);
    }
}
