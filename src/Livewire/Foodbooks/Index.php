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
        // Kreative Leitplanken: das aufgelöste Niveau (Foodbook-Default → Segment) rankt die
        // Vorschläge (Rezepte mit passender Niveau-Eignung höher). Die Kapitel-Stufe (concept.level)
        // greift erst, wenn das Kapitel-Konzept existiert — beim ersten Vorschlag gilt der Default.
        $svc = app(FoodbookService::class);
        $fb = $svc->detail($this->team(), $this->selectedId);
        $lp = $fb !== null ? $svc->leitplanken($this->team(), $fb) : ['niveau' => null, 'convenience' => null];
        $this->slotVorschlaege[$slotId] = app(\Platform\FoodAlchemist\Services\ConceptGeneratorService::class)
            ->slotVorschlaege($this->team(), $frame, $slot, 6, $lp['niveau'], $lp['convenience']);
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

    /** slotId → kurze Rückmeldung nach „Konzept füllen" (Leitstelle-Kaskade). */
    public array $slotFuellStatus = [];

    /**
     * Leitstelle-Kaskade (schritt-für-schritt, pro Slot): erzeugt/füllt das Slot-Konzept mit
     * passenden Gerichten. Das Foodbook ist die Leitstelle — das Konzept erbt die Leitplanken
     * (uebernehmeVorschlag stempelt concept.level), die Gericht-Auswahl folgt Niveau + Convenience.
     * $quelle = 'bestand' (deterministisch, gerankt) | 'neu' (KI-Neu-Erstellung, braucht Provider, #512).
     */
    public function slotFuellen(int $slotId, string $quelle, FoodbookService $svc): void
    {
        $this->slotFuellStatus[$slotId] = null;
        if ($this->selectedId === null || $this->frameId === null) {
            return;
        }
        $frame = \Platform\FoodAlchemist\Models\FoodAlchemistPlanningFrame::find($this->frameId);
        $slot = \Platform\FoodAlchemist\Models\FoodAlchemistPlanningFrameSlot::find($slotId);
        if ($frame === null || $slot === null) {
            return;
        }
        if ($slot->chapter_id === null) {
            $this->slotFuellStatus[$slotId] = 'Erst „Struktur anwenden" — der Slot braucht ein Kapitel.';
            return;
        }
        if ($quelle === 'neu') {
            // KI-Neu-Erstellung eines Gerichts zum Niveau/Convenience/Kundentyp — braucht einen
            // gebundenen LLM-Provider (auf demo, #512). Solange deterministisch aus Bestand füllen.
            $this->slotFuellStatus[$slotId] = 'KI-Neu-Erstellung braucht einen gebundenen Provider (demo, #512) — solange „aus Bestand" nutzen.';
            return;
        }

        // Bestand: Top-target_count gerankt (Niveau + Convenience aus den Leitplanken) → ins Slot-Konzept.
        $lp = $svc->leitplanken($this->team(), $svc->detail($this->team(), $this->selectedId));
        $anzahl = max(1, (int) ($slot->target_count ?: 3));
        $vorschlaege = app(\Platform\FoodAlchemist\Services\ConceptGeneratorService::class)
            ->slotVorschlaege($this->team(), $frame, $slot, $anzahl, $lp['niveau'], $lp['convenience']);
        $n = 0;
        foreach ($vorschlaege as $v) {
            $res = $svc->uebernehmeVorschlag($this->team(), $this->selectedId, $slotId, (int) $v['id']);
            if (! ($res['schon_drin'] ?? false)) {
                $n++;
            }
        }
        unset($this->slotVorschlaege[$slotId]);
        $this->frameLaden();
        $this->slotFuellStatus[$slotId] = $n > 0
            ? "Konzept gefüllt: {$n} Gericht(e) aus dem Bestand (Niveau/Convenience-gerankt)."
            : 'Kein passendes Gericht im Bestand — Leitplanken/Filter prüfen oder „neu erstellen".';
    }

    // ── Phase 3a: „Struktur anwenden" — Gerüst-Slots als Kapitel materialisieren (Slot = Kapitel) ──
    public ?array $strukturErgebnis = null;

    /** Fehlermeldung des Voll-Kaskade-Go (P3), wenn kein Gerüst da ist. */
    public ?string $kaskadeMeldung = null;

    public function strukturAnwenden(FoodbookService $svc): void
    {
        $this->strukturErgebnis = null;
        if ($this->selectedId === null) {
            return;
        }
        $this->strukturErgebnis = $svc->strukturAusGeruest($this->team(), $this->selectedId);
        // Gerüst neu laden, damit $frameSlots die frisch gesetzten chapter_id trägt.
        $this->frameLaden();
    }

    /**
     * Voll-Kaskade (P3): aus dem Foodbook-Gerüst je Slot ein Concept erzeugen (an sein Kapitel gehängt) +
     * je Concept der Gericht-Fan-out. Legt eine Planungs-Session als Review-Wurzel an und leitet in den
     * Planung-Editor (Live-Fortschritt + Freigabe). Ohne Gerüst → Meldung.
     */
    public function vollKaskadeStarten(
        \Platform\FoodAlchemist\Services\PlanningCascadeService $cascade,
        \Platform\FoodAlchemist\Services\PlanningSessionService $sessions
    ) {
        $this->kaskadeMeldung = null;
        $team = $this->team();
        if ($team === null || $this->selectedId === null) {
            return null;
        }
        $fb = \Platform\FoodAlchemist\Models\FoodAlchemistFoodbook::visibleToTeam($team)->find($this->selectedId);
        try {
            $session = $sessions->create($team, [
                'title' => 'Voll-Kaskade: ' . ($fb?->label ?? ('Foodbook #' . $this->selectedId)),
                'created_via' => 'foodbook_vollkaskade',
            ]);
            $cascade->starteKaskade($team, 'vollkaskade', $session, 'voll_kreativ', [
                'owner_type' => 'foodbook', 'owner_id' => (int) $this->selectedId, 'created_via' => 'foodbook_vollkaskade',
            ]);

            return redirect()->route('foodalchemist.planung.index', ['session' => $session->id, 'open' => 1]);
        } catch (\Throwable $e) {
            $this->kaskadeMeldung = $e->getMessage();

            return null;
        }
    }

    // ── Spec 19 E6.3: Kreativ-Skizzenfläche (IdeenService) ──────────────────────
    // Divergenz-Ebene: freie oder aus Bestand übernommene Skizzen PRO Kapitel, Paket-Bündelung
    // per Mehrfachauswahl. Erdet NICHTS (Invariante M4) — erst das Kapitel-Go (E7.3)
    // materialisiert Skizzen zu Konzepten/Blöcken. Deshalb hier nur entwurf|verworfen.
    public string $ideeTitel = '';

    public string $skizzeGerichtSuche = '';

    /** Markierte Idee-IDs für „zu Paket bündeln" (nur freie Einzel-Skizzen). */
    public array $ideeAuswahl = [];

    public string $paketName = '';

    public bool $ideenPapierkorb = false;

    public ?string $ideenFehler = null;

    /** Spec 19 E9.4: Kreativ-Modus-Umschalter + Pairing-Inspiration (Pull-not-Push). */
    public string $kreativSeed = '';

    public ?string $kreativHinweis = null;

    /** Modus pro Kapitel setzen (voll_kreativ|hybrid|datenbank) — erbt sonst vom Foodbook. */
    public function kreativModusSetzen(string $modus): void
    {
        if ($this->selectedKapitelId === null
            || ! in_array($modus, FoodAlchemistFoodbookKapitel::CREATIVE_MODES, true)) {
            return;
        }
        app(FoodbookService::class)->updateKapitel($this->team(), $this->selectedKapitelId, ['creative_mode' => $modus]);
        $this->kreativHinweis = null;
    }

    /** „erden?"-Pull pro Idee: Idee-Titel als Inspirations-Seed (kein Dauer-Einblenden des Bestands). */
    public function erdenPull(string $term): void
    {
        $this->kreativSeed = trim($term);
        $this->kreativHinweis = null;
    }

    /** Bewusstes Melden einer Sortiments-Lücke ins Signale-Cockpit (E9.3). */
    public function luckeMelden(string $slug): void
    {
        app(\Platform\FoodAlchemist\Services\PairingInspirationService::class)
            ->meldeLuecke($this->team(), $slug, ['kapitel_id' => $this->selectedKapitelId]);
        $this->kreativHinweis = 'Sortiments-Lücke gemeldet: ' . $slug;
    }

    /** Freie Skizze anlegen (Titel Pflicht) — Owner = gewähltes Kapitel. */
    public function ideeHinzu(): void
    {
        $this->ideenFehler = null;
        if ($this->selectedKapitelId === null) {
            return;
        }
        try {
            app(IdeenService::class)->add($this->team(), [
                'chapter_id' => $this->selectedKapitelId,
                'title' => $this->ideeTitel,
            ]);
            $this->ideeTitel = '';
        } catch (\RuntimeException $e) {
            $this->ideenFehler = $e->getMessage();
        }
    }

    /** Bestands-Gericht als Skizze übernehmen (loser sales_recipe_id-Zeiger, dedupliziert NICHTS). */
    public function skizzeAusBestand(int $recipeId): void
    {
        $this->ideenFehler = null;
        if ($this->selectedKapitelId === null) {
            return;
        }
        try {
            app(IdeenService::class)->uebernehmeBestand($this->team(), [
                'chapter_id' => $this->selectedKapitelId,
                'sales_recipe_id' => $recipeId,
            ]);
            $this->skizzeGerichtSuche = '';
        } catch (\RuntimeException $e) {
            $this->ideenFehler = $e->getMessage();
        }
    }

    public function ideeVerwerfen(int $id): void
    {
        $this->ideeStatus($id, 'verworfen');
    }

    public function ideeReaktivieren(int $id): void
    {
        $this->ideeStatus($id, 'entwurf');
    }

    private function ideeStatus(int $id, string $status): void
    {
        $this->ideenFehler = null;
        try {
            app(IdeenService::class)->setStatus($this->team(), $id, $status);
        } catch (\RuntimeException $e) {
            $this->ideenFehler = $e->getMessage();
        }
        // Eine verworfene Skizze fällt aus der Bündel-Auswahl.
        $this->ideeAuswahl = array_values(array_filter($this->ideeAuswahl, fn ($v) => (int) $v !== $id));
    }

    /** Mehrfachauswahl → neue Paket-Gruppe + Zuordnung (Muster markiere()/wahlGruppeBilden()). */
    public function paketBilden(): void
    {
        $this->ideenFehler = null;
        if ($this->selectedKapitelId === null || $this->ideeAuswahl === []) {
            return;
        }
        try {
            $svc = app(IdeenService::class);
            $gruppe = $svc->addGruppe($this->team(), [
                'chapter_id' => $this->selectedKapitelId,
                'name' => trim($this->paketName) !== '' ? trim($this->paketName) : 'Paket',
            ]);
            foreach ($this->ideeAuswahl as $iid) {
                $svc->update($this->team(), (int) $iid, ['group_id' => $gruppe->id]);
            }
            $this->ideeAuswahl = [];
            $this->paketName = '';
        } catch (\RuntimeException $e) {
            $this->ideenFehler = $e->getMessage();
        }
    }

    /** Einzelne Skizze aus ihrem Paket lösen (→ Einzel; target_form muss mitgezogen werden, sonst greift der Paket-Guard). */
    public function ausPaketLoesen(int $ideaId): void
    {
        $this->ideenFehler = null;
        try {
            app(IdeenService::class)->update($this->team(), $ideaId, ['group_id' => 0, 'target_form' => 'einzel']);
        } catch (\RuntimeException $e) {
            $this->ideenFehler = $e->getMessage();
        }
    }

    /** Ganzes Paket auflösen: Mitglieder lösen + leere Gruppe entfernen. */
    public function paketAufloesen(int $groupId): void
    {
        $this->ideenFehler = null;
        try {
            app(IdeenService::class)->loescheGruppe($this->team(), $groupId);
        } catch (\RuntimeException $e) {
            $this->ideenFehler = $e->getMessage();
        }
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

        // Auto-Kontext: Segment (Bespielung) + kreative Leitplanken (Kundentyp/Niveau/Convenience)
        // + Marken-Kontext aus der DNA-Kaskade — alles als Vorgabe an die KI-Gerüst-Erstellung.
        $seg = app(\Platform\FoodAlchemist\Services\TeamSettingsService::class)->segment($team);
        $lp = app(FoodbookService::class)->leitplanken($team, $fb);
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
                    'leitplanken' => $lp,
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

    public array $form = ['label' => '', 'jahr' => null, 'personen' => null, 'status' => 'draft', 'description' => ''];

    /** `description` = Kapitel-Kundentext (Spec 03 · L2b) — im Editor vorher gar nicht erreichbar. */
    public array $kapitelForm = ['title' => '', 'consumer_title' => '', 'description' => '', 'price_mode' => 'auto', 'price_per_person' => null];

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

    /**
     * UX 2026-07-25 (Dominique): Concept-Picker filtert auf die Concepter-DIMENSIONEN
     * (Eventtyp/Servierform/Einsatzmoment/Saison) — Konzept-Taxonomie (Kategorie/Klasse) ausgemustert.
     *
     * @var array{eventtyp:?int, servierform:?int, einsatzmoment:?int, season:?int}
     */
    public array $conceptFacetten = ['eventtyp' => null, 'servierform' => null, 'einsatzmoment' => null, 'season' => null];

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

    // ── Spec 19 E3.3: Bedarf-Sektion (Briefing-Tab) — Foodbook-Default-Dimensionen ──

    /**
     * Einen skalaren Bedarf-Default setzen (Eventtyp / Servierform per FK-Id, Wareneinsatz-Ziel + Toleranz
     * als %). Leer = zurück auf Erben (Team-/Segment-Default). Kaskadiert als Foodbook-Boden nach unten.
     */
    public function bedarfSetzen(string $feld, $wert, FoodbookService $svc): void
    {
        if ($this->selectedId === null
            || ! in_array($feld, ['default_event_type_id', 'default_serving_form_id', 'target_food_cost_pct', 'food_cost_tolerance_pp'], true)) {
            return;
        }
        $leer = $wert === '' || $wert === null;
        $wert = $leer ? null : (in_array($feld, ['default_event_type_id', 'default_serving_form_id'], true) ? (int) $wert : (float) $wert);
        $svc->update($this->team(), $this->selectedId, [$feld => $wert]);
    }

    /** Einsatzmoment-Pill (Tagesablauf, 1–n) am Foodbook an/abwählen. */
    public function toggleFbEinsatzmoment(int $id, FoodbookService $svc): void
    {
        if ($this->selectedId === null) {
            return;
        }
        $svc->toggleEinsatzmoment($this->team(), $this->selectedId, $id);
    }

    /** Zielgruppen-Pill (Default, 1–n) am Foodbook an/abwählen. */
    public function toggleFbZielgruppe(int $id, FoodbookService $svc): void
    {
        if ($this->selectedId === null) {
            return;
        }
        $svc->toggleZielgruppe($this->team(), $this->selectedId, $id);
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

    // ── Blöcke ────────────────────────────────────────────────────────────

    public function conceptHinzu(int $conceptId, FoodbookService $svc): void
    {
        if ($this->selectedKapitelId === null) {
            return;
        }
        $svc->addBlock($this->team(), $this->selectedKapitelId, ['type' => 'concept_ref', 'concept_id' => $conceptId]);
        $this->conceptSuche = '';
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

        // Spec 19 E9.4: Kreativ-Modus (Kaskade Kapitel→Foodbook→hybrid) + Pairing-Inspiration.
        // Inspiration ist GATED auf $kreativSeed (Pull-not-Push) — kein Auto-Einblenden des Bestands.
        $kreativModus = $kapitel !== null ? $svc->kreativModus($team, $kapitel) : null;
        $kreativInspiration = null;
        if ($kreativModus !== null && $this->kreativSeed !== '') {
            $inspSvc = app(\Platform\FoodAlchemist\Services\PairingInspirationService::class);
            $seeds = $inspSvc->sucheAnker($this->kreativSeed, 5)->pluck('slug')->all();
            $kreativInspiration = $inspSvc->inspiration($team, $seeds, $kreativModus['modus']);
        }

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
            'coverage' => $coverage,
            // Spec 19 E5.2: abgeleitete 7-Schritt-Leitstellen-Checkliste (offen/teil/erledigt + Sprungziel)
            'checkliste' => $fb !== null
                ? app(\Platform\FoodAlchemist\Services\LeitstelleService::class)->checkliste($team, $fb) : [],
            // Spec 19 E8.1: Preise-Tab — Kapitel-Baum mit EK/VK/WE-% + WE-Ampel + Duality-Positionen (VK-Deep-Links)
            'preiseBaum' => $fb !== null
                ? app(\Platform\FoodAlchemist\Services\LeitstelleService::class)->preiseBaum($team, $fb) : [],
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
            // E1.3: Einzel-Gericht-Picker (recipe_ref) — sofort Liste, Suche + Klasse (HG) + Untergruppe filtern nur
            'gerichtKandidaten' => $this->selectedKapitelId !== null
                ? $svc->gerichtKandidaten($team, $this->gerichtSuche, 50, $this->gerichtHauptgruppe, $this->gerichtDishClass) : collect(),
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
            // Spec 19 E9.4: Kreativ-Modus (führt/erbt) + Pairing-Inspiration (gated, Pull-not-Push)
            'kreativModus' => $kreativModus,
            'kreativInspiration' => $kreativInspiration,
            // Spec 19 E6.3: Kreativ-Skizzenfläche — Skizzen des gewählten Kapitels (Pakete + freie Einzel)
            'ideenListe' => $this->selectedKapitelId !== null
                ? app(IdeenService::class)->liste($team, $this->selectedKapitelId, null, $this->ideenPapierkorb)
                : ['gruppen' => [], 'einzel' => collect()],
            // „aus Bestand"-Quelle der Skizzenfläche (Reuse des VK-Gericht-Pickers)
            'skizzeKandidaten' => $this->skizzeGerichtSuche !== '' && $this->selectedKapitelId !== null
                ? $svc->gerichtKandidaten($team, $this->skizzeGerichtSuche, 50) : collect(),
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
