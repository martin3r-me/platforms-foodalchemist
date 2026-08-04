<?php

namespace Platform\FoodAlchemist\Livewire\Angebote;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;
use Platform\FoodAlchemist\Livewire\Concerns\ManagesCanvas;
use Platform\FoodAlchemist\Services\AngebotService;

/**
 * Angebote-Editor (Fullscreen-Modal, pro Angebot) — herausgezogen aus dem bisherigen
 * Detail-/Edit-Panel. Tabs: Anfrage · Menü & Kalkulation · Kunde & Business-Case.
 * Geöffnet per `angebot-editor.bearbeiten` {id}; meldet Änderungen per `angebot-gespeichert`
 * an den Browser (Liste). Menü-Composer nutzt weiter den Concepter-Editor (nested).
 */
class Editor extends Component
{
    use ManagesCanvas;
    use \Platform\FoodAlchemist\Livewire\Concerns\InteractsWithSavedToast;

    public ?int $selectedId = null;

    public array $form = [
        'name' => '', 'status' => 'anfrage', 'occasion' => '', 'personen' => null,
        'budget' => null, 'event_date' => null, 'location' => '', 'diet_requirement' => '',
        'brief' => '', 'total_price' => null, 'valid_until' => null, 'price_mode' => 'auto',
    ];

    public string $firmaSuche = '';

    public string $kontaktSuche = '';

    public string $conceptSuche = '';

    /** @var array{eventtyp:?int, servierform:?int, einsatzmoment:?int, season:?int} */
    public array $conceptFacetten = ['eventtyp' => null, 'servierform' => null, 'einsatzmoment' => null, 'season' => null];

    #[On('angebot-editor.bearbeiten')]
    public function oeffnen(int $id): void
    {
        $this->selectedId = $id;
        $this->firmaSuche = '';
        $this->kontaktSuche = '';
        $this->conceptSuche = '';
        $this->resetConceptFacetten();
        $this->ladeForm();
        $this->dispatch('modal.open', name: 'angebot-editor');
    }

    public function toggleConceptFacet(string $feld, int $id): void
    {
        if (! array_key_exists($feld, $this->conceptFacetten)) {
            return;
        }
        $this->conceptFacetten[$feld] = ($this->conceptFacetten[$feld] === $id) ? null : $id;
    }

    public function resetConceptFacetten(): void
    {
        $this->conceptFacetten = ['eventtyp' => null, 'servierform' => null, 'einsatzmoment' => null, 'season' => null];
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
        $svc->aktualisiereAutoPreis($this->team(), $a);
        $a->refresh();
        $this->form = [
            'name' => $a->name,
            'status' => $a->status?->value ?? 'anfrage',
            'occasion' => $a->occasion,
            'personen' => $a->personen,
            'budget' => $a->budget,
            'event_date' => $a->event_date?->format('Y-m-d'),
            'location' => $a->location,
            'diet_requirement' => $a->diet_requirement,
            'brief' => $a->brief,
            'total_price' => $a->total_price,
            'valid_until' => $a->valid_until?->format('Y-m-d'),
            'price_mode' => $a->price_mode ?? 'auto',
        ];
    }

    public function speichern(AngebotService $svc): void
    {
        if ($this->selectedId === null) {
            return;
        }
        $svc->update($this->team(), $this->selectedId, $this->form);
        $this->ladeForm();
        $this->dispatch('angebot-gespeichert');
        $this->savedToast('Angebot gespeichert');
    }

    /** Stufe 3 — Angebot in die Produktion übergeben (concept × Pax → Produktionsauftrag). */
    public function anProduktion(AngebotService $svc): void
    {
        if ($this->selectedId === null) {
            return;
        }
        $res = $svc->anProduktion($this->team(), $this->selectedId, \Illuminate\Support\Facades\Auth::id());
        session()->flash('angebot_produktion', $res['order_id'] !== null
            ? "In Produktion übergeben ({$res['ziele']} Ziele) — jetzt im Tagesplan planbar."
            : 'Kein Menü/Concept im Angebot — nichts zu übergeben.');
        $this->dispatch('angebot-gespeichert');
    }

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
        $this->dispatch('modal.close', name: 'angebot-editor');
        $this->dispatch('angebot-geloescht', id: $id);
    }

    // ── Menü-Composer (angebots-lokale Concepts, im Concepter-Editor gebaut) ──

    public function neuesMenue(AngebotService $svc): void
    {
        if ($this->selectedId === null) {
            return;
        }
        $c = $svc->neuesConcept($this->team(), $this->selectedId);
        $this->dispatch('angebot-gespeichert');
        $this->dispatch('concepter-editor.oeffnen', type: 'concepts', id: $c->id);
    }

    public function bearbeiteMenue(int $conceptId): void
    {
        $this->dispatch('concepter-editor.oeffnen', type: 'concepts', id: $conceptId);
    }

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

        if ($angebot !== null && $this->canvasOwnerId !== $angebot->id) {
            $this->canvasInit('angebot', 'angebot', $angebot->id);
        }

        return view('foodalchemist::livewire.angebote.editor', [
            'angebot' => $angebot,
            'kalkulation' => $angebot ? $svc->kalkulation($this->team(), $angebot) : null,
            'statusWerte' => $svc->statusWerte(),
            'firmen' => $svc->sucheFirmen($this->firmaSuche),
            'kontakte' => $svc->sucheKontakte($this->kontaktSuche),
            'crmVerfuegbar' => $svc->crmVerfuegbar(),
            'katalogTreffer' => $this->selectedId !== null
                ? $svc->katalogConcepts($this->team(), $this->conceptSuche, 50, $this->conceptFacetten)
                : collect(),
            'facetteEventtypen' => \Platform\FoodAlchemist\Models\FoodAlchemistEventtyp::visibleToTeam($this->team())->where('is_inactive', false)->orderBy('sort_order')->get(['id', 'name']),
            'facetteServierformen' => \Platform\FoodAlchemist\Models\FoodAlchemistServierform::where('is_inactive', false)->orderBy('sort_order')->get(['id', 'label']),
            'facetteMomente' => \Platform\FoodAlchemist\Models\FoodAlchemistEinsatzmoment::visibleToTeam($this->team())->where('is_inactive', false)->orderBy('sort_order')->get(['id', 'name']),
            'facetteSaisons' => \Platform\FoodAlchemist\Models\FoodAlchemistSaison::visibleToTeam($this->team())->where('is_inactive', false)->orderBy('sort_order')->get(['id', 'name']),
        ]);
    }

    private function team()
    {
        return Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
    }
}
