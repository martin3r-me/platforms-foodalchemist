<?php

namespace Platform\FoodAlchemist\Livewire\Angebote;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;
use Platform\FoodAlchemist\Livewire\Concerns\ManagesCanvas;
use Platform\FoodAlchemist\Services\AngebotService;

/**
 * #380 — Angebote-Detail-/Edit-Panel (rechtes Panel, am Concepter-DetailPanel
 * orientiert). Zeigt + editiert die Anfrage/Briefing-Felder, verknüpft optional
 * einen CRM-Kunden (MVP: nur verlinken) und steuert den Lifecycle-Status. Der
 * Menü-Composer (Concepter-Slots am Angebot) ist die nächste Stufe.
 */
class DetailPanel extends Component
{
    use ManagesCanvas;

    public ?int $selectedId = null;

    public array $form = [
        'name' => '', 'status' => 'anfrage', 'anlass' => '', 'personen' => null,
        'budget' => null, 'event_datum' => null, 'location' => '', 'diaet_vorgabe' => '',
        'brief' => '', 'gesamtpreis' => null, 'gueltig_bis' => null, 'preis_modus' => 'auto',
    ];

    public string $firmaSuche = '';

    public string $kontaktSuche = '';

    public string $conceptSuche = '';

    public ?int $conceptKategorie = null;

    #[On('angebot-selected')]
    public function zeige(?int $id): void
    {
        $this->selectedId = $id;
        $this->firmaSuche = '';
        $this->kontaktSuche = '';
        $this->ladeForm();
    }

    private function ladeForm(): void
    {
        if ($this->selectedId === null) {
            return;
        }
        $svc = app(AngebotService::class);
        $a = $svc->detail($this->team(), $this->selectedId);
        if ($a === null) {
            $this->selectedId = null;

            return;
        }
        $svc->aktualisiereAutoPreis($this->team(), $a);   // auto-Gesamtpreis frisch halten
        $a->refresh();
        $this->form = [
            'name' => $a->name,
            'status' => $a->status?->value ?? 'anfrage',
            'anlass' => $a->anlass,
            'personen' => $a->personen,
            'budget' => $a->budget,
            'event_datum' => $a->event_datum?->format('Y-m-d'),
            'location' => $a->location,
            'diaet_vorgabe' => $a->diaet_vorgabe,
            'brief' => $a->brief,
            'gesamtpreis' => $a->gesamtpreis,
            'gueltig_bis' => $a->gueltig_bis?->format('Y-m-d'),
            'preis_modus' => $a->preis_modus ?? 'auto',
        ];
    }

    public function speichern(AngebotService $svc): void
    {
        if ($this->selectedId === null) {
            return;
        }
        $svc->update($this->team(), $this->selectedId, $this->form);
        $this->ladeForm();   // frisch (auto-Gesamtpreis, preis_modus) zurück ins Formular
        $this->dispatch('angebot-gespeichert');
    }

    /** Lifecycle-Übergang über die Workflow-Buttons (#380). */
    public function statusSetzen(string $status, AngebotService $svc): void
    {
        if ($this->selectedId === null) {
            return;
        }
        $svc->setStatus($this->team(), $this->selectedId, $status);
        $this->ladeForm();
        $this->dispatch('angebot-gespeichert');
    }

    public function verknuepfeFirma(int $companyId, AngebotService $svc): void
    {
        if ($this->selectedId === null) {
            return;
        }
        $a = $svc->detail($this->team(), $this->selectedId);
        $svc->verknuepfeKunde($this->team(), $this->selectedId, $companyId, $a?->crm_contact_id);
        $this->firmaSuche = '';
        $this->dispatch('angebot-gespeichert');
    }

    public function verknuepfeKontakt(int $contactId, AngebotService $svc): void
    {
        if ($this->selectedId === null) {
            return;
        }
        $a = $svc->detail($this->team(), $this->selectedId);
        $svc->verknuepfeKunde($this->team(), $this->selectedId, $a?->crm_company_id, $contactId);
        $this->kontaktSuche = '';
        $this->dispatch('angebot-gespeichert');
    }

    public function loeseKunde(AngebotService $svc): void
    {
        if ($this->selectedId === null) {
            return;
        }
        $svc->verknuepfeKunde($this->team(), $this->selectedId, null, null);
        $this->dispatch('angebot-gespeichert');
    }

    public function loeschen(AngebotService $svc): void
    {
        if ($this->selectedId === null) {
            return;
        }
        $id = $this->selectedId;
        $svc->delete($this->team(), $id);
        $this->selectedId = null;
        $this->dispatch('angebot-geloescht', id: $id);
    }

    // ── Menü-Composer (angebots-lokale Concepts, im Concepter-Editor gebaut) ──

    /** Neues angebots-lokales Menü → im wiederverwendeten Concepter-Editor öffnen. */
    public function neuesMenue(AngebotService $svc): void
    {
        if ($this->selectedId === null) {
            return;
        }
        $c = $svc->neuesConcept($this->team(), $this->selectedId);
        $this->dispatch('angebot-gespeichert');
        $this->dispatch('concepter-editor.oeffnen', type: 'concepts', id: $c->id);
    }

    /** Bestehendes angebots-lokales Menü im Concepter-Editor bearbeiten. */
    public function bearbeiteMenue(int $conceptId): void
    {
        $this->dispatch('concepter-editor.oeffnen', type: 'concepts', id: $conceptId);
    }

    /** „In Concepter übernehmen" — Promote (angebot_id → NULL, standardisiert). */
    public function uebernehmeMenue(int $conceptId, AngebotService $svc): void
    {
        $svc->promoteConcept($this->team(), $conceptId);
        $this->dispatch('angebot-gespeichert');
    }

    public function entferneMenue(int $conceptId, AngebotService $svc): void
    {
        $svc->entferneConcept($this->team(), $conceptId);
        $this->dispatch('angebot-gespeichert');
    }

    /** #380 DoD-5: bestehendes Katalog-Concept referenzieren / Referenz lösen. */
    public function referenziereConcept(int $conceptId, AngebotService $svc): void
    {
        if ($this->selectedId === null) {
            return;
        }
        $svc->referenziereConcept($this->team(), $this->selectedId, $conceptId);
        $this->conceptSuche = '';
        $this->dispatch('angebot-gespeichert');
    }

    public function entferneReferenz(int $conceptId, AngebotService $svc): void
    {
        if ($this->selectedId === null) {
            return;
        }
        $svc->entferneReferenz($this->team(), $this->selectedId, $conceptId);
        $this->dispatch('angebot-gespeichert');
    }

    /** Concepter-Editor hat einen angebots-lokalen Entwurf geändert → Auto-Preis + Detail neu. */
    #[On('concepter-gespeichert')]
    public function nachConcepterEdit(AngebotService $svc): void
    {
        if ($this->selectedId !== null) {
            $svc->recomputeAngebot($this->team(), $this->selectedId);
            $this->ladeForm();
        }
    }

    public function render(AngebotService $svc)
    {
        $angebot = $this->selectedId !== null ? $svc->detail($this->team(), $this->selectedId) : null;
        if ($this->selectedId !== null && $angebot === null) {
            $this->selectedId = null;
        }

        // Canvas: Angebot-Business-Case nur bei Selektions-WECHSEL (re)laden (kein Edit-Verlust).
        if ($angebot !== null && $this->canvasOwnerId !== $angebot->id) {
            $this->canvasInit('angebot', 'angebot', $angebot->id);
        }

        return view('foodalchemist::livewire.angebote.detail-panel', [
            'angebot' => $angebot,
            'kalkulation' => $angebot ? $svc->kalkulation($this->team(), $angebot) : null,
            'statusWerte' => $svc->statusWerte(),
            'firmen' => $svc->sucheFirmen($this->firmaSuche),
            'kontakte' => $svc->sucheKontakte($this->kontaktSuche),
            'crmVerfuegbar' => $svc->crmVerfuegbar(),
            'katalogTreffer' => ($this->conceptSuche !== '' || $this->conceptKategorie !== null)
                ? $svc->katalogConcepts($this->team(), $this->conceptSuche, $this->conceptKategorie)
                : collect(),
            'conceptKategorien' => app(\Platform\FoodAlchemist\Services\ConceptService::class)->categoriesFlat($this->team()),
        ]);
    }

    private function team()
    {
        return Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
    }
}
