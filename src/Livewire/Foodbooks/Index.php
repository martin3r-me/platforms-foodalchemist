<?php

namespace Platform\FoodAlchemist\Livewire\Foodbooks;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Platform\FoodAlchemist\Livewire\Concerns\ManagesCanvas;
use Platform\FoodAlchemist\Livewire\Concerns\ManagesPhase;
use Platform\FoodAlchemist\Livewire\Concerns\ManagesPlanningFrame;
use Platform\FoodAlchemist\Services\FoodbookService;

/**
 * M11-03 / Doc 15 §9.3: Foodbook-Editor — stellt fertige **Concepts** zu einem
 * Kunden-Angebot zusammen (KEINE Einzel-Gerichte — der Concepter ist der Kern).
 * Foodbook-Liste + Kapitel-Baum links · Block-Liste Mitte · Pax-Gesamt-Cockpit rechts.
 */
class Index extends Component
{
    use WithPagination, WithFileUploads, ManagesCanvas, ManagesPlanningFrame, ManagesPhase;

    // ── Phase 6: Branding / CI (pro Foodbook) — verdrahtet die FoodbookService-Branding-API ──
    public array $brandingForm = ['brand_color' => '#6d28d9', 'band_color' => '', 'footer_text' => ''];

    public $logoUpload = null;

    public $coverUpload = null;

    public ?int $brandingLoadedId = null;

    public ?string $brandingFehler = null;

    public bool $brandingGespeichert = false;

    public function brandingSpeichern(FoodbookService $svc): void
    {
        $this->brandingFehler = null;
        $this->brandingGespeichert = false;
        if ($this->selectedId === null) {
            return;
        }
        try {
            $fb = $svc->setBranding($this->team(), $this->selectedId, [
                'brand_color' => $this->brandingForm['brand_color'] ?? '#6d28d9',
                'band_color' => $this->brandingForm['band_color'] ?? '',
                'footer_text' => $this->brandingForm['footer_text'] ?? '',
            ]);
            $this->brandingForm = [
                'brand_color' => $fb->brand_color ?? '#6d28d9',
                'band_color' => $fb->band_color ?? '',
                'footer_text' => $fb->footer_text ?? '',
            ];
            $this->brandingGespeichert = true;
        } catch (\RuntimeException $e) {
            // Hex-Murks oder geerbtes Foodbook (Owner-Guard D1) → sauber als UI-Fehler.
            $this->brandingFehler = $e->getMessage();
        }
    }

    public function updatedLogoUpload(): void
    {
        $this->brandingBildHochladen('logoUpload', 'storeLogo');
    }

    public function updatedCoverUpload(): void
    {
        $this->brandingBildHochladen('coverUpload', 'storeCover');
    }

    /** Auto-Upload bei Dateiwahl: validieren → Service (räumt Altdatei) → Feld leeren. */
    private function brandingBildHochladen(string $prop, string $serviceMethod): void
    {
        $this->brandingFehler = null;
        if ($this->selectedId === null || $this->{$prop} === null) {
            return;
        }
        $this->validate([$prop => 'image|max:8192'], [], [$prop => $prop === 'logoUpload' ? 'Logo' : 'Cover-Bild']);
        try {
            app(FoodbookService::class)->{$serviceMethod}($this->team(), $this->selectedId, $this->{$prop});
        } catch (\RuntimeException $e) {
            $this->brandingFehler = $e->getMessage();
        }
        $this->reset($prop);
    }

    public function brandingLogoEntfernen(FoodbookService $svc): void
    {
        if ($this->selectedId !== null) {
            $svc->clearLogo($this->team(), $this->selectedId);
        }
    }

    public function brandingCoverEntfernen(FoodbookService $svc): void
    {
        if ($this->selectedId !== null) {
            $svc->clearCover($this->team(), $this->selectedId);
        }
    }

    /** R4.3: Owner für den Phasen-Stepper (Trait ManagesPhase). */
    protected function phaseOwner(): array
    {
        return ['foodbook', $this->selectedId];
    }

    // ── Phase 3 (Weg B): per-Slot-Vorschläge → abstimmen → übernehmen ──
    // Ersetzt den alten Monolith „Konzept aus Gerüst" (ein Gerüst → ein Konzept war die
    // falsche Abstraktion, Dominique 2026-07-21). Jetzt schlägt die Engine je Slot vor,
    // der Mensch stimmt ab, Übernehmen landet im Slot-Kapitel-Konzept.
    /** slotId → Liste vorgeschlagener Gerichte {id,name,diet_form,sales_net}. */
    public array $slotVorschlaege = [];

    public function vorschlaegeFuerSlot(int $slotId): void
    {
        if ($this->selectedId === null || $this->frameId === null) {
            return;
        }
        $frame = \Platform\FoodAlchemist\Models\FoodAlchemistPlanningFrame::find($this->frameId);
        $slot = \Platform\FoodAlchemist\Models\FoodAlchemistPlanningFrameSlot::find($slotId);
        if ($frame === null || $slot === null) {
            return;
        }
        // Phase 5: Segment-Niveau bevorzugen (Rezepte mit passender Niveau-Eignung ranken höher).
        $zielNiveau = app(\Platform\FoodAlchemist\Services\TeamSettingsService::class)->segment($this->team())['niveau'] ?? null;
        $this->slotVorschlaege[$slotId] = app(\Platform\FoodAlchemist\Services\ConceptGeneratorService::class)
            ->slotVorschlaege($this->team(), $frame, $slot, 6, $zielNiveau);
    }

    /** Weg B: Gericht in den Slot übernehmen (Slot-Kapitel-Konzept) + aus der Vorschlagsliste nehmen. */
    public function uebernehmeGericht(int $slotId, int $recipeId, FoodbookService $svc): void
    {
        if ($this->selectedId === null) {
            return;
        }
        $svc->uebernehmeVorschlag($this->team(), $this->selectedId, $slotId, $recipeId);
        $this->entferneVorschlag($slotId, $recipeId);
    }

    public function verwerfeGericht(int $slotId, int $recipeId): void
    {
        $this->entferneVorschlag($slotId, $recipeId);
    }

    private function entferneVorschlag(int $slotId, int $recipeId): void
    {
        if (isset($this->slotVorschlaege[$slotId])) {
            $this->slotVorschlaege[$slotId] = array_values(array_filter(
                $this->slotVorschlaege[$slotId],
                fn ($v) => (int) $v['id'] !== $recipeId,
            ));
        }
    }

    // ── Phase 3a: „Struktur anwenden" — Gerüst-Slots als Kapitel materialisieren (Slot = Kapitel) ──
    public ?array $strukturErgebnis = null;

    public function strukturAnwenden(FoodbookService $svc): void
    {
        $this->strukturErgebnis = null;
        if ($this->selectedId === null) {
            return;
        }
        $this->strukturErgebnis = $svc->strukturAusGeruest($this->team(), $this->selectedId);
        // Gerüst neu laden, damit $frameSlots die frisch gesetzten chapter_id trägt
        // (sonst bleiben die per-Slot-Vorschläge-Buttons fälschlich disabled).
        $this->frameLaden();
    }

    // ── Phase 5: Kickoff-Wizard „Neues Foodbook für Kunde X" (Brief → KI-Gerüst-Vorschlag) ──
    // Minimale Rückfrage (Anlass/Gäste/Saison/Service-Form/Budget) + Auto-Kontext (Segment +
    // DNA-Kaskade Team→Kunde→Foodbook) → LLM schlägt das Gerüst vor. Doktrin: Vorschlag, nicht
    // Zwang — das Gerüst landet im Planung-Tab, der User prüft und ruft dann „Struktur anwenden".
    // Der LLM-Call läuft über den Core-Contract (AiGatewayService); ohne gebundenen Provider
    // wirft er typisiert und wird hier als UI-Fehler abgefangen (kein 500).
    public array $kickoff = ['anlass' => '', 'personen' => null, 'saison' => '', 'service_form' => '', 'budget' => null];

    public ?array $kickoffErgebnis = null;

    public ?string $kickoffFehler = null;

    public function frameAusBriefVorschlagen(): void
    {
        $this->kickoffFehler = null;
        $this->kickoffErgebnis = null;
        if ($this->selectedId === null) {
            return;
        }
        $team = $this->team();
        $fb = app(FoodbookService::class)->detail($team, $this->selectedId);
        if ($fb === null) {
            return;
        }

        $brief = $this->kickoffBriefText($fb);
        if (trim($brief) === '') {
            $this->kickoffFehler = 'Bitte mindestens Anlass oder Gäste-Zahl angeben.';
            return;
        }

        // Auto-Kontext: Segment (Bespielung) + Marken-Kontext aus der DNA-Kaskade.
        $seg = app(\Platform\FoodAlchemist\Services\TeamSettingsService::class)->segment($team);
        $kaskade = app(\Platform\FoodAlchemist\Services\CanvasService::class)
            ->cascadeKontext($team, null, $fb->id, null, $fb->crm_company_id);

        try {
            $res = app(\Platform\FoodAlchemist\Services\ConceptGeneratorService::class)->geruestAusBriefFuerOwner(
                $team,
                'foodbook',
                $fb->id,
                $brief,
                [
                    'segment' => $seg,
                    'marken_kontext' => $kaskade['marken_kontext'] ?? null,
                ],
            );
            // Frame-Objekt NICHT in den Livewire-State (nicht serialisierbar) — nur die Kennzahlen.
            $this->kickoffErgebnis = ['slots' => $res['slots'], 'confidence' => $res['confidence'], 'name' => $res['name']];
            $this->frameLaden();   // frisches Gerüst → Planung-Tab zeigt die vorgeschlagenen Slots
        } catch (\Platform\FoodAlchemist\Exceptions\KiDeaktiviertException $e) {
            $this->kickoffFehler = 'KI ist für dieses Team deaktiviert (Einstellungen → Food DNA / KI).';
        } catch (\Platform\FoodAlchemist\Exceptions\KiNichtVerfuegbarException $e) {
            $this->kickoffFehler = 'Kein KI-Provider gebunden — der Kickoff-Vorschlag braucht ein aktives Modell (demo). Gerüst manuell im Planung-Tab anlegen.';
        } catch (\RuntimeException $e) {
            $this->kickoffFehler = $e->getMessage();
        }
    }

    /** Baut den minimalen Freitext-Brief aus den Kickoff-Feldern + Foodbook-Kontext. */
    private function kickoffBriefText($fb): string
    {
        $teile = [];
        if (trim((string) $this->kickoff['anlass']) !== '') {
            $teile[] = 'Anlass: ' . trim((string) $this->kickoff['anlass']);
        }
        $pers = $this->kickoff['personen'] ?: $fb->personen;
        if ($pers) {
            $teile[] = 'Gäste: ' . (int) $pers . ' Personen';
        }
        if (trim((string) $this->kickoff['saison']) !== '') {
            $teile[] = 'Saison: ' . trim((string) $this->kickoff['saison']);
        }
        if (trim((string) $this->kickoff['service_form']) !== '') {
            $teile[] = 'Service-Form: ' . trim((string) $this->kickoff['service_form']);
        }
        if ($this->kickoff['budget'] !== null && $this->kickoff['budget'] !== '') {
            $teile[] = 'Budget: ' . (float) $this->kickoff['budget'] . ' € pro Person';
        }
        if (trim((string) ($fb->description ?? '')) !== '') {
            $teile[] = 'Kontext: ' . trim((string) $fb->description);
        }

        return implode("\n", $teile);
    }

    #[Url(as: 'q')]
    public string $search = '';

    /** R4.3: Phasen-Filter der Browser-Liste. */
    #[Url(as: 'phase')]
    public string $phaseFilter = '';

    #[Url(as: 'fb')]
    public ?int $selectedId = null;

    #[Url(as: 'kap')]
    public ?int $selectedKapitelId = null;

    public array $form = ['label' => '', 'customer' => '', 'jahr' => null, 'personen' => null, 'status' => 'draft', 'description' => ''];

    public array $kapitelForm = ['title' => '', 'consumer_title' => '', 'price_mode' => 'auto', 'price_per_person' => null];

    public string $neuesKapitelTitel = '';

    public string $conceptSuche = '';

    public ?int $conceptKategorie = null;

    /** #369: CRM-Kunde-Picker. */
    public string $firmaSuche = '';

    public string $kontaktSuche = '';

    /** Block, dessen Inline-Editor offen ist + dessen Formular. */
    public ?int $editBlockId = null;

    public array $blockForm = [];

    /** markierte concept_ref-Blöcke für die Wahl-Gruppe. */
    public array $markiert = [];

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    // ── Foodbook ──────────────────────────────────────────────────────────

    public function neu(FoodbookService $svc): void
    {
        $fb = $svc->create($this->team(), ['label' => 'Neues Foodbook']);
        $this->waehle($fb->id, $svc);
    }

    public function waehle(int $id, FoodbookService $svc): void
    {
        $fb = $svc->detail($this->team(), $id);
        if ($fb === null) {
            return;
        }
        $this->selectedId = $id;
        $this->form = [
            'label' => $fb->label, 'customer' => $fb->customer ?? '', 'jahr' => $fb->jahr,
            'personen' => $fb->personen, 'status' => $fb->status, 'description' => $fb->description ?? '',
        ];
        // UX 2026-07-21: Foodbook-Wahl landet auf dem Foodbook-KOPF (übergeordnete Ebene),
        // NICHT mehr automatisch im ersten Kapitel — Kopf und Speisen sind getrennte Ansichten.
        $this->selectedKapitelId = null;
        $this->editBlockId = null;
        $this->markiert = [];
    }

    /** UX 2026-07-21: zurück auf den Foodbook-Kopf (Master-Detail: kein Kapitel gewählt). */
    public function kopfAnzeigen(): void
    {
        $this->selectedKapitelId = null;
        $this->editBlockId = null;
        $this->markiert = [];
    }

    public function speichern(FoodbookService $svc): void
    {
        if ($this->selectedId !== null) {
            $svc->update($this->team(), $this->selectedId, $this->form);
        }
    }

    /**
     * Kreativ-Tab: Foodbook-Tonalität (Schreibstil-Override) setzen. Leer = Default-Kaskade
     * (Team-DNA → Kunde-DNA). Der gewählte Stil führt über die Defaults (CanvasService::cascadeKontext).
     */
    public function tonalitaetSetzen($styleId, FoodbookService $svc): void
    {
        if ($this->selectedId === null) {
            return;
        }
        $svc->update($this->team(), $this->selectedId, [
            'writing_style_id' => ($styleId === '' || $styleId === null) ? null : (int) $styleId,
        ]);
    }

    // ── #369: CRM-Kunde-Link (MVP, nur verlinken) ──────────────────────────────

    public function verknuepfeFirma(int $companyId, FoodbookService $svc): void
    {
        if ($this->selectedId === null) {
            return;
        }
        $fb = $svc->detail($this->team(), $this->selectedId);
        $svc->verknuepfeKunde($this->team(), $this->selectedId, $companyId, $fb?->crm_contact_id);
        $this->firmaSuche = '';
    }

    public function verknuepfeKontakt(int $contactId, FoodbookService $svc): void
    {
        if ($this->selectedId === null) {
            return;
        }
        $fb = $svc->detail($this->team(), $this->selectedId);
        $svc->verknuepfeKunde($this->team(), $this->selectedId, $fb?->crm_company_id, $contactId);
        $this->kontaktSuche = '';
    }

    public function loeseKunde(FoodbookService $svc): void
    {
        if ($this->selectedId === null) {
            return;
        }
        $svc->verknuepfeKunde($this->team(), $this->selectedId, null, null);
    }

    public function loeschen(int $id, FoodbookService $svc): void
    {
        $svc->delete($this->team(), $id);
        if ($this->selectedId === $id) {
            $this->selectedId = null;
            $this->selectedKapitelId = null;
        }
    }

    // ── Kapitel ───────────────────────────────────────────────────────────

    public function kapitelNeu(?int $parentId = null): void
    {
        if ($this->selectedId === null) {
            return;
        }
        $svc = app(FoodbookService::class);   // via Container, nicht als Action-Param — sonst kollidiert die DI mit $parentId
        $titel = $parentId !== null ? 'Neues Unterkapitel' : ($this->neuesKapitelTitel ?: 'Neues Kapitel');
        $k = $svc->addKapitel($this->team(), $this->selectedId, ['title' => $titel], $parentId);
        $this->neuesKapitelTitel = '';
        $this->selectedKapitelId = $k->id;
        $this->ladeKapitelForm($svc);
    }

    public function kapitelWaehle(int $id, FoodbookService $svc): void
    {
        $this->selectedKapitelId = $id;
        $this->ladeKapitelForm($svc);
        $this->editBlockId = null;
        $this->markiert = [];
    }

    private function ladeKapitelForm(FoodbookService $svc): void
    {
        if ($this->selectedKapitelId === null) {
            return;
        }
        $k = \Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel::find($this->selectedKapitelId);
        if ($k) {
            $this->kapitelForm = [
                'title' => $k->title, 'consumer_title' => $k->consumer_title ?? '',
                'price_mode' => $k->price_mode, 'price_per_person' => $k->price_per_person,
            ];
        }
    }

    public function kapitelSpeichern(FoodbookService $svc): void
    {
        if ($this->selectedKapitelId !== null) {
            $svc->updateKapitel($this->team(), $this->selectedKapitelId, $this->kapitelForm);
        }
    }

    public function kapitelLoeschen(int $id, FoodbookService $svc): void
    {
        $svc->deleteKapitel($this->team(), $id);
        if ($this->selectedKapitelId === $id) {
            $this->selectedKapitelId = null;
        }
    }

    public function kapitelHoch(int $id, FoodbookService $svc): void
    {
        $this->verschiebeKapitel($id, -1, $svc);
    }

    public function kapitelRunter(int $id, FoodbookService $svc): void
    {
        $this->verschiebeKapitel($id, 1, $svc);
    }

    private function verschiebeKapitel(int $id, int $richtung, FoodbookService $svc): void
    {
        $k = \Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel::find($id);
        if ($k === null || $this->selectedId === null) {
            return;
        }
        $geschwister = \Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel::where('foodbook_id', $this->selectedId)
            ->where('parent_id', $k->parent_id)->orderBy('position')->pluck('id')->all();
        $pos = array_search($id, $geschwister, true);
        $ziel = $pos + $richtung;
        if ($pos === false || $ziel < 0 || $ziel >= count($geschwister)) {
            return;
        }
        [$geschwister[$pos], $geschwister[$ziel]] = [$geschwister[$ziel], $geschwister[$pos]];
        $svc->reorderKapitel($this->team(), $this->selectedId, $k->parent_id, $geschwister);
    }

    // ── Blöcke ────────────────────────────────────────────────────────────

    public function conceptHinzu(int $conceptId, FoodbookService $svc): void
    {
        if ($this->selectedKapitelId === null) {
            return;
        }
        $svc->addBlock($this->team(), $this->selectedKapitelId, ['type' => 'concept_ref', 'concept_id' => $conceptId]);
        $this->conceptSuche = '';
    }

    public function presetHinzu(string $type, ?string $slug, ?string $label, ?string $preisBasis, bool $sichtbar, FoodbookService $svc): void
    {
        if ($this->selectedKapitelId === null) {
            return;
        }
        $svc->addBlock($this->team(), $this->selectedKapitelId, [
            'type' => $type, 'header_source' => $slug, 'label' => $label,
            'price_basis' => $type === 'header_frei_preis' ? ($preisBasis ?: 'person') : null,
            'price_value' => $type === 'header_frei_preis' ? 0 : null,
            'visible' => $sichtbar,
        ]);
    }

    public function blockBasis(string $type, FoodbookService $svc): void
    {
        if ($this->selectedKapitelId === null) {
            return;
        }
        $svc->addBlock($this->team(), $this->selectedKapitelId, [
            'type' => $type,
            'height' => $type === 'spacer' ? 'mittel' : null,
            'price_basis' => $type === 'header_frei_preis' ? 'person' : null,
            'price_value' => $type === 'header_frei_preis' ? 0 : null,
        ]);
    }

    public function blockBearbeiten(int $id): void
    {
        $block = \Platform\FoodAlchemist\Models\FoodAlchemistFoodbookBlock::find($id);
        if ($block === null) {
            return;
        }
        $this->editBlockId = $id;
        $this->blockForm = [
            'label' => $block->label ?? '', 'wording' => $block->wording ?? '',
            'customer_text' => $block->customer_text ?? '',
            'price_value' => $block->price_value, 'price_basis' => $block->price_basis ?? 'person',
            'height' => $block->height ?? 'mittel', 'interne_bemerkung' => $block->interne_bemerkung ?? '',
        ];
    }

    /**
     * ✨ Kundentext-Vorschlag für den gerade editierten concept_ref-Block —
     * der Marketing-Text lebt seit dem UX-Umbau 2026-07-03 HIER (kundenspezifisch)
     * statt am Gericht (recipes.marketing_text = Alt-Feld, nur noch Import-Spiegel).
     */
    public function kiKundentext(): void
    {
        if ($this->editBlockId === null) {
            return;
        }
        $block = \Platform\FoodAlchemist\Models\FoodAlchemistFoodbookBlock::visibleToTeam($this->team())->with('concept.slots.dish:id,name,sales_wording_standard')->find($this->editBlockId);
        if ($block === null || $block->concept === null) {
            return;
        }
        $wording = app(\Platform\FoodAlchemist\Services\WordingResolver::class);
        $kontext = [
            'concept' => $block->concept->name,
            'anzeigename' => trim((string) ($this->blockForm['wording'] ?? '')) !== '' ? $this->blockForm['wording'] : null,
            'gerichte' => collect($wording->gerichtZeilen($block->concept, $block))
                ->where('type', 'gericht')->pluck('text')->values()->all(),
        ];
        try {
            $v = app(\Platform\FoodAlchemist\Services\Ai\AiGatewayService::class)->propose('vk.marketing', $kontext, [
                'food_dna_foodbook_id' => $this->selectedId,
                // Ebene 2 der DNA-Kette: Endkunde des Foodbooks (Kunde-DNA fließt in den Marketing-Text)
                'food_dna_crm_company_id' => \Platform\FoodAlchemist\Models\FoodAlchemistFoodbook::whereKey($this->selectedId)->value('crm_company_id'),
                'target_table' => 'foodalchemist_foodbook_blocks', 'target_id' => $block->id,
            ]);
            $text = $v->werte['marketing_text'] ?? null;
            if (is_string($text) && trim($text) !== '') {
                $this->blockForm['customer_text'] = trim($text);
            }
        } catch (\Throwable $e) {
            // still — Feld bleibt unverändert (Kill-Switch/Provider-Fehler); kein Crash im Editor
        }
    }

    public function blockSpeichern(FoodbookService $svc): void
    {
        if ($this->editBlockId !== null) {
            $svc->updateBlock($this->team(), $this->editBlockId, $this->blockForm);
        }
        $this->editBlockId = null;
    }

    public function blockRaus(int $id, FoodbookService $svc): void
    {
        $svc->deleteBlock($this->team(), $id);
    }

    public function blockSichtbar(int $id, FoodbookService $svc): void
    {
        $block = \Platform\FoodAlchemist\Models\FoodAlchemistFoodbookBlock::find($id);
        if ($block !== null) {
            $svc->updateBlock($this->team(), $id, ['visible' => ! $block->visible]);
        }
    }

    public function blockEbene(int $id, int $delta, FoodbookService $svc): void
    {
        $block = \Platform\FoodAlchemist\Models\FoodAlchemistFoodbookBlock::find($id);
        if ($block !== null) {
            $svc->updateBlock($this->team(), $id, ['level' => max(0, min(2, (int) $block->level + $delta))]);
        }
    }

    public function blockHoch(int $id, FoodbookService $svc): void
    {
        $this->verschiebeBlock($id, -1, $svc);
    }

    public function blockRunter(int $id, FoodbookService $svc): void
    {
        $this->verschiebeBlock($id, 1, $svc);
    }

    private function verschiebeBlock(int $id, int $richtung, FoodbookService $svc): void
    {
        if ($this->selectedKapitelId === null) {
            return;
        }
        $ids = \Platform\FoodAlchemist\Models\FoodAlchemistFoodbookBlock::where('chapter_id', $this->selectedKapitelId)
            ->orderBy('position')->pluck('id')->all();
        $pos = array_search($id, $ids, true);
        $ziel = $pos + $richtung;
        if ($pos === false || $ziel < 0 || $ziel >= count($ids)) {
            return;
        }
        [$ids[$pos], $ids[$ziel]] = [$ids[$ziel], $ids[$pos]];
        $svc->reorderBlocks($this->team(), $this->selectedKapitelId, $ids);
    }

    /**
     * Drag & Drop: Block `$id` HINTER Block `$afterId` einsortieren (Insert-after,
     * spiegelt Concepter::positionNach — gleiche UX über beide Editoren; ▲▼ bleibt
     * als zuverlässige Kanten-Alternative). Der Ziehgriff sitzt in der Block-Zeile.
     */
    public function blockVerschiebenAuf(int $id, int $afterId, FoodbookService $svc): void
    {
        if ($this->selectedKapitelId === null || $id === $afterId) {
            return;
        }
        $ids = \Platform\FoodAlchemist\Models\FoodAlchemistFoodbookBlock::where('chapter_id', $this->selectedKapitelId)
            ->orderBy('position')->pluck('id')->map(fn ($x) => (int) $x)->all();
        $ids = array_values(array_filter($ids, fn ($x) => $x !== $id));
        $pos = array_search($afterId, $ids, true);
        if ($pos === false) {
            return; // Ziel gehört nicht zum Kapitel — kein blinder Append
        }
        array_splice($ids, $pos + 1, 0, [$id]);
        $svc->reorderBlocks($this->team(), $this->selectedKapitelId, $ids);
    }

    // ── Wahl-Gruppe (A|B|C zwischen Concepts) ───────────────────────────────

    public function markiere(int $id): void
    {
        $this->markiert = in_array($id, $this->markiert, true)
            ? array_values(array_diff($this->markiert, [$id]))
            : [...$this->markiert, $id];
    }

    public function wahlGruppeBilden(FoodbookService $svc): void
    {
        if (count($this->markiert) < 2 || $this->selectedKapitelId === null) {
            return;
        }
        $gid = $svc->nextVariantGroupId($this->team(), $this->selectedKapitelId);
        $svc->setVariantGroup($this->team(), $this->markiert, $gid);
        $this->markiert = [];
    }

    public function wahlGruppeAufheben(int $id, FoodbookService $svc): void
    {
        $svc->setVariantGroup($this->team(), [$id], null);
    }

    public function render(FoodbookService $svc)
    {
        $team = $this->team();
        $fb = $this->selectedId !== null ? $svc->detail($team, $this->selectedId) : null;
        $kapitel = $fb !== null && $this->selectedKapitelId !== null
            ? $fb->chapters->firstWhere('id', $this->selectedKapitelId) : null;

        // #389/Canvas: Foodbook-Leitidee-Canvas nur bei Selektions-WECHSEL (re)laden — kein Edit-Verlust je Roundtrip.
        if ($fb !== null && $this->canvasOwnerId !== $fb->id) {
            $this->canvasInit('foodbook', 'foodbook', $fb->id);
        }

        // R4.1: Planungs-Gerüst (Soll-Rahmen) — gleiche Wechsel-Logik wie der Canvas.
        if ($fb !== null && $this->frameOwnerId !== $fb->id) {
            $this->frameInit('foodbook', $fb->id);
        }

        // Phase 6: Branding-Felder nur bei Selektions-WECHSEL aus dem Foodbook laden (kein Edit-Verlust je Roundtrip).
        if ($fb !== null && $this->brandingLoadedId !== $fb->id) {
            $this->brandingForm = [
                'brand_color' => $fb->brand_color ?? '#6d28d9',
                'band_color' => $fb->band_color ?? '',
                'footer_text' => $fb->footer_text ?? '',
            ];
            $this->brandingLoadedId = $fb->id;
        }

        $menue = $fb !== null ? $svc->dokumentDaten($team, $fb) : null;

        // R2.6: Ø-Feedback je Gericht fürs interne Foodbook (Bulk über alle Menü-Zeilen).
        // $menue ist assoziativ (customer/gesamt/kapitel…) → über $menue['kapitel'] laufen.
        $menueRecipeIds = collect($menue['kapitel'] ?? [])
            ->flatMap(fn ($k) => collect($k['bloecke'] ?? [])->flatMap(fn ($b) => collect($b['gerichte'] ?? [])->pluck('recipe_id')))
            ->filter()->map(fn ($v) => (int) $v)->unique()->values()->all();
        $feedbackAgg = $menueRecipeIds !== []
            ? app(\Platform\FoodAlchemist\Services\FeedbackService::class)->aggregatBulk($team, $menueRecipeIds)
            : [];

        // R4.2: Soll/Ist-Coverage live gegen das Planungs-Gerüst (nur wenn eines existiert).
        $coverage = null;
        if ($fb !== null && $this->frameId !== null) {
            $coverage = app(\Platform\FoodAlchemist\Services\CoverageService::class)->coverage($team, 'foodbook', $fb->id);
        }

        return view('foodalchemist::livewire.foodbooks.index', [
            'coverage' => $coverage,
            'foodbooks' => $svc->paginateBrowser(['search' => $this->search, 'phase' => $this->phaseFilter], $team),
            'fb' => $fb,
            // D (UX-Umbau): Kunden-Vorschau (Menü-Ansicht) mit aufgelöster Wording-Kette — dieselbe Quelle wie das Druck-Dokument
            'menue' => $menue,
            'feedbackAgg' => $feedbackAgg,
            'kapitelTree' => $fb !== null ? $svc->kapitelTree($team, $fb->id) : [],
            'kapitel' => $kapitel,
            'kapitelAgg' => $kapitel !== null ? $svc->kapitelAggregat($team, $kapitel, $fb?->personen) : null,
            'gesamt' => $fb !== null ? $svc->gesamt($team, $fb) : null,
            'headerPresets' => FoodbookService::headerPresets(),
            'conceptKategorien' => app(\Platform\FoodAlchemist\Services\ConceptService::class)->categoriesFlat($team),
            'conceptKandidaten' => ($this->conceptSuche !== '' || $this->conceptKategorie !== null) && $this->selectedKapitelId !== null
                ? $svc->conceptKandidaten($team, $this->conceptSuche, $this->conceptKategorie, 50) : collect(),
            // #369: CRM-Kunde-Picker
            'crmVerfuegbar' => $svc->crmVerfuegbar(),
            'firmen' => $svc->sucheFirmen($this->firmaSuche),
            'kontakte' => $svc->sucheKontakte($this->kontaktSuche),
            // Phase 4: Trend-Tab — Wissensschrank-Pull (Kategorie „trend") als Inspiration
            'trendDocs' => $fb !== null ? app(\Platform\FoodAlchemist\Services\Ai\KnowledgeContextService::class)->listDocuments('trend', 0, 8, true)['documents'] : [],
            // Phase 5: Segment (aus Küchen-Typ abgeleitet) — die Achse, an der die Planung hängt
            'segment' => app(\Platform\FoodAlchemist\Services\TeamSettingsService::class)->segment($team),
            // Kreativ-Tab: Schreibstile fürs Foodbook-Tonalitäts-Override (aktive, team-sichtbar)
            'schreibstile' => \Platform\FoodAlchemist\Models\FoodAlchemistWritingStyle::visibleToTeam($team)
                ->where('is_inactive', false)->orderBy('name')->get(['id', 'name']),
        ])->layout('platform::layouts.app');
    }

    private function team()
    {
        return Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
    }
}
