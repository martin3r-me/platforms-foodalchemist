<?php

namespace Platform\FoodAlchemist\Livewire\Settings;

use Livewire\Component;
use Platform\FoodAlchemist\Livewire\Concerns\ManagesCanvas;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Support\CrmKunden;

/**
 * Kunde-DNA als Einstellungen-Sektion (Ebene 2 der DNA-Kette Team → Kunde → Foodbook).
 * Spec 42 F3: die Marken-/Kunden-DNA gehört zum KUNDEN, nicht pro Foodbook — der Autoren-Canvas
 * zog aus dem (entfernten) Foodbook-DNA-Tab hierher. Firma per Suche wählen → geteiltes Canvas-Board
 * (owner_type=crm_company). Der Lese-Pfad (CanvasService::cascadeKontext, Ebene kunde_dna) ist
 * unverändert — hier zieht nur die Autoren-Fläche um. Muster: {@see FoodDna} (Team-DNA, Ebene 1).
 *
 * Einstieg aus dem CRM (2026-10-07): `?firma=<crm_company_id>` öffnet die Firma direkt — die Plattform
 * verlinkt so von der CRM-Firmenseite hierher. Jede Firmen-ID (URL wie Klick) läuft durch
 * {@see CrmKunden}: nur Firmen des eigenen Haupt-Teams, der Name kommt aus der DB, nie vom Client.
 */
class KundeDna extends Component
{
    use ManagesCanvas;

    public string $firmaSuche = '';

    public ?int $companyId = null;

    public ?string $companyName = null;

    public ?string $firmaFehler = null;

    public function mount(): void
    {
        $firma = (int) request()->query('firma', 0);
        if ($firma > 0) {
            $this->firmaWaehlen($firma);
        }
    }

    /** Firma wählen → Canvas (kunde_dna, crm_company) initialisieren + laden. Fremde/unbekannte Firma → Hinweis, nichts geöffnet. */
    public function firmaWaehlen(int $companyId): void
    {
        $this->firmaFehler = null;
        $firma = CrmKunden::firma(CrmKunden::aktuellesTeam(), $companyId);
        if ($firma === null) {
            $this->firmaFehler = 'Diese Firma gibt es in deiner Kundenverwaltung nicht.';

            return;
        }

        $this->companyId = (int) $firma->id;
        $this->companyName = $firma->display_name;
        $this->firmaSuche = '';
        $this->canvasInit('kunde_dna', 'crm_company', $this->companyId);
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
