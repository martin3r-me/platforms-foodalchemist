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

    /** UX-Ausbau: Eingabe fürs neue Gerüst-Slot-Label (Angebot-Gerüst-Review-Tab). */
    public string $neuerSlot = '';

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

    /**
     * E2 (Spec 40): Voll-Kaskade fürs Angebot — je Frame-Slot ein Konzept, ans Angebot referenziert
     * (spiegelt Foodbook/Speisekarte, Rückweg via {@see AngebotService::referenziereConcept}). Slots kann der
     * Mensch VOR der Kaskade im Gerüst-Tab prüfen/bauen ({@see geruestSlotNeu}/{@see geruestKickoff}); ist noch
     * KEIN Gerüst da, wird als Fallback EINMAL aus dem Angebots-Kopf (Anlass/Gäste) auto-strukturiert. Springt in
     * die Leitstelle — Owner-Banner + Zurück-Link (E1b) zeigen den Round-Trip.
     */
    public function vollKaskadeStarten(
        \Platform\FoodAlchemist\Services\PlanningSessionService $sessions,
        \Platform\FoodAlchemist\Services\PlanningCascadeService $cascade,
        \Platform\FoodAlchemist\Services\ConceptGeneratorService $gen,
    ) {
        $team = $this->team();
        if ($this->selectedId === null) {
            return null;
        }
        $angebot = \Platform\FoodAlchemist\Models\FoodAlchemistAngebot::visibleToTeam($team)->find($this->selectedId);
        if ($angebot === null) {
            return null;
        }
        try {
            $frame = app(\Platform\FoodAlchemist\Services\PlanningFrameService::class)->find('offer', (int) $this->selectedId);
            if ($frame === null || $frame->slots()->count() === 0) {
                $gen->geruestAusBriefFuerOwner($team, 'offer', (int) $this->selectedId, $this->angebotBrief($angebot));
            }
            $session = $sessions->create($team, [
                'title' => 'Voll-Kaskade: ' . ($angebot->name ?: ('Angebot #' . $this->selectedId)),
                'created_via' => 'angebot_vollkaskade',
            ]);
            $cascade->starteKaskade($team, 'vollkaskade', $session, 'voll_kreativ', [
                'owner_type' => 'offer', 'owner_id' => (int) $this->selectedId, 'created_via' => 'angebot_vollkaskade',
            ]);

            return redirect()->route('foodalchemist.planung.index', ['session' => $session->id, 'open' => 1]);
        } catch (\Throwable $e) {
            $this->errorToast($e->getMessage());

            return null;
        }
    }

    /** Minimaler Brief fürs Auto-Gerüst aus den Angebots-Kopf-Feldern (Anlass/Gäste). */
    private function angebotBrief(\Platform\FoodAlchemist\Models\FoodAlchemistAngebot $a): string
    {
        $teile = [];
        if (trim((string) $a->occasion) !== '') {
            $teile[] = 'Anlass: ' . trim((string) $a->occasion);
        }
        if ((int) $a->personen > 0) {
            $teile[] = 'Gäste: ' . (int) $a->personen . ' Personen';
        }

        return implode(' — ', $teile);
    }

    // ── UX-Ausbau: Angebot-Gerüst-Review (Slots VOR der Kaskade prüfen/bauen) ──

    /** Slot ans Angebots-Gerüst anhängen (frameFor legt das Gerüst bei Bedarf an). */
    public function geruestSlotNeu(\Platform\FoodAlchemist\Services\PlanningFrameService $frames): void
    {
        if ($this->selectedId === null || trim($this->neuerSlot) === '') {
            return;
        }
        try {
            $frame = $frames->frameFor($this->team(), 'offer', (int) $this->selectedId);
            $frames->addSlot($this->team(), $frame, ['label' => trim($this->neuerSlot)]);
            $this->neuerSlot = '';
        } catch (\Throwable $e) {
            $this->errorToast($e->getMessage());
        }
    }

    /** Einen Gerüst-Slot löschen. */
    public function geruestSlotLoeschen(int $slotId, \Platform\FoodAlchemist\Services\PlanningFrameService $frames): void
    {
        try {
            $frames->removeSlot($this->team(), $slotId);
        } catch (\Throwable $e) {
            $this->errorToast($e->getMessage());
        }
    }

    /** KI-Kickoff: Slots aus dem Angebots-Brief (Anlass/Gäste) vorschlagen — graceful ohne Provider. */
    public function geruestKickoff(\Platform\FoodAlchemist\Services\ConceptGeneratorService $gen): void
    {
        $team = $this->team();
        if ($this->selectedId === null) {
            return;
        }
        $angebot = \Platform\FoodAlchemist\Models\FoodAlchemistAngebot::visibleToTeam($team)->find($this->selectedId);
        if ($angebot === null) {
            return;
        }
        try {
            $gen->geruestAusBriefFuerOwner($team, 'offer', (int) $this->selectedId, $this->angebotBrief($angebot));
            $this->savedToast('Gerüst-Vorschlag erstellt — prüfe/ergänze die Slots, dann Voll-Kaskade.');
        } catch (\Throwable $e) {
            $this->errorToast($e->getMessage());
        }
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

        // UX-Ausbau: Angebot-Gerüst-Slots (read-only) für den Review-Tab — kein Create beim Rendern.
        $offerFrame = $this->selectedId !== null
            ? app(\Platform\FoodAlchemist\Services\PlanningFrameService::class)->find('offer', (int) $this->selectedId)
            : null;
        $geruestSlots = $offerFrame !== null
            ? $offerFrame->slots()->orderBy('position')->orderBy('id')->get(['id', 'label', 'target_count', 'price_anchor'])
            : collect();

        $facetten = $svc->facetten($this->team());

        return view('foodalchemist::livewire.angebote.editor', [
            'angebot' => $angebot,
            'geruestSlots' => $geruestSlots,
            'kalkulation' => $angebot ? $svc->kalkulation($this->team(), $angebot) : null,
            'statusWerte' => $svc->statusWerte(),
            'firmen' => $svc->sucheFirmen($this->firmaSuche),
            'kontakte' => $svc->sucheKontakte($this->kontaktSuche),
            'crmVerfuegbar' => $svc->crmVerfuegbar(),
            'katalogTreffer' => $this->selectedId !== null
                ? $svc->katalogConcepts($this->team(), $this->conceptSuche, 50, $this->conceptFacetten)
                : collect(),
            'facetteEventtypen' => $facetten['eventtypen'],
            'facetteServierformen' => $facetten['servierformen'],
            'facetteMomente' => $facetten['momente'],
            'facetteSaisons' => $facetten['saisons'],
        ]);
    }

    private function team()
    {
        return Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
    }
}
