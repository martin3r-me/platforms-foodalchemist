<?php

namespace Platform\FoodAlchemist\Livewire\Foodbooks;

use Livewire\Component;
use Platform\FoodAlchemist\Livewire\Concerns\ManagesCanvas;

/**
 * Phase 2: Kunde-DNA-Board (Ebene 2 der DNA-Kette Team → KUNDE → Foodbook).
 * Eigenes Nested-Livewire, weil ManagesCanvas genau EINEN Canvas je Component hält
 * (der Foodbook-Canvas belegt den im Cockpit schon). Hängt am CRM-Kunden
 * (owner_type=crm_company); wird pro Foodbook im Kreativ-Tab eingebettet und über
 * wire:key an die company_id gebunden (Re-Mount bei Kunden-Wechsel).
 */
class KundeDnaPanel extends Component
{
    use ManagesCanvas;

    public ?int $companyId = null;

    public function mount(?int $companyId = null): void
    {
        $this->companyId = $companyId;
        if ($companyId !== null) {
            $this->canvasInit('kunde_dna', 'crm_company', $companyId);
        }
    }

    public function render()
    {
        return view('foodalchemist::livewire.foodbooks.kunde-dna-panel');
    }
}
