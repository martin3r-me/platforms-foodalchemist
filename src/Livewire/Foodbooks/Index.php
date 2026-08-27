<?php

namespace Platform\FoodAlchemist\Livewire\Foodbooks;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Platform\FoodAlchemist\Livewire\Concerns\ManagesCanvas;
use Platform\FoodAlchemist\Livewire\Concerns\ManagesPhase;
use Platform\FoodAlchemist\Livewire\Concerns\ManagesPlanningFrame;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel;
use Platform\FoodAlchemist\Enums\AusgabeStatus;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Services\IdeenService;

/**
 * M11-03 / Doc 15 §9.3: Foodbook-Editor — stellt fertige **Concepts** zu einem
 * Kunden-Angebot zusammen (KEINE Einzel-Gerichte — der Concepter ist der Kern).
 * Foodbook-Liste + Kapitel-Baum links · Block-Liste Mitte · Pax-Gesamt-Cockpit rechts.
 */
class Index extends Component
{
    use WithPagination, WithFileUploads, ManagesCanvas, ManagesPlanningFrame, ManagesPhase;
    use \Platform\FoodAlchemist\Livewire\Concerns\InteractsWithSavedToast;

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
    // S3b: Slot-Vorschlags-/Füll-/Übernehme-/Struktur-Methoden entfernt (alte Foodbook-Planung →
    // Leitstelle-Kaskade). Die Service-Ebene (FoodbookService::uebernehmeVorschlag/strukturAusGeruest)
    // bleibt; sie wird jetzt vom Kaskaden-Motor genutzt.

    /** Fehlermeldung des Voll-Kaskade-Go (P3), wenn kein Gerüst da ist. */
    public ?string $kaskadeMeldung = null;

    /**
     * Spec 42 (F2) — Handoff in die Leitstelle. Die Planung (Brief → Gerüst → Kaskade) zieht in die
     * Leitstelle; das Foodbook ist reine Ausgabe. Dieser Knopf baut KEIN Gerüst mehr im Modul, sondern
     * öffnet die Leitstelle im Owner-Kontext dieses Foodbooks (`fb_owner`) — dort entstehen Struktur +
     * Inhalte und docken via attachToOutput automatisch zurück.
     */
    public function vollKaskadeStarten()
    {
        $this->kaskadeMeldung = null;
        if ($this->selectedId === null) {
            return null;
        }

        return redirect()->route('foodalchemist.planung.index', ['fb_owner' => (int) $this->selectedId]);
    }

    // ── Spec-42-Vollzug S3b: Kreativ-Skizzen (IdeenService), Paket-Bündelung, Kreativ-Modus,
    // Pairing-Pull/Lücke-Melden und der Kickoff-Brief→Gerüst-Vorschlag sind entfernt — die
    // ganze Planung lebt in der Leitstelle (Planung\Index + KapitelRail/FoodbookKontextRail).
    #[Url(as: 'q')]
    public string $search = '';

    /** R4.3: Phasen-Filter der Browser-Liste. */
    #[Url(as: 'phase')]
    public string $phaseFilter = '';

    #[Url(as: 'fb')]
    public ?int $selectedId = null;

    #[Url(as: 'kap')]
    public ?int $selectedKapitelId = null;

    public array $form = ['label' => '', 'jahr' => null, 'personen' => null, 'status' => 'draft', 'description' => ''];

    /** `description` = Kapitel-Kundentext (Spec 03 · L2b) — im Editor vorher gar nicht erreichbar. */
    public array $kapitelForm = ['title' => '', 'consumer_title' => '', 'description' => '', 'price_mode' => 'auto', 'price_per_person' => null, 'writing_style_id' => null];

    /**
     * Spec 03 · L2: KI-Kundentext — VORSCHAU-Zustand. Der Vorschlag landet hier und
     * nirgends sonst; erst `kiTextUebernehmen()` schreibt ihn ins Formular, erst
     * „Speichern" in die DB. Zwei Stufen mit Absicht: das Briefing-Feld ist oft
     * handgeschrieben, und ein still ersetzter Kundentext wäre unwiederbringlich.
     */
    public ?string $kiTextVorschau = null;

    public ?float $kiTextConfidence = null;

    /** Fehler ODER Erfolgs-Hinweis der KI-Text-Fläche (eine Zeile, ein Zustand). */
    public ?string $kiTextHinweis = null;

    /**
     * Spec 03 · L2b: WELCHES Feld der Vorschlag füllen soll — `foodbook` (Einleitung) oder
     * `kapitel` (Hinführung). EIN Vorschau-Zustand für beide Ebenen, weil beide Flächen
     * nie gleichzeitig sichtbar sind (Master-Detail: Buch-Kopf ODER Kapitel). Zwei
     * Zustands-Sätze wären zwei Wahrheiten über denselben Ablauf.
     */
    public string $kiTextZiel = 'foodbook';

    public string $neuesKapitelTitel = '';

    public string $conceptSuche = '';

    /** #3: Suche im Paket-Picker-Reiter (kind=paket-Concepts). */
    public string $paketSuche = '';

    /** Format-Umbau F5: Suche im „Format einfügen"-Picker (Katalog-Modus 'format'). */
    public string $formatSuche = '';

    /** Picker-Baustein (2026-08-23): aktiver Katalog-Modus (Server-Modus, wie Speisekarte).
     *  #3: nur noch concept|paket|format — der Gericht-Reiter ist raus (Picker enthält ausschließlich
     *  Concepte, Pakete, Formate). Property heisst bewusst NICHT wie die Methode katalogModus() —
     *  gleicher Name = Livewire-Footgun (client wire:click misfired). */
    public string $pickerModus = 'concept';

    /**
     * UX 2026-07-25 (Dominique): Concept-Picker filtert auf die Concepter-DIMENSIONEN
     * (Eventtyp/Servierform/Einsatzmoment/Saison) — Konzept-Taxonomie (Kategorie/Klasse) ausgemustert.
     *
     * @var array{eventtyp:?int, servierform:?int, einsatzmoment:?int, season:?int}
     */
    public array $conceptFacetten = ['eventtyp' => null, 'servierform' => null, 'einsatzmoment' => null, 'season' => null];

    /** #3: Paket-Picker-Reiter teilt dieselbe Facetten-Filterkette wie der Concept-Reiter. */
    public array $paketFacetten = ['eventtyp' => null, 'servierform' => null, 'einsatzmoment' => null, 'season' => null];

    /** E1.3: Freitext-Suche für den recipe_ref-Einzel-Gericht-Picker. */
    public string $gerichtSuche = '';

    /** UX 2026-07-24: Klassen-/Hauptgruppen-Filter im Gericht-Picker (Modell A: dish_main_group_id) — Browsen ohne Namen. */
    public ?int $gerichtHauptgruppe = null;

    /** UX 2026-07-24: Untergruppe (dish_class, z. B. „Vorspeise Vegan") unter der aktiven HG. */
    public ?int $gerichtDishClass = null;

    /** #369: CRM-Kunde-Picker. */
    public string $firmaSuche = '';

    public string $kontaktSuche = '';

    /** Block, dessen Inline-Editor offen ist + dessen Formular. */
    public ?int $editBlockId = null;

    public array $blockForm = [];

    /** C1: inline-editierte Gericht-Zeile in der Block-Vorschau — Key "blockId:slotId" + Text. */
    public ?string $editSlotKey = null;

    public string $editSlotWording = '';

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
            'label' => $fb->label, 'jahr' => $fb->jahr,
            'personen' => $fb->personen, 'status' => $fb->statusWert()->value, 'description' => $fb->description ?? '',
            // Spec 33 P1: Gültigkeitsfenster — ohne `bis` bliebe ein aktiv gesetztes Foodbook
            // für immer im Portfolio (nichts läuft von selbst ab).
            'gueltig_von' => $fb->gueltig_von?->format('Y-m-d') ?? '',
            'gueltig_bis' => $fb->gueltig_bis?->format('Y-m-d') ?? '',
            'outlet_id' => $fb->outlet_id,   // Spec 33 P2: Betriebsachse am Kopf
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
        if ($this->kiTextZiel === 'kapitel') {
            $this->kiTextZuruecksetzen('foodbook');   // die Kapitel-Fläche ist weg, ihr Vorschlag auch
        }
        // Spec 29 / S6: der Speisen-Tab entfällt ohne Kapitel — zurück auf Briefing, damit nicht
        // ein „aktiver", aber unsichtbarer Tab die Fläche leer lässt.
        $this->dispatch('fb-goto', tab: 'briefing');
    }

    /**
     * Spec 33 P5 — Schnellschalter aktiv ⇄ inaktiv.
     *
     * Nimmt eine laufende Ausgabe vom Netz und zurück, ohne den Umweg über das Status-Dropdown
     * und ohne zu archivieren. Geschrieben wird über den Service, damit Team-Guard,
     * Normalisierung und Audit dort bleiben, wo sie hingehören.
     */
    public function aktivUmschalten(FoodbookService $svc): void
    {
        if ($this->selectedId === null) {
            return;
        }
        $fb = FoodAlchemistFoodbook::visibleToTeam($this->team())->find($this->selectedId);
        if ($fb === null) {
            return;
        }

        $neu = $fb->statusWert() === AusgabeStatus::Aktiv ? AusgabeStatus::Inaktiv : AusgabeStatus::Aktiv;
        $svc->update($this->team(), $this->selectedId, ['status' => $neu->value]);
        $this->form['status'] = $neu->value;
    }

    public function speichern(FoodbookService $svc): void
    {
        if ($this->selectedId === null) {
            return;
        }
        $svc->update($this->team(), $this->selectedId, $this->form);
        // Der Tab-übergreifende „Speichern" sichert die GANZE Foodbook-Ebene — inkl. Branding
        // (Marken-Farbe/Bandfarbe/Footer). Sonst greift er nicht auf dem Branding-Tab und der
        // prominente Button ließe die CI-Änderungen unbemerkt liegen (Falle des hochgezogenen
        // Speicherns). Idempotent, wenn Branding nicht angefasst wurde; Hex-Fehler → brandingFehler.
        $this->brandingSpeichern($svc);
        $svc->vorschauSnapshotAktualisieren($this->team(), $this->selectedId);
        $this->savedToast('Foodbook gespeichert');
        // Der übernommene KI-Text ist jetzt echter Feld-Inhalt — die Vorschau-Fläche hat
        // nichts mehr zu sagen und würde sonst als „noch offen" stehen bleiben.
        $this->kiTextHinweis = null;
    }

    /**
     * Spec 03 · L2: Kundentext-Vorschlag für die Foodbook-Einleitung holen.
     * Schreibt NICHT ins Formular — nur in die Vorschau (`kiTextVorschau`).
     */
    public function kiEinleitung(FoodbookService $svc): void
    {
        $this->kiTextZuruecksetzen('foodbook');
        if ($this->selectedId === null) {
            return;
        }
        $this->kiTextHolen(fn () => $svc->kiKundentextVorschlag($this->team(), $this->selectedId));
    }

    /**
     * Spec 03 · L2b: Hinführung fürs gewählte Kapitel holen. Gleiche zwei Stufen wie auf
     * der Buch-Ebene — der Vorschlag berührt `kapitelForm.description` nie.
     */
    public function kiKapitelText(FoodbookService $svc): void
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
     * Der gemeinsame Call-Rahmen beider Ebenen: die typisierten KI-Ausfälle werden zu
     * genau einer Hinweis-Zeile. Bewusst EINE Stelle — sonst driften die Meldungen
     * zwischen Buch- und Kapitel-Knopf auseinander.
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

    /**
     * Vorschlag ins Formular übernehmen — bewusst OHNE zu speichern: der Text steht
     * danach sichtbar im Feld und geht denselben Weg wie jede Handeingabe („Speichern").
     * Das Ziel entscheidet `kiTextZiel`, nicht der Aufrufer: der Knopf sitzt in beiden
     * Ebenen an derselben Vorschau-Fläche.
     */
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
            $this->form['description'] = $this->kiTextVorschau;
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

    /**
     * Kreativ-Tab: eine kreative Leitplanke setzen (kundentyp | default_niveau | default_convenience).
     * Leer = zurück auf Erben (Segment-Default). Guideline fürs ganze Foodbook — Kapitel + Vorschläge
     * + KI-Erstellung erben sie, Kapitel können ihr Niveau via concept.level überschreiben.
     */
    public function leitplankeSetzen(string $feld, $wert, FoodbookService $svc): void
    {
        if ($this->selectedId === null || ! in_array($feld, ['kundentyp', 'default_niveau', 'default_convenience'], true)) {
            return;
        }
        $svc->update($this->team(), $this->selectedId, [
            $feld => ($wert === '' || $wert === null) ? null : (string) $wert,
        ]);
    }

    // S3b: Bedarf-Sektion (bedarfSetzen + toggleFbEinsatzmoment/Zielgruppe) entfernt — die Foodbook-
    // Default-Dimensionen werden als Planung in der Leitstelle gesetzt (Kapitel-Ziele / Buch-Ebene).

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

    /**
     * MVP-027 (P0): Ein Kapitel nur laden, wenn es dem Team gehört. Vorher prefillte eine
     * manipulierte fremde ID Titel/Kundentext/Preise in public Properties (Leseleck). Die
     * nachgelagerten Writes waren via FoodbookService::ownedKapitel geschützt — das Prefill nicht.
     */
    private function eigenesKapitel(int $id): ?\Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel
    {
        $team = Auth::user()?->currentTeamRelation;
        $k = \Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel::visibleToTeam($team)->find($id);

        return ($k !== null && $k->isOwnedBy($team)) ? $k : null;
    }

    private function eigenerBlock(int $id): ?\Platform\FoodAlchemist\Models\FoodAlchemistFoodbookBlock
    {
        $team = Auth::user()?->currentTeamRelation;
        $b = \Platform\FoodAlchemist\Models\FoodAlchemistFoodbookBlock::visibleToTeam($team)->find($id);

        return ($b !== null && $b->isOwnedBy($team)) ? $b : null;
    }

    public function kapitelWaehle(int $id, FoodbookService $svc): void
    {
        // Fremde/geerbte Kapitel gar nicht erst auswählen — sonst lädt ladeKapitelForm() ihre Daten.
        if ($this->eigenesKapitel($id) === null) {
            return;
        }
        $this->selectedKapitelId = $id;
        $this->ladeKapitelForm($svc);
        $this->editBlockId = null;
        $this->markiert = [];
        // Spec 29 / S6: Kapitelwahl springt in den Speisen-Tab (der jetzt erscheint) — via den
        // bestehenden fb-goto-Bus (Window-Event, greift, sobald das Panel im DOM ist).
        $this->dispatch('fb-goto', tab: 'speisen');
    }

    /**
     * E5.3: Die Leitstelle-Rail (Nested-Livewire) meldet Kapitel-Ziel-Edits hierher —
     * no-op-Handler, damit der Eltern-Render (Kapitel-Kopf, Coverage, Checkliste) die
     * Rail-Änderungen im Hauptbereich spiegelt.
     */
    #[On('leitstelle-kapitel-geaendert')]
    public function leitstelleAktualisiert(): void
    {
        // absichtlich leer — der Livewire-Roundtrip löst das Re-Render aus
    }

    private function ladeKapitelForm(FoodbookService $svc): void
    {
        // Kapitel-Wechsel = anderer Gegenstand: ein Vorschlag fürs vorige Kapitel darf hier
        // nicht stehen bleiben (er würde beim „Übernehmen" im falschen Feld landen).
        if ($this->kiTextZiel === 'kapitel') {
            $this->kiTextZuruecksetzen('kapitel');
        }
        if ($this->selectedKapitelId === null) {
            return;
        }
        $k = $this->eigenesKapitel($this->selectedKapitelId);        // MVP-027: nur eigenes prefillen
        if ($k) {
            $this->kapitelForm = [
                'title' => $k->title, 'consumer_title' => $k->consumer_title ?? '',
                'description' => $k->description ?? '',
                'price_mode' => $k->price_mode, 'price_per_person' => $k->price_per_person,
                'writing_style_id' => $k->writing_style_id,   // #2: Kapitel-Schreibstil-Override
            ];
        }
    }

    public function kapitelSpeichern(FoodbookService $svc): void
    {
        if ($this->selectedKapitelId !== null) {
            $svc->updateKapitel($this->team(), $this->selectedKapitelId, $this->kapitelForm);
            // Der übernommene KI-Text ist jetzt echter Feld-Inhalt (Muster wie `speichern()`).
            if ($this->kiTextZiel === 'kapitel') {
                $this->kiTextHinweis = null;
            }
        }
    }

    /**
     * #2: Kapitel-Wording im gewählten Kapitel-Schreibstil neu erzeugen (Snapshot, LLM-Kosten →
     * nur auf Knopfdruck). Speichert zuerst den Stil-Override, dann betextet der Service die
     * concept_ref-Blöcke des Kapitels foodbook-lokal. Kein Stil gesetzt = Hinweis, kein Call.
     */
    public function kapitelWordingGenerieren(FoodbookService $svc): void
    {
        if ($this->selectedKapitelId === null) {
            return;
        }
        $this->resetErrorBag('kapitelWording');
        // Stil-Override zuerst persistieren (der Regler + evtl. andere Feld-Edits).
        $svc->updateKapitel($this->team(), $this->selectedKapitelId, $this->kapitelForm);
        $stilId = $this->kapitelForm['writing_style_id'] ?? null;
        if ($stilId === null || $stilId === '') {
            $this->addError('kapitelWording', 'Kein Kapitel-Schreibstil gewählt — es gibt nichts zu überschreiben (Standard erbt live aus den Concepten).');

            return;
        }
        try {
            $n = $svc->kapitelWordingRegenerieren($this->team(), $this->selectedKapitelId);
        } catch (\Throwable $e) {
            $this->addError('kapitelWording', $e->getMessage());

            return;
        }
        $svc->vorschauSnapshotAktualisieren($this->team(), $this->selectedId);
        $this->dispatch('foodbook-gespeichert');
        $this->dispatch('toast', text: $n > 0 ? "{$n} Konzept(e) im Kapitel-Stil neu betextet." : 'Keine Konzept-Blöcke im Kapitel.');
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

    /**
     * E2.1: Kapitel eine Ebene TIEFER — neuer Parent = unmittelbar vorheriges Geschwister
     * (Outline-Editor-Doktrin). Ohne vorheriges Geschwister nicht einrückbar.
     */
    public function kapitelEinruecken(int $id, FoodbookService $svc): void
    {
        $k = \Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel::find($id);
        if ($k === null || $this->selectedId === null) {
            return;
        }
        $geschwister = \Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel::where('foodbook_id', $this->selectedId)
            ->where('parent_id', $k->parent_id)->orderBy('position')->pluck('id')->all();
        $pos = array_search($id, $geschwister, true);
        if ($pos === false || $pos === 0) {
            return; // erstes Geschwister hat keinen Vorgänger zum Einrücken
        }
        $this->kapitelUnter($id, (int) $geschwister[$pos - 1], $svc);
    }

    /**
     * E2.1: Kapitel eine Ebene HÖHER — neuer Parent = Großelternteil (oder Top-Ebene).
     * Top-Kapitel sind nicht weiter ausrückbar.
     */
    public function kapitelAusruecken(int $id, FoodbookService $svc): void
    {
        $k = \Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel::find($id);
        if ($k === null || $k->parent_id === null || $this->selectedId === null) {
            return;
        }
        $parent = \Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel::find($k->parent_id);
        $grossParent = $parent?->parent_id !== null ? (int) $parent->parent_id : null;
        $this->kapitelUnter($id, $grossParent, $svc);
    }

    /**
     * Gemeinsamer Move: Parent wechseln (Service trägt den Zyklus-Schutz) und das Kapitel
     * ans ENDE der neuen Geschwister-Ordnung setzen, damit `position` konsistent bleibt
     * (moveKapitel selbst rührt die Position nicht an).
     */
    private function kapitelUnter(int $id, ?int $neuerParent, FoodbookService $svc): void
    {
        try {
            $svc->moveKapitel($this->team(), $id, $neuerParent);
        } catch (\RuntimeException) {
            return; // Zyklus o. Ä. — UI bietet solche Moves ohnehin nicht an
        }
        $geschwister = \Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel::where('foodbook_id', $this->selectedId)
            ->where('parent_id', $neuerParent)->where('id', '!=', $id)->orderBy('position')->pluck('id')->all();
        $geschwister[] = $id;
        $svc->reorderKapitel($this->team(), $this->selectedId, $neuerParent, $geschwister);
    }

    /**
     * #4: Kapitel per Drag&Drop umsortieren — das gezogene Kapitel landet unmittelbar VOR dem
     * Ziel-Kapitel und übernimmt dessen Parent-Ebene (moveKapitel trägt den Zyklus-Schutz:
     * ein Kapitel auf einen eigenen Nachfahren gezogen wird abgewiesen). Spiegelt die
     * Block-Drop-Semantik (blockVerschiebenAuf).
     */
    public function kapitelVerschiebenAuf(int $dragId, int $zielId, FoodbookService $svc): void
    {
        if ($dragId === $zielId || $this->selectedId === null) {
            return;
        }
        $ziel = \Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel::where('foodbook_id', $this->selectedId)->find($zielId);
        if ($ziel === null) {
            return;
        }
        try {
            $svc->moveKapitel($this->team(), $dragId, $ziel->parent_id);   // auf die Ziel-Ebene (Zyklus-Schutz im Service)
        } catch (\RuntimeException) {
            return;
        }
        $geschwister = \Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel::where('foodbook_id', $this->selectedId)
            ->where('parent_id', $ziel->parent_id)->where('id', '!=', $dragId)->orderBy('position')->pluck('id')->all();
        $zielPos = array_search($zielId, $geschwister, true);
        if ($zielPos === false) {
            $geschwister[] = $dragId;
        } else {
            array_splice($geschwister, $zielPos, 0, [$dragId]);   // direkt VOR das Ziel
        }
        $svc->reorderKapitel($this->team(), $this->selectedId, $ziel->parent_id, $geschwister);
    }

    // ── Blöcke ────────────────────────────────────────────────────────────

    /** Picker-Baustein: Katalog-Modus umschalten (#3: concept|paket|format — kein Gericht mehr). */
    public function katalogModus(string $modus): void
    {
        if (in_array($modus, ['concept', 'paket', 'format'], true)) {
            $this->pickerModus = $modus;
        }
    }

    public function conceptHinzu(int $conceptId, FoodbookService $svc): void
    {
        if ($this->selectedKapitelId === null) {
            return;
        }
        $svc->addBlock($this->team(), $this->selectedKapitelId, ['type' => 'concept_ref', 'concept_id' => $conceptId]);
        $this->conceptSuche = '';
    }

    /**
     * #3: ein Paket (kind=paket-Concept) als concept_ref-Block ans Kapitel — dieselbe Buchung
     * wie {@see conceptHinzu} (concept_id trägt Concept + Paket). Eigener Picker-Reiter, damit
     * Pakete mit ihrem Kundennamen browsebar sind.
     */
    public function paketHinzu(int $paketId, FoodbookService $svc): void
    {
        if ($this->selectedKapitelId === null) {
            return;
        }
        $svc->addBlock($this->team(), $this->selectedKapitelId, ['type' => 'concept_ref', 'concept_id' => $paketId]);
        $this->paketSuche = '';
    }

    /**
     * Format-Umbau F5: ein Format ins Foodbook buchen — WIE EIN CONCEPT. Legt ein eigenes
     * Kapitel an, dessen Inhalt die Editionen als live concept_ref-Blöcke + die Struktur-Blöcke
     * des Formats sind (kein Live-Format-Sonderweg). Fail-soft: Kunden-IP-/Status-Guard meldet
     * sich als Fehler, kippt den Editor nicht. Braucht das gewählte Foodbook, kein Ziel-Kapitel.
     */
    public function formatEinfuegen(int $formatId, FoodbookService $svc): void
    {
        if ($this->selectedId === null) {
            return;
        }
        try {
            $svc->insertFormatAlsKapitel($this->team(), $this->selectedId, $formatId);
        } catch (\Throwable $e) {
            $this->addError('formatKapitel', $e->getMessage());

            return;
        }
        $this->formatSuche = '';
    }

    /**
     * E1.3: Einzel-Gericht (VK-Rezept) als `recipe_ref`-Block direkt ans Kapitel
     * (€/Position). Spiegelt `conceptHinzu`; die Schreibpfad-Validierung
     * (verkauf()-Scope, keine Slot-Variante) übernimmt `addBlock`/`pruefeRecipeRef`.
     */
    public function gerichtHinzu(int $recipeId, FoodbookService $svc): void
    {
        if ($this->selectedKapitelId === null) {
            return;
        }
        $svc->addBlock($this->team(), $this->selectedKapitelId, ['type' => 'recipe_ref', 'sales_recipe_id' => $recipeId]);
        $this->gerichtSuche = '';
    }

    /** Gericht-Picker: Hauptgruppe (de)selektieren; HG-Wechsel setzt die Untergruppe zurück. */
    public function waehleGerichtHg(?int $hgId): void
    {
        $this->gerichtHauptgruppe = ($this->gerichtHauptgruppe === $hgId) ? null : $hgId;
        $this->gerichtDishClass = null;
    }

    /** Gericht-Picker: Untergruppe (dish_class) unter der aktiven HG (de)selektieren. */
    public function waehleGerichtKlasse(int $dishClassId): void
    {
        $this->gerichtDishClass = ($this->gerichtDishClass === $dishClassId) ? null : $dishClassId;
    }

    /** Concept-Picker: eine Concepter-Dimension (eventtyp|servierform|einsatzmoment|season) (de)selektieren. */
    public function toggleConceptFacet(string $feld, int $id): void
    {
        if (! array_key_exists($feld, $this->conceptFacetten)) {
            return;
        }
        $this->conceptFacetten[$feld] = ($this->conceptFacetten[$feld] === $id) ? null : $id;
    }

    /** Concept-Picker: alle Dimensions-Filter zurücksetzen. */
    public function resetConceptFacetten(): void
    {
        $this->conceptFacetten = ['eventtyp' => null, 'servierform' => null, 'einsatzmoment' => null, 'season' => null];
    }

    /**
     * Dropdown-Bindung (Filter als <select> statt Pill-Wand, Produktions-Muster): Concept-Facetten
     * '' → null + int-Coercion (das Array ist typlos; der Service vergleicht strikt gegen int-IDs).
     */
    public function updatedConceptFacetten($value, $key): void
    {
        $this->conceptFacetten[$key] = ($value === '' || $value === null) ? null : (int) $value;
    }

    /** #3: Dropdown-Bindung der Paket-Picker-Facetten (analog updatedConceptFacetten). */
    public function updatedPaketFacetten($value, $key): void
    {
        $this->paketFacetten[$key] = ($value === '' || $value === null) ? null : (int) $value;
    }

    /** Dropdown-Bindung: HG-Wechsel setzt die Unterklasse zurück (wie waehleGerichtHg). */
    public function updatedGerichtHauptgruppe(): void
    {
        $this->gerichtDishClass = null;
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
        $block = $this->eigenerBlock($id);                           // MVP-027: kein fremdes Prefill
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

    /**
     * C1: eine einzelne Gericht-Zeile der Block-Vorschau inline bearbeiten (manuelles Wording,
     * wenn das KI-Ergebnis nicht passt). Öffnet den Inline-Editor mit dem aktuellen Text.
     */
    public function slotWordingBearbeiten(int $blockId, int $slotId, ?string $aktuell = null): void
    {
        $this->editSlotKey = $blockId . ':' . $slotId;
        $this->editSlotWording = (string) $aktuell;
    }

    /**
     * C1: den inline bearbeiteten Anzeigenamen speichern — als foodbook-lokaler Block-Override
     * (payload_json['wording_overrides'][slotId], oberste Stufe der Wording-Kette). Leer = zurück
     * auf die Kette (Konzept-Wording → Standard → Name). Das Concept bleibt unangetastet.
     */
    public function slotWordingSpeichern(FoodbookService $svc): void
    {
        if ($this->editSlotKey === null || ! str_contains($this->editSlotKey, ':')) {
            return;
        }
        [$blockId, $slotId] = array_map('intval', explode(':', $this->editSlotKey, 2));
        try {
            $svc->setBlockSlotWording($this->team(), $blockId, $slotId, $this->editSlotWording);
        } catch (\Throwable $e) {
            $this->addError('slotWording', $e->getMessage());

            return;
        }
        $this->editSlotKey = null;
        $this->editSlotWording = '';
        $svc->vorschauSnapshotAktualisieren($this->team(), $this->selectedId);
        $this->dispatch('foodbook-gespeichert');
    }

    public function slotWordingAbbrechen(): void
    {
        $this->editSlotKey = null;
        $this->editSlotWording = '';
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

        // #7: Live-Menü-Vorschau je concept_ref-Block (aufgelöste gerichtZeilen — wie im Format-Editor).
        // Löst eingebettete Pakete rekursiv auf (embeddedConcept) + trägt den Paket-Preis (#1). Key = Block-ID.
        $blockMenus = [];
        if ($kapitel !== null) {
            $conceptBloecke = $kapitel->blocks->where('type', 'concept_ref')->filter(fn ($b) => $b->concept_id !== null);
            if ($conceptBloecke->isNotEmpty()) {
                $wording = app(\Platform\FoodAlchemist\Services\WordingResolver::class);
                $geladen = \Platform\FoodAlchemist\Models\FoodAlchemistFoodbookBlock::whereIn('id', $conceptBloecke->pluck('id'))
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

        // S3b: Kreativ-Modus/Pairing-Inspiration + Skizzen-Render-Daten entfallen (Kreativ-Fläche →
        // Leitstelle). Die zugehörigen Props (kreativSeed/ideenPapierkorb/skizzeGerichtSuche) sind weg.

        $menue = $fb !== null ? $svc->vorschauSnapshot($fb) : null;

        // R2.6: Ø-Feedback je Gericht fürs interne Foodbook (Bulk über alle Snapshot-Zeilen).
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
            // Board (2026-08-27): EIN Baum statt Übersicht/Fortschritt/Preise — Status + Inhalt + Preis je Kapitel.
            'kapitelBoard' => $fb !== null
                ? app(\Platform\FoodAlchemist\Services\LeitstelleService::class)->kapitelBoard($team, $fb) : [],
            // Coverage/Befunde je Kapitel (aus dem EINEN CoverageService-Call gruppiert) — inline beim Aufklappen.
            'boardCoverage' => collect($coverage['befunde'] ?? [])
                ->groupBy('chapter_id')->map(fn ($g) => $g->values()->all())->all(),
            'coverage' => $coverage,
            // Spec 19 E5.2: abgeleitete 7-Schritt-Leitstellen-Checkliste (offen/teil/erledigt + Sprungziel)
            'checkliste' => $fb !== null
                ? app(\Platform\FoodAlchemist\Services\LeitstelleService::class)->checkliste($team, $fb) : [],
            'foodbooks' => $svc->paginateBrowser(['search' => $this->search, 'phase' => $this->phaseFilter], $team),
            'fb' => $fb,
            // Spec 33 P5: Auswahl für das geteilte Status-/Zuordnungs-Bauteil. Nur aktive
            // Betriebe — ein deaktivierter Standort soll nicht neu zugeordnet werden.
            'betriebe' => \Platform\FoodAlchemist\Models\FoodAlchemistOutlet::where('team_id', $team->id)
                ->where('is_inactive', false)->orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
            // Spec 33 P3: Hinweis, kein Verbot — zwei parallel laufende Ausgaben derselben
            // Art in derselben Zuordnung können gewollt sein (Übergang, Sonderfall).
            'portfolioKonflikt' => $fb === null ? null
                : app(\Platform\FoodAlchemist\Services\PortfolioService::class)
                    ->konfliktHinweis($team, 'foodbook', (int) $fb->id),
            // D (UX-Umbau): Kunden-Vorschau (Menü-Ansicht) mit aufgelöster Wording-Kette — dieselbe Quelle wie das Druck-Dokument
            'menue' => $menue,
            'menueSnapshotAt' => $fb?->preview_snapshot_at,
            'feedbackAgg' => $feedbackAgg,
            'kapitelTree' => $fb !== null ? $svc->kapitelTree($team, $fb->id) : [],
            'kapitel' => $kapitel,
            'blockMenus' => $blockMenus,   // #7: Live-Menü-Vorschau je concept_ref-Block
            // E5.3: Portfolio/Kapitel-Aggregat + WE ziehen jetzt in die Leitstelle-Rail (Nested-Livewire) um.
            'headerPresets' => FoodbookService::headerPresets(),
            // UX 2026-07-25 (Dominique): Concept-Picker filtert auf die Concepter-DIMENSIONEN
            // (Eventtyp/Servierform/Einsatzmoment/Saison) — Konzept-Taxonomie (Kategorie/Klasse) ausgemustert.
            // Vokabulare wie im Concepter-Browser; nur bei gewähltem Kapitel laden.
            'facetteEventtypen' => $this->selectedKapitelId !== null
                ? \Platform\FoodAlchemist\Models\FoodAlchemistEventtyp::visibleToTeam($team)->where('is_inactive', false)->orderBy('sort_order')->get(['id', 'name']) : collect(),
            'facetteServierformen' => $this->selectedKapitelId !== null
                ? \Platform\FoodAlchemist\Models\FoodAlchemistServierform::where('is_inactive', false)->orderBy('sort_order')->get(['id', 'label']) : collect(),
            'facetteMomente' => $this->selectedKapitelId !== null
                ? \Platform\FoodAlchemist\Models\FoodAlchemistEinsatzmoment::visibleToTeam($team)->where('is_inactive', false)->orderBy('sort_order')->get(['id', 'name']) : collect(),
            'facetteSaisons' => $this->selectedKapitelId !== null
                ? \Platform\FoodAlchemist\Models\FoodAlchemistSaison::visibleToTeam($team)->where('is_inactive', false)->orderBy('sort_order')->get(['id', 'name']) : collect(),
            // UX-Fix 2026-07-24 (Dominique „Eingabe katastrophe"): Picker zeigt beim Öffnen sofort eine
            // browsebare Liste — Suche/Dimensionen FILTERN nur noch, sind nicht mehr Voraussetzung. Service
            // liefert bei leerer Suche längst alle (orderBy name, cap 50). Nur an Kapitel-Auswahl gebunden.
            'conceptKandidaten' => $this->selectedKapitelId !== null
                ? $svc->conceptKandidaten($team, $this->conceptSuche, 50, $this->conceptFacetten) : collect(),
            // #3: Paket-Reiter (kind=paket-Concepts) — dieselbe Filterkette, consumer_name statt intern.
            'paketKandidaten' => $this->selectedKapitelId !== null
                ? $svc->paketKandidaten($team, $this->paketSuche, 50, $this->paketFacetten) : collect(),
            // E1.3: Einzel-Gericht-Picker (recipe_ref) — sofort Liste, Suche + Klasse (HG) + Untergruppe filtern nur
            'gerichtKandidaten' => $this->selectedKapitelId !== null
                ? $svc->gerichtKandidaten($team, $this->gerichtSuche, 50, $this->gerichtHauptgruppe, $this->gerichtDishClass) : collect(),
            // Format-Umbau F5: Format-Kandidaten für den „Format einfügen"-Picker (Kunden-IP-gefiltert).
            // Braucht nur das Foodbook (Format wird zum eigenen Kapitel), nicht die Kapitel-Auswahl.
            'formatKandidaten' => $fb !== null ? $svc->formatKandidaten($team, $fb, $this->formatSuche, 50) : collect(),
            // UX 2026-07-24: Klassen-Spalte im Gericht-Picker (Modell A: aktive VK-Hauptgruppen)
            'gerichtHauptgruppen' => $this->selectedKapitelId !== null
                ? app(\Platform\FoodAlchemist\Services\SalesRecipeService::class)->dishMainGroups($team)
                : collect(),
            // Untergruppen (dish_classes) der aktiven HG — Drill-down im Gericht-Picker
            'gerichtUntergruppen' => ($this->selectedKapitelId !== null && $this->gerichtHauptgruppe !== null)
                ? \Platform\FoodAlchemist\Models\FoodAlchemistDishClass::visibleToTeam($team)
                    ->where('dish_main_group_id', $this->gerichtHauptgruppe)
                    ->orderBy('id')->get(['id', 'label', 'diet_form'])
                : collect(),
            // S3b: kreativModus/kreativInspiration/ideenListe/skizzeKandidaten entfallen (Kreativ-Fläche → Leitstelle).
            // „bereits angelegt"-Zustand (Bestands-Foodbooks): Kapitel trägt schon Inhalt, aber keine Skizzen
            'kapitelHatInhalt' => $this->selectedKapitelId !== null
                && \Platform\FoodAlchemist\Models\FoodAlchemistFoodbookBlock::where('chapter_id', $this->selectedKapitelId)
                    ->whereIn('type', ['concept_ref', 'recipe_ref'])->exists(),
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
            // Kreativ-Tab: kreative Leitplanken (Kundentyp + Niveau + Convenience) + aufgelöster Ist-Stand
            'kundentypen' => \Platform\FoodAlchemist\Services\TeamSettingsService::KUNDENTYPEN,
            'niveauLabels' => \Platform\FoodAlchemist\Services\TeamSettingsService::NIVEAU_LABEL,
            'convenienceLabels' => \Platform\FoodAlchemist\Services\TeamSettingsService::CONVENIENCE_LABEL,
            'leitplanken' => $fb !== null ? $svc->leitplanken($team, $fb) : null,
            // Spec 19 E3.3: Bedarf-Sektion — Vokabulare für Foodbook-Default-Dimensionen
            'eventtypen' => \Platform\FoodAlchemist\Models\FoodAlchemistEventtyp::visibleToTeam($team)
                ->where('is_inactive', false)->orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
            'servierformen' => \Platform\FoodAlchemist\Models\FoodAlchemistServierform::where('is_inactive', false)
                ->orderBy('sort_order')->orderBy('label')->get(['id', 'label']),
            'einsatzmomente' => \Platform\FoodAlchemist\Models\FoodAlchemistEinsatzmoment::visibleToTeam($team)
                ->where('is_inactive', false)->orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
            'zielgruppen' => \Platform\FoodAlchemist\Models\FoodAlchemistTargetGroup::visibleToTeam($team)
                ->where('is_inactive', false)->orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
        ])->layout('platform::layouts.app');
    }

    private function team()
    {
        return Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
    }
}
