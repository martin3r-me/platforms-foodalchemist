<?php

namespace Platform\FoodAlchemist\Livewire\Angebote;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;
use Platform\FoodAlchemist\Livewire\Concerns\ManagesCanvas;
use Platform\FoodAlchemist\Models\FoodAlchemistAngebot;
use Platform\FoodAlchemist\Models\FoodAlchemistOfferBlock;
use Platform\FoodAlchemist\Models\FoodAlchemistOfferChapter;
use Platform\FoodAlchemist\Services\AngebotService;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Services\OfferCompositionService;
use Platform\FoodAlchemist\Services\PresentationDesignService;
use Platform\FoodAlchemist\Services\PresentationService;

/**
 * Angebote-Editor (Fullscreen-Modal, pro Angebot) — 1:1-Fork des Foodbook-Editors
 * ({@see \Platform\FoodAlchemist\Livewire\Foodbooks\Index}) aufs Angebot. Die volle
 * Editor-Maschinerie (Kapitel-Baum-CRUD, Board/Fortschritt, Katalog-Picker
 * Concept/Paket/Format/Gericht, Block-Editor mit Presets/Wahlgruppen/Slot-Wording/
 * Ebene/Sichtbar, Branding & Präsentation, KI-Texte, Bilder) — aber offer-scoped über
 * {@see AngebotService} + {@see OfferCompositionService} statt FoodbookService, und
 * FoodAlchemistFoodbook*→FoodAlchemistOffer* / foodbook_id→offer_id.
 *
 * PLUS die Angebot-Spezifika, die aus dem bisherigen Editor ERHALTEN bleiben:
 * Fullscreen-Modal (`angebot-editor.bearbeiten` → `modal.open`), Anfrage-Tab-Formular,
 * speichern/statusSetzen/loeschen/anProduktion, Voll-Kaskade (Leitstelle-Handoff),
 * Gerüst-Slots (VOR der Kaskade prüfen/bauen), CRM-Verknüpfung, ManagesCanvas,
 * InteractsWithSavedToast, `concepter-gespeichert`/`aktiver-betrieb-geaendert`.
 *
 * Anders als das Foodbook (Browser + Editor in EINER Seite) ist dies ein reiner
 * Modal-Editor pro Angebot — der Angebots-Browser lebt in {@see Index}. Es gibt daher
 * KEINE Pagination/Liste hier; das Angebot wird per `oeffnen(id)` gewählt.
 */
class Editor extends Component
{
    use ManagesCanvas;
    use WithFileUploads;
    use \Platform\FoodAlchemist\Livewire\Concerns\InteractsWithSavedToast;

    public ?int $selectedId = null;

    /** Master-Detail: gewähltes Kapitel (Speisen-/Aufbau-Ansicht). */
    public ?int $selectedKapitelId = null;

    // ── Anfrage-Tab: Angebots-Kopf (Angebot-Spezifikum, erhalten) ──────────────
    public array $form = [
        'name' => '', 'status' => 'anfrage', 'occasion' => '', 'personen' => null,
        'budget' => null, 'event_date' => null, 'location' => '', 'diet_requirement' => '',
        'brief' => '', 'total_price' => null, 'valid_until' => null, 'price_mode' => 'auto',
        'price_override_reason' => '',
    ];

    // ── Kapitel-Formular (Foodbook-Parität) ────────────────────────────────────
    /** `description` = Kapitel-Kundentext (Hinführung / Story). */
    public array $kapitelForm = ['title' => '', 'consumer_title' => '', 'description' => '', 'price_mode' => 'auto', 'price_per_person' => null, 'personen' => null, 'writing_style_id' => null, 'is_struktur' => false];

    public string $neuesKapitelTitel = '';

    // ── Katalog-Picker (concept|paket|format|gericht) ──────────────────────────
    public string $conceptSuche = '';

    public string $paketSuche = '';

    public string $formatSuche = '';

    public string $gerichtSuche = '';

    public ?int $gerichtHauptgruppe = null;

    public ?int $gerichtDishClass = null;

    /** Aktiver Katalog-Modus (Server-Modus, wie Speisekarte/Foodbook). */
    public string $pickerModus = 'concept';

    /** @var array{eventtyp:?int, servierform:?int, einsatzmoment:?int, season:?int} */
    public array $conceptFacetten = ['eventtyp' => null, 'servierform' => null, 'einsatzmoment' => null, 'season' => null];

    /** @var array{eventtyp:?int, servierform:?int, einsatzmoment:?int, season:?int} */
    public array $paketFacetten = ['eventtyp' => null, 'servierform' => null, 'einsatzmoment' => null, 'season' => null];

    // ── Block-Editor ───────────────────────────────────────────────────────────
    public ?int $editBlockId = null;

    public array $blockForm = [];

    /** Inline-editierte Gericht-Zeile in der Block-Vorschau — Key "blockId:slotId" + Text. */
    public ?string $editSlotKey = null;

    public string $editSlotWording = '';

    /** Markierte concept_ref-Blöcke für die Wahl-Gruppe. */
    public array $markiert = [];

    // ── CRM-Picker (Angebot-Spezifikum, erhalten) ──────────────────────────────
    public string $firmaSuche = '';

    public string $kontaktSuche = '';

    // ── Gerüst-Review (Angebot-Spezifikum, erhalten): Slots VOR der Kaskade ─────
    public string $neuerSlot = '';

    // ── Branding / CI (pro Angebot) ────────────────────────────────────────────
    public array $brandingForm = ['brand_color' => '#6d28d9', 'band_color' => '', 'footer_text' => ''];

    public $logoUpload = null;

    public $coverUpload = null;

    public ?int $brandingLoadedId = null;

    public ?string $brandingFehler = null;

    public bool $brandingGespeichert = false;

    // ── Kapitel-Bild + Galerie (Bild-Epic) ─────────────────────────────────────
    public $kapitelImageUpload = null;

    public ?string $kapitelImageFehler = null;

    /** @var array<int, \Illuminate\Http\UploadedFile> */
    public $kapitelGalleryUpload = [];

    // ── Präsentation (digitales Kundenbuch, TYPE_ANGEBOT) ──────────────────────
    public string $presentationDesign = 'editorial';

    public ?string $presentationGueltigBis = null;

    public bool $presentationPreisAnzeige = true;

    /** Republish-Preis-Schutz: AUS = eingefrorene Preise behalten; AN = aktuelle VK ziehen. */
    public bool $presentationPreiseAktualisieren = false;

    public bool $presentationDeklaration = true;

    public ?string $presentationCtaText = null;

    public ?string $presentationCtaLink = null;

    public ?string $presentationSlug = null;

    public ?int $presentationLoadedId = null;

    public ?string $presentationFehler = null;

    public ?string $presentationHinweis = null;

    // publish-per-Betrieb: zusätzlicher Link je Betrieb mit DESSEN Preisen + eigener Freigabe.
    public ?int $outletPublishId = null;

    public ?string $outletPublishGueltigBis = null;

    public ?string $outletPublishDesign = '';

    public ?string $outletPublishSlug = '';

    // ── KI-Kundentext (Foodbook §L2): Vorschau-Zustand (Buch- + Kapitel-Ebene) ──
    public ?string $kiTextVorschau = null;

    public ?float $kiTextConfidence = null;

    public ?string $kiTextHinweis = null;

    /** Welches Feld der Vorschlag füllt: `angebot` (Einleitung) oder `kapitel` (Hinführung). */
    public string $kiTextZiel = 'angebot';

    // ══════════════════════════════════════════════════════════════════════════
    //  Modal-Lifecycle (Angebot-Spezifikum, erhalten)
    // ══════════════════════════════════════════════════════════════════════════

    #[On('angebot-editor.bearbeiten')]
    public function oeffnen(int $id): void
    {
        $this->selectedId = $id;
        $this->selectedKapitelId = null;
        $this->editBlockId = null;
        $this->markiert = [];
        $this->firmaSuche = '';
        $this->kontaktSuche = '';
        $this->conceptSuche = '';
        $this->resetConceptFacetten();
        $this->ladeForm();
        $this->dispatch('modal.open', name: 'angebot-editor');
    }

    private function ladeForm(): void
    {
        if ($this->selectedId === null) {
            return;
        }
        // Fürs Formular genügt der Angebotskopf. Die schweren Editor-Relationen
        // lädt render() direkt danach genau einmal.
        $a = FoodAlchemistAngebot::visibleToTeam($this->team())->find($this->selectedId);
        if ($a === null) {
            $this->selectedId = null;

            return;
        }
        // Öffnen ist ein reiner Leseweg. Preisändernde Commands aktualisieren den
        // Auto-Preis bereits selbst; eine volle Kalkulation hier würde direkt vor
        // dem Render dieselbe schwere Arbeit nochmals ausführen und in die DB schreiben.
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
            'price_override_reason' => $a->price_override_reason ?? '',
        ];
    }

    public function speichern(AngebotService $svc): void
    {
        if ($this->selectedId === null) {
            return;
        }
        $svc->update($this->team(), $this->selectedId, $this->form);
        // Tab-übergreifend: der prominente „Speichern" sichert auch das Branding-Tab
        // (Marken-Farbe/Bandfarbe/Footer) — spiegelt Foodbook::speichern. Idempotent,
        // wenn Branding nicht angefasst wurde; Hex-Fehler landen in brandingFehler.
        $this->brandingSpeichern($svc);
        $this->ladeForm();
        $this->dispatch('angebot-gespeichert');
        $this->savedToast('Angebot gespeichert');
        // Der übernommene KI-Text ist jetzt echter Feld-Inhalt — die Vorschau-Fläche ruht.
        $this->kiTextHinweis = null;
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

    /** Stufe 3 — Angebot in die Produktion übergeben (concept × Pax → Produktionsauftrag). */
    public function anProduktion(AngebotService $svc): void
    {
        if ($this->selectedId === null) {
            return;
        }
        $res = $svc->anProduktion($this->team(), $this->selectedId, Auth::id());
        session()->flash('angebot_produktion', $res['order_id'] !== null
            ? "In Produktion übergeben ({$res['ziele']} Ziele) — jetzt im Tagesplan planbar."
            : 'Kein Menü/Concept im Angebot — nichts zu übergeben.');
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

    /** Zurück auf den Angebots-Kopf (Master-Detail: kein Kapitel gewählt). */
    public function kopfAnzeigen(): void
    {
        $this->selectedKapitelId = null;
        $this->editBlockId = null;
        $this->markiert = [];
        if ($this->kiTextZiel === 'kapitel') {
            $this->kiTextZuruecksetzen('angebot');   // die Kapitel-Fläche ist weg, ihr Vorschlag auch
        }
        $this->dispatch('angebot-goto', tab: 'anfrage');
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  CRM-Kunde-Link (Angebot-Spezifikum, erhalten)
    // ══════════════════════════════════════════════════════════════════════════

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

    // ══════════════════════════════════════════════════════════════════════════
    //  Angebots-lokale Menü-Entwürfe (Concepter-Editor, nested) — erhalten
    // ══════════════════════════════════════════════════════════════════════════

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

    // ══════════════════════════════════════════════════════════════════════════
    //  Kapitel-Baum (Foodbook-Fork, offer-scoped über OfferCompositionService)
    // ══════════════════════════════════════════════════════════════════════════

    /** Nach jeder Kompositions-Änderung: Auto-Preis neu + Form + Liste aktualisieren. */
    private function nachKomposition(): void
    {
        if ($this->selectedId !== null) {
            app(AngebotService::class)->recomputeAngebot($this->team(), $this->selectedId);
            $this->ladeForm();
            $this->dispatch('angebot-gespeichert');
        }
    }

    private function eigenesKapitel(int $id): ?FoodAlchemistOfferChapter
    {
        $team = $this->team();
        $k = FoodAlchemistOfferChapter::visibleToTeam($team)->find($id);

        return ($k !== null && $k->isOwnedBy($team)) ? $k : null;
    }

    private function eigenerBlock(int $id): ?FoodAlchemistOfferBlock
    {
        $team = $this->team();
        $b = FoodAlchemistOfferBlock::visibleToTeam($team)->find($id);

        return ($b !== null && $b->isOwnedBy($team)) ? $b : null;
    }

    public function kapitelNeu(?int $parentId = null): void
    {
        if ($this->selectedId === null) {
            return;
        }
        $svc = app(OfferCompositionService::class);   // via Container, nicht als Action-Param — DI kollidiert sonst mit $parentId
        $titel = $parentId !== null ? 'Neues Unterkapitel' : ($this->neuesKapitelTitel ?: 'Neues Kapitel');
        $k = $svc->addKapitel($this->team(), $this->selectedId, ['title' => $titel], $parentId);
        $this->neuesKapitelTitel = '';
        $this->selectedKapitelId = $k->id;
        $this->ladeKapitelForm();
        $this->nachKomposition();
    }

    public function kapitelWaehle(int $id): void
    {
        // Fremde/geerbte Kapitel gar nicht erst auswählen — sonst lädt ladeKapitelForm() ihre Daten.
        if ($this->eigenesKapitel($id) === null) {
            return;
        }
        $this->selectedKapitelId = $id;
        $this->ladeKapitelForm();
        $this->editBlockId = null;
        $this->markiert = [];
        $this->dispatch('angebot-goto', tab: 'speisen');
    }

    private function ladeKapitelForm(): void
    {
        // Kapitel-Wechsel = anderer Gegenstand: ein KI-Vorschlag fürs vorige Kapitel darf hier
        // nicht stehen bleiben (er würde beim „Übernehmen" im falschen Feld landen).
        if ($this->kiTextZiel === 'kapitel') {
            $this->kiTextZuruecksetzen('kapitel');
        }
        if ($this->selectedKapitelId === null) {
            return;
        }
        $k = $this->eigenesKapitel($this->selectedKapitelId);
        if ($k) {
            $this->kapitelForm = [
                'title' => $k->title, 'consumer_title' => $k->consumer_title ?? '',
                'description' => $k->description ?? '',
                'price_mode' => $k->price_mode, 'price_per_person' => $k->price_per_person,
                'personen' => $k->personen,
                'writing_style_id' => $k->writing_style_id,
                'is_struktur' => (bool) $k->is_struktur,
            ];
        }
    }

    public function kapitelSpeichern(OfferCompositionService $comp): void
    {
        if ($this->selectedKapitelId !== null) {
            $comp->updateKapitel($this->team(), $this->selectedKapitelId, $this->kapitelForm);
            if ($this->kiTextZiel === 'kapitel') {
                $this->kiTextHinweis = null;
            }
            $this->nachKomposition();
        }
    }

    /** Board: manuellen Kapitel-Fortschritt setzen (offen|in_arbeit|fertig). */
    public function kapitelFortschritt(int $id, string $wert, OfferCompositionService $comp): void
    {
        try {
            $comp->kapitelFortschritt($this->team(), $id, $wert);
        } catch (\Throwable $e) {
            $this->errorToast($e->getMessage());
        }
    }

    public function kapitelLoeschen(int $id, OfferCompositionService $comp): void
    {
        $comp->deleteKapitel($this->team(), $id);
        if ($this->selectedKapitelId === $id) {
            $this->selectedKapitelId = null;
        }
        $this->nachKomposition();
    }

    public function kapitelHoch(int $id, OfferCompositionService $comp): void
    {
        $this->verschiebeKapitel($id, -1, $comp);
    }

    public function kapitelRunter(int $id, OfferCompositionService $comp): void
    {
        $this->verschiebeKapitel($id, 1, $comp);
    }

    private function verschiebeKapitel(int $id, int $richtung, OfferCompositionService $comp): void
    {
        $k = FoodAlchemistOfferChapter::find($id);
        if ($k === null || $this->selectedId === null) {
            return;
        }
        $geschwister = $this->geschwisterIds($k->parent_id !== null ? (int) $k->parent_id : null);
        $pos = array_search($id, $geschwister, true);
        $ziel = $pos + $richtung;
        if ($pos === false || $ziel < 0 || $ziel >= count($geschwister)) {
            return;
        }
        [$geschwister[$pos], $geschwister[$ziel]] = [$geschwister[$ziel], $geschwister[$pos]];
        $comp->reorderKapitel($this->team(), $this->selectedId, $geschwister);
        $this->nachKomposition();
    }

    /**
     * Kapitel eine Ebene TIEFER — neuer Parent = unmittelbar vorheriges Geschwister
     * (Outline-Editor-Doktrin). Ohne vorheriges Geschwister nicht einrückbar.
     */
    public function kapitelEinruecken(int $id, OfferCompositionService $comp): void
    {
        $k = FoodAlchemistOfferChapter::find($id);
        if ($k === null || $this->selectedId === null) {
            return;
        }
        $geschwister = $this->geschwisterIds($k->parent_id !== null ? (int) $k->parent_id : null);
        $pos = array_search($id, $geschwister, true);
        if ($pos === false || $pos === 0) {
            return; // erstes Geschwister hat keinen Vorgänger zum Einrücken
        }
        $this->kapitelUnter($id, (int) $geschwister[$pos - 1], $comp);
    }

    /**
     * Kapitel eine Ebene HÖHER — neuer Parent = Großelternteil (oder Top-Ebene).
     * Top-Kapitel sind nicht weiter ausrückbar.
     */
    public function kapitelAusruecken(int $id, OfferCompositionService $comp): void
    {
        $k = FoodAlchemistOfferChapter::find($id);
        if ($k === null || $k->parent_id === null || $this->selectedId === null) {
            return;
        }
        $parent = FoodAlchemistOfferChapter::find($k->parent_id);
        $grossParent = $parent?->parent_id !== null ? (int) $parent->parent_id : null;
        $this->kapitelUnter($id, $grossParent, $comp);
    }

    /**
     * Gemeinsamer Move: Parent wechseln (Service trägt den Zyklus-Schutz) und das Kapitel
     * ans ENDE der neuen Geschwister-Ordnung setzen, damit die Positionen konsistent bleiben.
     */
    private function kapitelUnter(int $id, ?int $neuerParent, OfferCompositionService $comp): void
    {
        try {
            $comp->moveKapitel($this->team(), $id, $neuerParent);
        } catch (\RuntimeException) {
            return; // Zyklus o. Ä. — UI bietet solche Moves ohnehin nicht an
        }
        $geschwister = $this->geschwisterIds($neuerParent, $id);
        $geschwister[] = $id;
        $comp->reorderKapitel($this->team(), $this->selectedId, $geschwister);
        $this->nachKomposition();
    }

    /**
     * Drag & Drop: das gezogene Kapitel landet unmittelbar VOR dem Ziel-Kapitel und
     * übernimmt dessen Parent-Ebene (moveKapitel trägt den Zyklus-Schutz). Spiegelt die
     * Block-Drop-Semantik.
     */
    public function kapitelVerschiebenAuf(int $dragId, int $zielId, OfferCompositionService $comp): void
    {
        if ($dragId === $zielId || $this->selectedId === null) {
            return;
        }
        $ziel = FoodAlchemistOfferChapter::where('offer_id', $this->selectedId)->find($zielId);
        if ($ziel === null) {
            return;
        }
        $neuerParent = $ziel->parent_id !== null ? (int) $ziel->parent_id : null;
        try {
            $comp->moveKapitel($this->team(), $dragId, $neuerParent);   // auf die Ziel-Ebene (Zyklus-Schutz im Service)
        } catch (\RuntimeException) {
            return;
        }
        $geschwister = $this->geschwisterIds($neuerParent, $dragId);
        $zielPos = array_search($zielId, $geschwister, true);
        if ($zielPos === false) {
            $geschwister[] = $dragId;
        } else {
            array_splice($geschwister, $zielPos, 0, [$dragId]);   // direkt VOR das Ziel
        }
        $comp->reorderKapitel($this->team(), $this->selectedId, $geschwister);
        $this->nachKomposition();
    }

    /**
     * Geschwister-IDs (gleiche Parent-Ebene) des aktuellen Angebots, nach Position geordnet.
     * `$ausser` schließt eine ID aus (für Move-Neuordnung).
     *
     * @return list<int>
     */
    private function geschwisterIds(?int $parentId, ?int $ausser = null): array
    {
        return FoodAlchemistOfferChapter::where('offer_id', $this->selectedId)
            ->where('parent_id', $parentId)
            ->when($ausser !== null, fn ($q) => $q->where('id', '!=', $ausser))
            ->orderBy('position')->pluck('id')->map(fn ($x) => (int) $x)->all();
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  Katalog-Picker (concept | paket | format | gericht)
    // ══════════════════════════════════════════════════════════════════════════

    public function katalogModus(string $modus): void
    {
        if (in_array($modus, ['concept', 'paket', 'format', 'gericht'], true)) {
            $this->pickerModus = $modus;
        }
    }

    public function conceptHinzu(int $conceptId, OfferCompositionService $comp): void
    {
        if ($this->selectedKapitelId === null) {
            return;
        }
        try {
            $comp->addBlock($this->team(), $this->selectedKapitelId, ['type' => 'concept_ref', 'concept_id' => $conceptId]);
        } catch (\Throwable $e) {
            $this->errorToast($e->getMessage());

            return;
        }
        $this->conceptSuche = '';
        $this->nachKomposition();
    }

    /**
     * Ein Paket (kind=paket-Concept) als concept_ref-Block ans Kapitel — dieselbe Buchung
     * wie {@see conceptHinzu} (concept_id trägt Concept + Paket). Eigener Picker-Reiter.
     */
    public function paketHinzu(int $paketId, OfferCompositionService $comp): void
    {
        if ($this->selectedKapitelId === null) {
            return;
        }
        try {
            $comp->addBlock($this->team(), $this->selectedKapitelId, ['type' => 'concept_ref', 'concept_id' => $paketId]);
        } catch (\Throwable $e) {
            $this->errorToast($e->getMessage());

            return;
        }
        $this->paketSuche = '';
        $this->nachKomposition();
    }

    /**
     * Ein Format ins Angebot buchen — als eigenes (lebendes) Format-Kapitel. Braucht nur das
     * gewählte Angebot, kein Ziel-Kapitel. Fail-soft: Kunden-IP-/Status-Guard meldet sich
     * als Fehler, kippt den Editor nicht.
     */
    public function formatEinfuegen(int $formatId, OfferCompositionService $comp): void
    {
        if ($this->selectedId === null) {
            return;
        }
        try {
            $comp->insertFormatKapitel($this->team(), $this->selectedId, $formatId);
        } catch (\Throwable $e) {
            $this->addError('formatKapitel', $e->getMessage());

            return;
        }
        $this->formatSuche = '';
        $this->nachKomposition();
    }

    /** Nur für Format-Kapitel: additiv|alternativen umschalten. */
    public function formatPreisModus(int $chapterId, string $mode, OfferCompositionService $comp): void
    {
        try {
            $comp->setFormatPriceMode($this->team(), $chapterId, $mode);
            $this->nachKomposition();
        } catch (\Throwable $e) {
            $this->errorToast($e->getMessage());
        }
    }

    /**
     * Einzel-Gericht (VK-Rezept) als `recipe_ref`-Block direkt ans Kapitel (€/Position).
     * Spiegelt {@see conceptHinzu}; die Schreibpfad-Validierung übernimmt addBlock/pruefeRecipeRef.
     */
    public function gerichtHinzu(int $recipeId, OfferCompositionService $comp): void
    {
        if ($this->selectedKapitelId === null) {
            return;
        }
        try {
            $comp->addBlock($this->team(), $this->selectedKapitelId, ['type' => 'recipe_ref', 'sales_recipe_id' => $recipeId]);
        } catch (\Throwable $e) {
            $this->errorToast($e->getMessage());

            return;
        }
        $this->gerichtSuche = '';
        $this->nachKomposition();
    }

    public function waehleGerichtHg(?int $hgId): void
    {
        $this->gerichtHauptgruppe = ($this->gerichtHauptgruppe === $hgId) ? null : $hgId;
        $this->gerichtDishClass = null;
    }

    public function waehleGerichtKlasse(int $dishClassId): void
    {
        $this->gerichtDishClass = ($this->gerichtDishClass === $dishClassId) ? null : $dishClassId;
    }

    public function updatedGerichtHauptgruppe(): void
    {
        $this->gerichtDishClass = null;
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

    public function updatedConceptFacetten($value, $key): void
    {
        $this->conceptFacetten[$key] = ($value === '' || $value === null) ? null : (int) $value;
    }

    public function updatedPaketFacetten($value, $key): void
    {
        $this->paketFacetten[$key] = ($value === '' || $value === null) ? null : (int) $value;
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  Blöcke
    // ══════════════════════════════════════════════════════════════════════════

    public function presetHinzu(string $type, ?string $slug, ?string $label, ?string $preisBasis, bool $sichtbar, OfferCompositionService $comp): void
    {
        if ($this->selectedKapitelId === null) {
            return;
        }
        $comp->addBlock($this->team(), $this->selectedKapitelId, [
            'type' => $type, 'header_source' => $slug, 'label' => $label,
            'price_basis' => $type === 'header_frei_preis' ? ($preisBasis ?: 'person') : null,
            'price_value' => $type === 'header_frei_preis' ? 0 : null,
            'visible' => $sichtbar,
        ]);
        $this->nachKomposition();
    }

    public function blockBasis(string $type, OfferCompositionService $comp): void
    {
        if ($this->selectedKapitelId === null) {
            return;
        }
        $comp->addBlock($this->team(), $this->selectedKapitelId, [
            'type' => $type,
            'height' => $type === 'spacer' ? 'mittel' : null,
            'price_basis' => $type === 'header_frei_preis' ? 'person' : null,
            'price_value' => $type === 'header_frei_preis' ? 0 : null,
        ]);
        $this->nachKomposition();
    }

    public function blockBearbeiten(int $id): void
    {
        $block = $this->eigenerBlock($id);
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
     * ✨ Kundentext-Vorschlag für den gerade editierten concept_ref-Block — der Marketing-Text
     * lebt kundenspezifisch am Block (nicht am Gericht). Fail-soft: Feld bleibt bei Provider-
     * Ausfall/Kill-Switch unverändert, kein Crash im Editor.
     */
    public function kiKundentext(): void
    {
        if ($this->editBlockId === null) {
            return;
        }
        $block = FoodAlchemistOfferBlock::visibleToTeam($this->team())
            ->with('concept.slots.dish:id,name,sales_wording_standard')->find($this->editBlockId);
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
                // Ebene 2 der DNA-Kette: Endkunde des Angebots (Kunde-DNA fließt in den Marketing-Text)
                'food_dna_crm_company_id' => FoodAlchemistAngebot::whereKey($this->selectedId)->value('crm_company_id'),
                'target_table' => 'foodalchemist_offer_blocks', 'target_id' => $block->id,
            ]);
            $text = $v->werte['marketing_text'] ?? null;
            if (is_string($text) && trim($text) !== '') {
                $this->blockForm['customer_text'] = trim($text);
            }
        } catch (\Throwable $e) {
            // still — Feld bleibt unverändert (Kill-Switch/Provider-Fehler); kein Crash im Editor
        }
    }

    public function blockSpeichern(OfferCompositionService $comp): void
    {
        if ($this->editBlockId !== null) {
            $comp->updateBlock($this->team(), $this->editBlockId, $this->blockForm);
        }
        $this->editBlockId = null;
        $this->nachKomposition();
    }

    public function blockRaus(int $id, OfferCompositionService $comp): void
    {
        $comp->deleteBlock($this->team(), $id);
        $this->nachKomposition();
    }

    /**
     * Eine einzelne Gericht-Zeile der Block-Vorschau inline bearbeiten (manuelles Wording,
     * wenn das KI-Ergebnis nicht passt). Öffnet den Inline-Editor mit dem aktuellen Text.
     */
    public function slotWordingBearbeiten(int $blockId, int $slotId, ?string $aktuell = null): void
    {
        $this->editSlotKey = $blockId . ':' . $slotId;
        $this->editSlotWording = (string) $aktuell;
    }

    /**
     * Den inline bearbeiteten Anzeigenamen speichern — als angebots-lokaler Block-Override
     * (payload_json['wording_overrides'][slotId], oberste Stufe der Wording-Kette). Leer =
     * zurück auf die Kette. Das Concept bleibt unangetastet.
     */
    public function slotWordingSpeichern(OfferCompositionService $comp): void
    {
        if ($this->editSlotKey === null || ! str_contains($this->editSlotKey, ':')) {
            return;
        }
        [$blockId, $slotId] = array_map('intval', explode(':', $this->editSlotKey, 2));
        try {
            $comp->setBlockSlotWording($this->team(), $blockId, $slotId, $this->editSlotWording);
        } catch (\Throwable $e) {
            $this->addError('slotWording', $e->getMessage());

            return;
        }
        $this->editSlotKey = null;
        $this->editSlotWording = '';
        $this->nachKomposition();
    }

    public function slotWordingAbbrechen(): void
    {
        $this->editSlotKey = null;
        $this->editSlotWording = '';
    }

    public function blockSichtbar(int $id, OfferCompositionService $comp): void
    {
        $block = FoodAlchemistOfferBlock::find($id);
        if ($block !== null) {
            $comp->blockSichtbar($this->team(), $id, ! $block->visible);
            $this->nachKomposition();
        }
    }

    public function blockEbene(int $id, int $delta, OfferCompositionService $comp): void
    {
        $comp->blockEbene($this->team(), $id, $delta);
        $this->nachKomposition();
    }

    public function blockHoch(int $id, OfferCompositionService $comp): void
    {
        $this->verschiebeBlock($id, -1, $comp);
    }

    public function blockRunter(int $id, OfferCompositionService $comp): void
    {
        $this->verschiebeBlock($id, 1, $comp);
    }

    private function verschiebeBlock(int $id, int $richtung, OfferCompositionService $comp): void
    {
        if ($this->selectedKapitelId === null) {
            return;
        }
        $ids = FoodAlchemistOfferBlock::where('chapter_id', $this->selectedKapitelId)
            ->orderBy('position')->pluck('id')->map(fn ($x) => (int) $x)->all();
        $pos = array_search($id, $ids, true);
        $ziel = $pos + $richtung;
        if ($pos === false || $ziel < 0 || $ziel >= count($ids)) {
            return;
        }
        [$ids[$pos], $ids[$ziel]] = [$ids[$ziel], $ids[$pos]];
        $comp->reorderBlocks($this->team(), $this->selectedKapitelId, $ids);
        $this->nachKomposition();
    }

    /**
     * Drag & Drop: Block `$id` HINTER Block `$afterId` einsortieren (Insert-after). Der
     * Ziehgriff sitzt in der Block-Zeile; ▲▼ bleibt als zuverlässige Kanten-Alternative.
     */
    public function blockVerschiebenAuf(int $id, int $afterId, OfferCompositionService $comp): void
    {
        if ($this->selectedKapitelId === null || $id === $afterId) {
            return;
        }
        $ids = FoodAlchemistOfferBlock::where('chapter_id', $this->selectedKapitelId)
            ->orderBy('position')->pluck('id')->map(fn ($x) => (int) $x)->all();
        $ids = array_values(array_filter($ids, fn ($x) => $x !== $id));
        $pos = array_search($afterId, $ids, true);
        if ($pos === false) {
            return; // Ziel gehört nicht zum Kapitel — kein blinder Append
        }
        array_splice($ids, $pos + 1, 0, [$id]);
        $comp->reorderBlocks($this->team(), $this->selectedKapitelId, $ids);
        $this->nachKomposition();
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  Wahl-Gruppe (A|B|C zwischen Concepts)
    // ══════════════════════════════════════════════════════════════════════════

    public function markiere(int $id): void
    {
        $this->markiert = in_array($id, $this->markiert, true)
            ? array_values(array_diff($this->markiert, [$id]))
            : [...$this->markiert, $id];
    }

    public function wahlGruppeBilden(OfferCompositionService $comp): void
    {
        if (count($this->markiert) < 2 || $this->selectedKapitelId === null) {
            return;
        }
        $gid = $comp->nextVariantGroupId($this->team(), $this->selectedKapitelId);
        $comp->setVariantGroup($this->team(), $this->markiert, $gid);
        $this->markiert = [];
        $this->nachKomposition();
    }

    public function wahlGruppeAufheben(int $id, OfferCompositionService $comp): void
    {
        $comp->setVariantGroup($this->team(), [$id], null);
        $this->nachKomposition();
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  KI-Kundentext (Einleitung + Kapitel-Hinführung) — Vorschau-Zustand
    // ══════════════════════════════════════════════════════════════════════════

    /** Kundentext-Vorschlag für die Angebots-Einleitung holen (schreibt NUR in die Vorschau). */
    public function kiEinleitung(AngebotService $svc): void
    {
        $this->kiTextZuruecksetzen('angebot');
        if ($this->selectedId === null) {
            return;
        }
        $this->kiTextHolen(fn () => $svc->kiKundentextVorschlag($this->team(), $this->selectedId));
    }

    /** Hinführung fürs gewählte Kapitel holen. Gleiche zwei Stufen wie auf der Buch-Ebene. */
    public function kiKapitelText(AngebotService $svc): void
    {
        $this->kiTextZuruecksetzen('kapitel');
        if ($this->selectedKapitelId === null) {
            return;
        }
        $this->kiTextHolen(fn () => $svc->kiKapitelKundentextVorschlag($this->team(), $this->selectedKapitelId));
    }

    private function kiTextZuruecksetzen(string $ziel): void
    {
        $this->kiTextZiel = $ziel;
        $this->kiTextVorschau = null;
        $this->kiTextConfidence = null;
        $this->kiTextHinweis = null;
    }

    /**
     * Gemeinsamer Call-Rahmen beider Ebenen: die typisierten KI-Ausfälle werden zu genau
     * einer Hinweis-Zeile.
     *
     * @param  \Closure():array{text:string,confidence:?float,call_log_id:?int}  $call
     */
    private function kiTextHolen(\Closure $call): void
    {
        try {
            $r = $call();
            $this->kiTextVorschau = $r['text'];
            $this->kiTextConfidence = $r['confidence'];
        } catch (\Platform\FoodAlchemist\Exceptions\KiDeaktiviertException $e) {
            $this->kiTextHinweis = 'KI ist für dieses Team deaktiviert (Einstellungen → Food DNA / KI).';
        } catch (\Platform\FoodAlchemist\Exceptions\KiNichtVerfuegbarException $e) {
            $this->kiTextHinweis = 'Kein KI-Provider gebunden — der Kundentext braucht ein aktives Modell (demo).';
        } catch (\RuntimeException $e) {
            $this->kiTextHinweis = $e->getMessage();
        }
    }

    /** Vorschlag ins Formular übernehmen — bewusst OHNE zu speichern. Ziel entscheidet kiTextZiel. */
    public function kiTextUebernehmen(): void
    {
        if ($this->kiTextVorschau === null) {
            return;
        }
        if ($this->kiTextZiel === 'kapitel') {
            if ($this->selectedKapitelId === null) {
                return;                                              // Kapitel gewechselt, während die KI lief
            }
            $this->kapitelForm['description'] = $this->kiTextVorschau;
            $hinweis = 'Text steht im Kapitel-Feld — noch nicht gespeichert (Feld verlassen speichert).';
        } else {
            $this->form['brief'] = $this->kiTextVorschau;
            $hinweis = 'Text steht im Feld — noch nicht gespeichert („Speichern" oben).';
        }
        $this->kiTextVorschau = null;
        $this->kiTextConfidence = null;
        $this->kiTextHinweis = $hinweis;
    }

    public function kiTextVerwerfen(): void
    {
        $this->kiTextVorschau = null;
        $this->kiTextConfidence = null;
        $this->kiTextHinweis = null;
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  Branding / CI (pro Angebot)
    // ══════════════════════════════════════════════════════════════════════════

    public function brandingSpeichern(AngebotService $svc): void
    {
        $this->brandingFehler = null;
        $this->brandingGespeichert = false;
        if ($this->selectedId === null) {
            return;
        }
        try {
            $a = $svc->setBranding($this->team(), $this->selectedId, [
                'brand_color' => $this->brandingForm['brand_color'] ?? '#6d28d9',
                'band_color' => $this->brandingForm['band_color'] ?? '',
                'footer_text' => $this->brandingForm['footer_text'] ?? '',
            ]);
            $this->brandingForm = [
                'brand_color' => $a->brand_color ?? '#6d28d9',
                'band_color' => $a->band_color ?? '',
                'footer_text' => $a->footer_text ?? '',
            ];
            $this->brandingGespeichert = true;
        } catch (\RuntimeException $e) {
            // Hex-Murks oder geerbtes Angebot (Owner-Guard) → sauber als UI-Fehler.
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
            app(AngebotService::class)->{$serviceMethod}($this->team(), $this->selectedId, $this->{$prop});
        } catch (\RuntimeException $e) {
            $this->brandingFehler = $e->getMessage();
        }
        $this->reset($prop);
    }

    public function brandingLogoEntfernen(AngebotService $svc): void
    {
        if ($this->selectedId !== null) {
            $svc->clearLogo($this->team(), $this->selectedId);
        }
    }

    public function brandingCoverEntfernen(AngebotService $svc): void
    {
        if ($this->selectedId !== null) {
            $svc->clearCover($this->team(), $this->selectedId);
        }
    }

    // ── Kapitel-Bild (überschreibt das Concept-Titelbild im Kapitel-Band) ──────
    public function updatedKapitelImageUpload(): void
    {
        $this->kapitelImageFehler = null;
        if ($this->selectedKapitelId === null || $this->kapitelImageUpload === null) {
            return;
        }
        $this->validate(['kapitelImageUpload' => 'image|max:8192'], [], ['kapitelImageUpload' => 'Kapitel-Bild']);
        try {
            app(OfferCompositionService::class)->setKapitelImage($this->team(), $this->selectedKapitelId, $this->kapitelImageUpload);
        } catch (\RuntimeException $e) {
            $this->kapitelImageFehler = $e->getMessage();
        }
        $this->reset('kapitelImageUpload');
    }

    public function kapitelImageEntfernen(OfferCompositionService $comp): void
    {
        if ($this->selectedKapitelId !== null) {
            $comp->clearKapitelImage($this->team(), $this->selectedKapitelId);
        }
    }

    // ── Kapitel-Galerie (Mehrfach-Upload) ──────────────────────────────────────
    public function updatedKapitelGalleryUpload(): void
    {
        $this->kapitelImageFehler = null;
        $dateien = array_filter(is_array($this->kapitelGalleryUpload) ? $this->kapitelGalleryUpload : [$this->kapitelGalleryUpload]);
        if ($this->selectedKapitelId === null || $dateien === []) {
            return;
        }
        $this->validate(['kapitelGalleryUpload.*' => 'image|max:8192'], [], ['kapitelGalleryUpload.*' => 'Bild']);
        try {
            foreach ($dateien as $datei) {
                app(OfferCompositionService::class)->addKapitelGalleryImage($this->team(), $this->selectedKapitelId, $datei);
            }
        } catch (\RuntimeException $e) {
            $this->kapitelImageFehler = $e->getMessage();
        }
        $this->reset('kapitelGalleryUpload');
    }

    public function kapitelGalerieBildEntfernen(int $imageId, OfferCompositionService $comp): void
    {
        $comp->removeKapitelGalleryImage($this->team(), $imageId);
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  Präsentation (digitales Kundenbuch, TYPE_ANGEBOT)
    // ══════════════════════════════════════════════════════════════════════════

    public function veroeffentlichen(): void
    {
        $this->presentationFehler = null;
        $this->presentationHinweis = null;
        if ($this->selectedId === null) {
            return;
        }
        try {
            app(PresentationService::class)->publish($this->team(), PresentationService::TYPE_ANGEBOT, $this->selectedId, [
                'design' => $this->presentationDesign,
                'expires_at' => $this->presentationGueltigBis,
                'price_display' => $this->presentationPreisAnzeige,
                'price_mode' => $this->presentationPreiseAktualisieren ? 'auto' : 'preserve',
                'declaration' => $this->presentationDeklaration,
                'cta' => ['text' => $this->presentationCtaText, 'link' => $this->presentationCtaLink],
                'slug' => $this->presentationSlug,
            ]);
            $this->presentationLoadedId = null; // erzwingt Neuladen des Status im render()
            $this->presentationHinweis = 'Veröffentlicht — der Kundenlink ist aktiv.';
        } catch (\Throwable $e) {
            $this->presentationFehler = $e->getMessage();
        }
    }

    public function zuruckziehen(): void
    {
        $this->presentationFehler = null;
        $this->presentationHinweis = null;
        if ($this->selectedId === null) {
            return;
        }
        try {
            app(PresentationService::class)->withdraw($this->team(), PresentationService::TYPE_ANGEBOT, $this->selectedId);
            $this->presentationLoadedId = null;
            $this->presentationHinweis = 'Veröffentlichung zurückgezogen — der Link ist jetzt inaktiv (404).';
        } catch (\Throwable $e) {
            $this->presentationFehler = $e->getMessage();
        }
    }

    /** Einen zweiten Link FÜR einen Betrieb anlegen — dessen Preise + Vorlage, eigene Freigabe. */
    public function betriebVeroeffentlichen(): void
    {
        $this->presentationFehler = null;
        $this->presentationHinweis = null;
        if ($this->selectedId === null) {
            return;
        }
        if ($this->outletPublishId === null) {
            $this->presentationFehler = 'Bitte zuerst einen Betrieb wählen.';

            return;
        }
        try {
            $settings = [
                'expires_at' => $this->outletPublishGueltigBis ?: $this->presentationGueltigBis,
                'price_display' => $this->presentationPreisAnzeige,
                'price_mode' => $this->presentationPreiseAktualisieren ? 'auto' : 'preserve',
                'declaration' => $this->presentationDeklaration,
                'cta' => ['text' => $this->presentationCtaText, 'link' => $this->presentationCtaLink],
            ];
            if (trim((string) $this->outletPublishDesign) !== '') {
                $settings['design'] = $this->outletPublishDesign;
            }
            if (trim((string) $this->outletPublishSlug) !== '') {
                $settings['slug'] = $this->outletPublishSlug;
            }
            app(PresentationService::class)->publishForOutlet($this->team(), PresentationService::TYPE_ANGEBOT, $this->selectedId, $this->outletPublishId, $settings);
            $this->outletPublishId = null;
            $this->outletPublishGueltigBis = null;
            $this->outletPublishDesign = '';
            $this->outletPublishSlug = '';
            $this->presentationHinweis = 'Betriebs-Link veröffentlicht — eigener Link mit den Preisen, der Vorlage und dem Namen dieses Betriebs.';
        } catch (\Throwable $e) {
            $this->presentationFehler = $e->getMessage();
        }
    }

    /** Einen Betriebs-Link vom Netz nehmen (Standard-Link bleibt unberührt). */
    public function betriebZuruckziehen(int $outletId): void
    {
        $this->presentationFehler = null;
        $this->presentationHinweis = null;
        if ($this->selectedId === null) {
            return;
        }
        try {
            app(PresentationService::class)->withdrawForOutlet($this->team(), PresentationService::TYPE_ANGEBOT, $this->selectedId, $outletId);
            $this->presentationHinweis = 'Betriebs-Link zurückgezogen.';
        } catch (\Throwable $e) {
            $this->presentationFehler = $e->getMessage();
        }
    }

    /** Einen zurückgezogenen Betriebs-Link wieder live nehmen (Snapshot/Preise bleiben eingefroren). */
    public function betriebWiederFreigeben(int $outletId): void
    {
        $this->presentationFehler = null;
        $this->presentationHinweis = null;
        if ($this->selectedId === null) {
            return;
        }
        try {
            app(PresentationService::class)->republishForOutlet($this->team(), PresentationService::TYPE_ANGEBOT, $this->selectedId, $outletId, $this->presentationGueltigBis);
            $this->presentationHinweis = 'Betriebs-Link wieder freigegeben — gleiche URL, eingefrorene Preise bleiben.';
        } catch (\Throwable $e) {
            $this->presentationFehler = $e->getMessage();
        }
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  Voll-Kaskade + Gerüst-Review (Angebot-Spezifika, erhalten)
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Voll-Kaskade fürs Angebot — je Frame-Slot ein Konzept, ans Angebot referenziert. Slots kann
     * der Mensch VOR der Kaskade im Gerüst-Tab prüfen/bauen; ohne Gerüst wird EINMAL aus dem
     * Angebots-Kopf (Anlass/Gäste) auto-strukturiert. Springt in die Leitstelle.
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
        $angebot = FoodAlchemistAngebot::visibleToTeam($team)->find($this->selectedId);
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
    private function angebotBrief(FoodAlchemistAngebot $a): string
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

    /** KI-Kickoff: Slots aus dem Angebots-Brief vorschlagen — graceful ohne Provider. */
    public function geruestKickoff(\Platform\FoodAlchemist\Services\ConceptGeneratorService $gen): void
    {
        $team = $this->team();
        if ($this->selectedId === null) {
            return;
        }
        $angebot = FoodAlchemistAngebot::visibleToTeam($team)->find($this->selectedId);
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

    // ══════════════════════════════════════════════════════════════════════════
    //  Nested-Livewire-Signale (erhalten)
    // ══════════════════════════════════════════════════════════════════════════

    /** Concepter-Editor hat einen angebots-lokalen Entwurf geändert → Auto-Preis + Detail neu. */
    #[On('concepter-gespeichert')]
    public function nachConcepterEdit(AngebotService $svc): void
    {
        if ($this->selectedId !== null) {
            $svc->recomputeAngebot($this->team(), $this->selectedId);
            $this->ladeForm();
        }
    }

    /** Ebene 2: Betrieb-Wechsel im Sidebar → Angebots-Kalkulation gegen den neuen Betrieb neu rendern. */
    #[On('aktiver-betrieb-geaendert')]
    public function betriebGewechselt(): void
    {
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  Render
    // ══════════════════════════════════════════════════════════════════════════

    /** Kapitel-Wording im gewählten Stil als angebots-lokalen Block-Snapshot erzeugen. */
    public function kapitelWordingGenerieren(OfferCompositionService $svc): void
    {
        if ($this->selectedKapitelId === null) {
            return;
        }
        $this->resetErrorBag('kapitelWording');
        $svc->updateKapitel($this->team(), $this->selectedKapitelId, $this->kapitelForm);
        $stilId = $this->kapitelForm['writing_style_id'] ?? null;
        if ($stilId === null || $stilId === '') {
            $this->addError('kapitelWording', 'Kein Kapitel-Schreibstil gewählt — Standard erbt live aus den Concepten.');

            return;
        }
        try {
            $anzahl = $svc->kapitelWordingRegenerieren($this->team(), $this->selectedKapitelId);
        } catch (\Throwable $e) {
            $this->addError('kapitelWording', $e->getMessage());

            return;
        }
        $this->dispatch('angebot-gespeichert');
        $this->savedToast($anzahl > 0
            ? "{$anzahl} Konzept(e) im Kapitel-Stil neu betextet."
            : 'Keine betextbaren Konzept-Blöcke im Kapitel.');
    }

    public function render(AngebotService $svc)
    {
        $team = $this->team();
        $angebot = $this->selectedId !== null ? $svc->detail($team, $this->selectedId) : null;
        if ($this->selectedId !== null && $angebot === null) {
            $this->selectedId = null;
        }

        $comp = app(OfferCompositionService::class);

        // Canvas nur bei Selektions-WECHSEL (re)laden — kein Edit-Verlust je Roundtrip.
        if ($angebot !== null && $this->canvasOwnerId !== $angebot->id) {
            $this->canvasInit('angebot', 'angebot', $angebot->id);
        }

        // Branding-Felder nur bei Selektions-WECHSEL laden.
        if ($angebot !== null && $this->brandingLoadedId !== $angebot->id) {
            $this->brandingForm = [
                'brand_color' => $angebot->brand_color ?? '#6d28d9',
                'band_color' => $angebot->band_color ?? '',
                'footer_text' => $angebot->footer_text ?? '',
            ];
            $this->brandingLoadedId = $angebot->id;
        }

        // Präsentations-Publish-Felder nur bei Selektions-WECHSEL laden.
        if ($angebot !== null && $this->presentationLoadedId !== $angebot->id) {
            $s = $angebot->presentationSettings();
            $this->presentationDesign = $angebot->presentation_design ?: 'editorial';
            $this->presentationGueltigBis = $angebot->presentation_expires_at?->format('Y-m-d');
            $this->presentationPreisAnzeige = (bool) ($s['price_display'] ?? true);
            $this->presentationDeklaration = (bool) ($s['declaration'] ?? true);
            $this->presentationCtaText = $s['cta']['text'] ?? null;
            $this->presentationCtaLink = $s['cta']['link'] ?? null;
            $this->presentationSlug = $angebot->presentation_slug;
            $this->presentationLoadedId = $angebot->id;
        }

        // Gewähltes Kapitel (mit Bild + Galerie) für die Speisen-/Aufbau-Ansicht.
        $kapitel = ($angebot !== null && $this->selectedKapitelId !== null)
            ? $angebot->chapters->firstWhere('id', $this->selectedKapitelId) : null;

        // Live-Menü-Vorschau je concept_ref-Block (aufgelöste gerichtZeilen). Key = Block-ID.
        $blockMenus = [];
        if ($kapitel !== null) {
            $conceptBloecke = $kapitel->blocks->where('type', 'concept_ref')->filter(fn ($b) => $b->concept_id !== null);
            if ($conceptBloecke->isNotEmpty()) {
                $wording = app(\Platform\FoodAlchemist\Services\WordingResolver::class);
                $geladen = FoodAlchemistOfferBlock::whereIn('id', $conceptBloecke->pluck('id'))
                    ->with([
                        'concept.slots' => fn ($q) => $q->orderBy('position'),
                        'concept.slots.dish:id,name,sales_wording_standard',
                        'concept.slots.package.dishes.dish:id,name,sales_wording_standard',
                        'concept.slots.embeddedConcept:id,name,consumer_name,price_per_person_cache',
                        'concept.slots.embeddedConcept.slots.dish:id,name,sales_wording_standard',
                        'concept.slots.embeddedConcept.slots.package.dishes.dish:id,name,sales_wording_standard',
                    ])->get();
                foreach ($geladen as $b) {
                    $blockMenus[$b->id] = $b->concept !== null ? $wording->gerichtZeilen($b->concept, $b) : [];
                }
            }
        }

        // Ebene 2: Angebot wird gegen den aktiven Betrieb gerechnet (Kosten + Ziel-WE-Ampel).
        $outlet = app(\Platform\FoodAlchemist\Services\ActiveOutletContext::class)->current($team);
        // Den schweren Kompositionsbaum und seine Preiseinheiten pro Render nur einmal bauen.
        $komposition = $angebot ? $comp->komposition($team, $angebot, $outlet, true) : null;
        $einheiten = $angebot ? $comp->preisEinheiten($team, $angebot, $outlet) : null;
        $kalkulation = $angebot ? $svc->kalkulation($team, $angebot, $outlet, $komposition, $einheiten) : null;
        $zielWareneinsatzPct = app(\Platform\FoodAlchemist\Services\TeamSettingsService::class)
            ->zielWareneinsatzPct($team, $outlet);
        $wareneinsatzAmpel = app(\Platform\FoodAlchemist\Services\MargeService::class)
            ->weAmpel($kalkulation['wareneinsatz_pct'] ?? null, $zielWareneinsatzPct);

        // Extern-sichere Kundensicht aus demselben Baum ableiten (keine zweite DB-/Preisrunde).
        $menue = $komposition !== null ? $comp->kundensicht($komposition) : null;

        // Board: EIN Baum mit Status + Preis je Kapitel (gegen die Betriebsbrille gerechnet).
        $kapitelBoard = $angebot ? $this->kapitelBoardDaten($team, $angebot, $komposition, $zielWareneinsatzPct) : [];

        // Gerüst-Slots (read-only) für den Review-Tab — kein Create beim Rendern.
        $offerFrame = $this->selectedId !== null
            ? app(\Platform\FoodAlchemist\Services\PlanningFrameService::class)->find('offer', (int) $this->selectedId)
            : null;
        $geruestSlots = $offerFrame !== null
            ? $offerFrame->slots()->orderBy('position')->orderBy('id')->get(['id', 'label', 'target_count', 'price_anchor'])
            : collect();

        // Präsentations-Status + Kunden-Link + Design-Auswahl fürs Branding-&-Präsentation-Tab.
        $presentationInfo = null;
        $presentationLink = null;
        $betriebsLinks = [];
        $betriebsOptionen = [];
        if ($angebot !== null) {
            $presentationInfo = [
                'enabled' => (bool) $angebot->presentation_enabled,
                'live' => $angebot->isPresentationLive(),
                'published_at' => $angebot->presentation_published_at?->format('d.m.Y H:i'),
                'expires_at' => $angebot->presentation_expires_at?->format('d.m.Y'),
            ];
            if ($angebot->presentation_enabled && $angebot->presentationPublicRef()) {
                $presentationLink = url('/p/angebot/' . $angebot->presentationPublicRef());
            }
            $betriebsLinks = app(PresentationService::class)->outletPresentations($team, PresentationService::TYPE_ANGEBOT, $angebot->id);
            $betriebsOptionen = \Platform\FoodAlchemist\Models\FoodAlchemistOutlet::where('team_id', $team->id)
                ->where('is_inactive', false)->orderBy('sort_order')->orderBy('name')->get(['id', 'name'])
                ->map(fn ($o) => ['id' => (int) $o->id, 'name' => (string) $o->name])->all();
        }
        $presentationDesignOptionen = app(PresentationDesignService::class)->pickerOptions($team, PresentationService::TYPE_ANGEBOT);

        $facetten = $svc->facetten($team);

        // Kapitel-Bild + Galerie-URLs (Media-Service).
        $media = app(\Platform\FoodAlchemist\Services\FoodAlchemistMediaService::class);

        return view('foodalchemist::livewire.angebote.editor', [
            'angebot' => $angebot,
            // Komposition (intern) + Kundensicht + Kalkulation
            'komposition' => $komposition,
            'menue' => $menue,
            'kalkulation' => $kalkulation,
            'wareneinsatzAmpel' => $wareneinsatzAmpel,
            'zielWareneinsatzPct' => $zielWareneinsatzPct,
            'statusWerte' => $svc->statusWerte(),
            // Kapitel-Baum + gewähltes Kapitel + Board + Block-Menüs
            'kapitelTree' => array_map(fn (array $k) => [
                'id' => $k['id'], 'title' => $k['title_intern'], 'parent_id' => $k['parent_id'], 'depth' => $k['depth'],
            ], $komposition['kapitel'] ?? []),
            'kapitel' => $kapitel,
            'kapitelBoard' => $kapitelBoard,
            'blockMenus' => $blockMenus,
            'headerPresets' => FoodbookService::headerPresets(),
            // Kapitel-Bild + Galerie
            'kapitelImageUrl' => ($kapitel !== null && ($kapitel->image_context_file_id || $kapitel->image_path))
                ? $media->url($kapitel->image_context_file_id, $kapitel->image_path)
                : null,
            'kapitelGallery' => $kapitel !== null
                ? $kapitel->images->map(fn ($gi) => ['id' => $gi->id, 'url' => $media->url($gi->context_file_id, $gi->path)])->all()
                : [],
            // Katalog-Picker (concept | paket | format | gericht) — sofort browsebare Liste, Filter optional
            'conceptKandidaten' => $this->selectedKapitelId !== null
                ? $svc->katalogConcepts($team, $this->conceptSuche, 50, $this->conceptFacetten) : collect(),
            'paketKandidaten' => $this->selectedKapitelId !== null
                ? $svc->paketKandidaten($team, $this->paketSuche, 50, $this->paketFacetten) : collect(),
            'gerichtKandidaten' => $this->selectedKapitelId !== null
                ? $svc->gerichtKandidaten($team, $this->gerichtSuche, 50, $this->gerichtHauptgruppe, $this->gerichtDishClass) : collect(),
            // Format wird zum eigenen Kapitel → braucht nur das Angebot, nicht die Kapitel-Auswahl
            'formatKandidaten' => $this->selectedId !== null ? $svc->formatKandidaten($team, $this->formatSuche, 50) : collect(),
            // Concept-/Paket-Picker-Facetten (Concepter-Dimensionen)
            'facetteEventtypen' => $facetten['eventtypen'],
            'facetteServierformen' => $facetten['servierformen'],
            'facetteMomente' => $facetten['momente'],
            'facetteSaisons' => $facetten['saisons'],
            // Gericht-Picker: Klassen-/Untergruppen-Spalte (Modell A)
            'gerichtHauptgruppen' => $this->selectedKapitelId !== null
                ? app(\Platform\FoodAlchemist\Services\SalesRecipeService::class)->dishMainGroups($team) : collect(),
            'gerichtUntergruppen' => ($this->selectedKapitelId !== null && $this->gerichtHauptgruppe !== null)
                ? \Platform\FoodAlchemist\Models\FoodAlchemistDishClass::visibleToTeam($team)
                    ->where('dish_main_group_id', $this->gerichtHauptgruppe)->orderBy('id')->get(['id', 'label', 'diet_form'])
                : collect(),
            // Branding & Präsentation
            'presentationInfo' => $presentationInfo,
            'presentationLink' => $presentationLink,
            'presentationDesignOptionen' => $presentationDesignOptionen,
            'betriebsLinks' => $betriebsLinks,
            'betriebsOptionen' => $betriebsOptionen,
            // CRM-Picker
            'crmVerfuegbar' => $svc->crmVerfuegbar(),
            'firmen' => $svc->sucheFirmen($this->firmaSuche),
            'kontakte' => $svc->sucheKontakte($this->kontaktSuche),
            // Gerüst-Review (Slots VOR der Kaskade)
            'geruestSlots' => $geruestSlots,
            // Betriebe fürs Status-/Zuordnungs-Bauteil (nur aktive)
            'betriebe' => \Platform\FoodAlchemist\Models\FoodAlchemistOutlet::where('team_id', $team->id)
                ->where('is_inactive', false)->orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
            // Schreibstile fürs Kapitel-Tonalitäts-Override (aktive, team-sichtbar)
            'schreibstile' => \Platform\FoodAlchemist\Models\FoodAlchemistWritingStyle::visibleToTeam($team)
                ->where('is_inactive', false)->orderBy('name')->get(['id', 'name']),
            // Board-Coverage (SOLL/IST) — Angebot hat (noch) kein Gerüst-Coverage-Panel → leer, Blade ist ?? -sicher.
            'boardCoverage' => [],
            // Kontext-Tab: Kunden-Segment + Portfolio-Konflikt — beim Angebot (noch) nicht belegt.
            'segment' => null,
            'portfolioKonflikt' => null,
            // Zuschlagskalkulation (Vollkosten-Wasserfall) fürs GANZE Angebot × Pax (B3-Partial).
            'auftragsKalkulation' => $angebot ? $svc->auftragsKalkulation($team, $angebot, $outlet, $einheiten) : null,
        ]);
    }

    /**
     * Board-Daten je Kapitel: Baum-Struktur (kapitelTree) angereichert um Fortschritt + Aggregat
     * (VK/EK/FC gegen die Betriebsbrille). Foodbook nutzt LeitstelleService::kapitelBoard (foodbook-
     * typisiert); fürs Angebot bauen wir das Board offer-nativ aus OfferCompositionService.
     *
     * @return list<array{id:int,title:string,parent_id:?int,depth:int,fortschritt:string,vk_pro_person:?float,ek_pro_person:float,food_cost_percent:?float}>
     */
    /**
     * Board-Zeilen je Kapitel in der Foodbook-Board-Shape (die das geforkte Blade erwartet):
     * kapitel_id/titel/is_struktur/pricing_mode/hat_ziele/positionen_count/bepreist/hat_inhalt/
     * positionen[]/aggregat{vk_pro_person,ek_per_person,pauschal}/wareneinsatz{status,ist_pct,ziel_pct}.
     * Quelle = die bereits berechnete Komposition (intern) + die Kapitel-Modelle (fortschritt/is_struktur/Ziele).
     */
    private function kapitelBoardDaten(\Platform\Core\Models\Team $team, FoodAlchemistAngebot $angebot, ?array $komposition, float $zielWePct): array
    {
        $modelle = FoodAlchemistOfferChapter::visibleToTeam($team)
            ->where('offer_id', $angebot->id)->get()->keyBy('id');
        $ampel = app(\Platform\FoodAlchemist\Services\MargeService::class);
        $board = [];
        foreach (($komposition['kapitel'] ?? []) as $kap) {
            $k = $modelle->get($kap['id']);
            $positionen = [];
            if ($kap['ist_format']) {
                foreach ($kap['editionen'] as $ed) {
                    if (($ed['typ'] ?? 'concept') !== 'concept') {
                        continue;
                    }
                    $vk = (float) ($ed['preis_pp'] ?? 0);
                    $ek = (float) ($ed['ek_pp'] ?? 0);
                    $positionen[] = ['art' => 'paket', 'label' => (string) ($ed['name'] ?? ''), 'ek' => $ek, 'vk' => $vk,
                        'preis_einheit' => 'gast', 'we_pct' => ($vk > 0 && $ek > 0) ? round($ek / $vk * 100, 1) : null];
                }
            } else {
                foreach ($kap['bloecke'] as $b) {
                    if (($b['ist_header'] ?? false) || in_array($b['type'] ?? '', ['header', 'header_preis', 'text', 'spacer'], true)) {
                        continue;
                    }
                    $vk = (float) ($b['preis_pp'] ?? 0);
                    $ek = (float) ($b['ek_pp'] ?? 0);
                    $positionen[] = ['art' => ($b['type'] ?? '') === 'recipe_ref' ? 'einzel' : 'paket',
                        'label' => (string) ($b['label'] ?? ''), 'ek' => $ek, 'vk' => $vk,
                        'preis_einheit' => $b['preis_einheit'] ?? 'gast', 'we_pct' => ($vk > 0 && $ek > 0) ? round($ek / $vk * 100, 1) : null];
                }
            }
            $vkPP = $kap['vk_pro_person'];
            $ekPP = (float) ($kap['ek_pro_person'] ?? 0);
            $fcPct = $kap['food_cost_percent'] ?? (($vkPP ?? 0) > 0 && $ekPP > 0 ? round($ekPP / $vkPP * 100, 1) : null);
            $board[] = [
                'kapitel_id' => (int) $kap['id'],
                'titel' => (string) ($kap['title_intern'] ?? $kap['title'] ?? ''),
                'parent_id' => $kap['parent_id'],
                'depth' => (int) ($kap['depth'] ?? 0),
                'fortschritt' => $k?->fortschritt ?? 'offen',
                'is_struktur' => (bool) ($k?->is_struktur ?? false),
                'pricing_mode' => $kap['price_mode'] ?? null,
                'hat_ziele' => $k !== null && ($k->target_count !== null || $k->price_anchor !== null),
                'positionen' => $positionen,
                'positionen_count' => count($positionen),
                'hat_inhalt' => count($positionen) > 0,
                'bepreist' => ($vkPP ?? 0) > 0,
                'aggregat' => ['vk_pro_person' => $vkPP, 'ek_per_person' => $ekPP, 'pauschal' => (float) ($kap['pauschal'] ?? 0)],
                'wareneinsatz' => ['status' => $ampel->weAmpel($fcPct, $zielWePct), 'ist_pct' => $fcPct, 'ziel_pct' => $zielWePct],
            ];
        }

        return $board;
    }

    private function team()
    {
        return Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
    }
}
