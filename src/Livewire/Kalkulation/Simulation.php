<?php

namespace Platform\FoodAlchemist\Livewire\Kalkulation;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\GpService;
use Platform\FoodAlchemist\Services\SimulationService;

/**
 * R2.2 — Was-wäre-wenn-Simulation (UI-Panel in der Kalkulations-Werkstatt).
 *
 * Hypothetisches Preisszenario (Warengruppe | Einzelartikel | GP, ± X %) →
 * Portfolio-Antwort: Marge-Delta gesamt + Top-20 betroffene Gerichte + Ersatz-
 * vorschläge aus dem Äquivalenz-Katalog. REIN LESEND — spiegelt exakt das
 * MCP-Tool `foodalchemist.simulation.POST` (SimulationService), verändert nichts.
 */
class Simulation extends Component
{
    /** 'warengruppe' | 'artikel' | 'gp' */
    #[Validate('required|in:warengruppe,artikel,gp')]
    public string $scope = 'warengruppe';

    /** WG-Code | supplier_item_id | gp_id — je nach Scope. */
    public string $ref = '';

    /** Anzeige-Label des gewählten Bezugs (für GP/Artikel-Suche). */
    public string $refLabel = '';

    /** relative Preisänderung in % (z. B. 20 oder -10). */
    #[Validate('numeric|gt:-100')]
    public float $deltaPct = 20.0;

    /** GP-Schnellsuche (nur scope=gp). */
    public string $gpQuery = '';

    /** Ergebnis der letzten Simulation (null = noch nichts gerechnet). */
    public ?array $result = null;

    /** Laufzeit der letzten Rechnung in ms (Perf-Transparenz, DoD < 10 s). */
    public ?int $dauerMs = null;

    public function updatedScope(): void
    {
        // Bezug zurücksetzen, wenn die Ebene wechselt (WG-Code ≠ gp_id ≠ item_id).
        $this->reset(['ref', 'refLabel', 'gpQuery', 'result', 'dauerMs']);
    }

    /** GP aus der Schnellsuche übernehmen. */
    public function waehleGp(int $gpId, string $name): void
    {
        $this->ref = (string) $gpId;
        $this->refLabel = $name . ' (#' . $gpId . ')';
        $this->gpQuery = '';
    }

    public function zuruecksetzen(): void
    {
        $this->reset(['ref', 'refLabel', 'gpQuery', 'result', 'dauerMs']);
    }

    public function simuliere(SimulationService $sim): void
    {
        $this->validate();
        $this->result = null;
        $this->dauerMs = null;

        $ref = trim($this->ref);
        if ($ref === '') {
            $this->addError('ref', 'Bitte einen Bezug wählen (Warengruppe, Grundprodukt oder Artikel).');

            return;
        }

        $team = Auth::user()?->currentTeamRelation;
        if ($team === null) {
            $this->addError('ref', 'Kein Team im Kontext.');

            return;
        }

        $start = hrtime(true);
        $res = $sim->simuliere($team, $this->scope, $ref, (float) $this->deltaPct);
        $this->dauerMs = (int) round((hrtime(true) - $start) / 1_000_000);

        // Namen für die Substitutions-GPs sind im Service bereits aufgelöst;
        // hier nur noch die betroffenen-GP-Namen für die Kopfzeile nachziehen.
        $this->result = $res;
    }

    /** GP-Treffer für die Schnellsuche (max 8), Team-gescopt. */
    public function getGpTrefferProperty(): array
    {
        $q = trim($this->gpQuery);
        if ($this->scope !== 'gp' || mb_strlen($q) < 2) {
            return [];
        }
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null) {
            return [];
        }

        return FoodAlchemistGp::visibleToTeam($team)
            ->where('name', 'like', '%' . $q . '%')
            ->orderByRaw('CASE WHEN lead_la_supplier_item_id IS NOT NULL THEN 0 ELSE 1 END')
            ->orderBy('name')
            ->limit(8)
            ->get(['id', 'name', 'lead_la_supplier_item_id'])
            ->map(fn ($g) => [
                'id' => (int) $g->id,
                'name' => (string) $g->name,
                'hat_lead' => $g->lead_la_supplier_item_id !== null,
            ])->all();
    }

    public function render(GpService $gps)
    {
        $team = Auth::user()?->currentTeamRelation;

        return view('foodalchemist::livewire.kalkulation.simulation', [
            'warengruppen' => $team ? $gps->warengruppenOptions($team) : collect(),
            'gpTreffer' => $this->getGpTrefferProperty(),
        ]);
    }
}
