<?php

namespace Platform\FoodAlchemist\Livewire\Settings;

use Livewire\Component;
use Platform\FoodAlchemist\Livewire\Concerns\ManagesCanvas;
use Platform\FoodAlchemist\Services\FoodbookService;

/**
 * Kunde-DNA als Einstellungen-Sektion (Ebene 2 der DNA-Kette Team → Kunde → Foodbook).
 * Spec 42 F3: die Marken-/Kunden-DNA gehört zum KUNDEN, nicht pro Foodbook — der Autoren-Canvas
 * zog aus dem (entfernten) Foodbook-DNA-Tab hierher. Firma per Suche wählen → geteiltes Canvas-Board
 * (owner_type=crm_company). Der Lese-Pfad (CanvasService::cascadeKontext, Ebene kunde_dna) ist
 * unverändert — hier zieht nur die Autoren-Fläche um. Muster: {@see FoodDna} (Team-DNA, Ebene 1).
 */
class KundeDna extends Component
{
    use ManagesCanvas;

    public string $firmaSuche = '';

    public ?int $companyId = null;

    public ?string $companyName = null;

    /** Firma wählen → Canvas (kunde_dna, crm_company) initialisieren + laden. */
    public function firmaWaehlen(int $companyId, string $name): void
    {
        $this->companyId = $companyId;
        $this->companyName = $name;
        $this->firmaSuche = '';
        $this->canvasInit('kunde_dna', 'crm_company', $companyId);
    }

    public function firmaLoesen(): void
    {
        $this->companyId = null;
        $this->companyName = null;
    }

    public function render(FoodbookService $svc)
    {
        $crmVerfuegbar = $svc->crmVerfuegbar();
        $firmen = ($crmVerfuegbar && trim($this->firmaSuche) !== '') ? $svc->sucheFirmen($this->firmaSuche) : collect();

        return view('foodalchemist::livewire.settings.kunde-dna', [
            'crmVerfuegbar' => $crmVerfuegbar,
            'firmen' => $firmen,
        ]);
    }
}
