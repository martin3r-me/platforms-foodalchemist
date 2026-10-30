<?php

namespace Platform\FoodAlchemist\Livewire\Projekte;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\FoodAlchemist\Livewire\Concerns\ManagesCanvas;
use Platform\FoodAlchemist\Services\CanvasService;
use Platform\FoodAlchemist\Support\CrmKunden;

/**
 * Spec 82 §9 · Phase 3 „DNA & Handschrift“ der Projekt-Akte: schreibt die BESTEHENDE Kunden-DNA der CRM-Firma (`kunde_dna`,
 * owner crm_company) — dieselbe, die auch Einstellungen → Kunde-DNA und das Foodbook lesen. Felder mit „Bleibt – unverhandelbar“
 * sind verbindlich und fließen ungekürzt in die KI-Kaskade. Eigenes Nested-Livewire, weil ManagesCanvas genau EINEN Canvas je
 * Komponente hält (die Projekt-Akte belegt den schon).
 */
class KundeDnaPhase extends Component
{
    use ManagesCanvas;
    use \Platform\FoodAlchemist\Livewire\Concerns\HandyLesebereich;   // #1003: am Handy nur lesen (Kunden-DNA am Computer pflegen)

    public int $companyId;

    public function mount(int $companyId): void
    {
        // Nur Firmen des CRM im aktuellen (Master-)Team; sonst bleibt das Board leer
        if (CrmKunden::firma(CrmKunden::aktuellesTeam(), $companyId) === null) {
            abort(404);
        }
        $this->companyId = $companyId;
        $this->canvasInit('kunde_dna', 'crm_company', $companyId);
    }

    public function render()
    {
        $canvas = app(CanvasService::class)->find('kunde_dna', 'crm_company', $this->companyId);
        $bleibt = $canvas !== null ? count(app(CanvasService::class)->verbindlichkeit($canvas)) : 0;

        return view('foodalchemist::livewire.projekte.kunde-dna-phase', ['bleibt' => $bleibt]);
    }
}
