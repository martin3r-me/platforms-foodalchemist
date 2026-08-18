<?php

namespace Platform\FoodAlchemist\Livewire\Planung;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistDishIdea;
use Platform\FoodAlchemist\Models\FoodAlchemistPlanningSession;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeStepPhoto;
use Platform\FoodAlchemist\Services\ConceptGeneratorService;
use Platform\FoodAlchemist\Services\IdeenService;
use Platform\FoodAlchemist\Services\PairingService;
use Platform\FoodAlchemist\Services\PlanningCascadeService;
use Platform\FoodAlchemist\Services\PlanningSessionService;
use Platform\FoodAlchemist\Services\RecipeImageService;
use Platform\FoodAlchemist\Services\SalesRecipeService;
use Platform\FoodAlchemist\Services\TitelVorschlagService;
use Platform\FoodAlchemist\Services\WorkerHealthService;
use Platform\FoodAlchemist\Support\TeamScope;

/**
 * Planungs-/Kreativ-Cockpit (Doppel-Diamant, Spec 08). Haus-Layout: links Kategorie→Klasse +
 * Session-Liste, Mitte Dashboard/Vorschau, rechts Detail; „Öffnen" → Fullscreen-Dark-Editor mit
 * Tabs Analyse · Skizzen · Planung + Go-Leiste. Read-mostly Container VOR dem Grounding — erst
 * „Go" erzeugt Basisrezept/Gericht/Concept (Draft), Lineage zurück in die Session.
 */
class Index extends Component
{
    use WithFileUploads;

    public ?string $fehler = null;

    /** Etappe 7 Teil 2: manuell hochgeladene Fotos je Cockpit-Step (stepId → TemporaryUploadedFile) —
     *  die NICHT-KI-Alternative zur Bild-Erzeugung, gebunden pro Step (mehrere Drafts gleichzeitig). */
    public array $fotoUploads = [];

    /** Etappe 7 Teil 3b: der Step, dessen Foto-Wiederverwendungs-Picker gerade offen ist (null = zu).
     *  Nur einer offen zur Zeit — die Kandidatenliste rechnet render() nur für diesen Step. */
    public ?int $fotoPickerStep = null;

    #[Url(as: 'session')]
    public ?int $sessionId = null;

    /** Neue-Planung-Formular (Mitte-Dashboard). */
    public string $neuTitel = '';

    /** Landing-Liste (finale Etappe #17): Volltext-Suche über den Session-Titel (live). */
    public string $sucheListe = '';

    /** Landing-Liste (finale Etappe #17): Status-Filter (''=alle | entwurf|läuft|prüfen|fertig|fehlgeschlagen). */
    public string $filterStatus = '';

    /** Landing-Liste (finale Etappe #17): Typ-Filter (''=alle | rezept|gericht|concept — aus dem Lauf-Scope). */
    public string $filterTyp = '';

    /** Worker (T2): Name eines manuell ergänzten Basisrezepts (Sub-Step, den die KI nicht erkannt hat). */
    public string $neuerSubName = '';

    /** Editor-Felder der aktiven Session. */
    public array $form = ['title' => '', 'brief' => '', 'analysis' => '', 'creative_mode' => 'voll_kreativ'];

    /** Skizzen-Eingaben. */
    public string $ideeTitel = '';

    public string $paketName = '';

    /** Composer-Tab: gewählte Anker (Einträge {id, slug, label}), Cap = INNER_ANKER_MAX (12). */
    public array $composerAnker = [];

    /** Composer-Suchfeld (live). */
    public string $composerTerm = '';

    /** Composer-Picker: Kategorie-Filter (leer = alle). */
    public string $composerCategory = '';

    /** Composer-Fokus: aktiv fokussierter Anker (Klick im Netz) — Netz dimmt auf ihn, Picker rankt relativ zu ihm. */
    public ?int $composerFocus = null;

    // ── Leitstelle: PER-TAB Eingabe + Leitplanken (jeder Scope eigener Zustand) ──
    /**
     * Eingabe je Creation-Tab (rezept|gericht|concept): Titel/Beschreibung/Kreativ-Modus.
     * Jeder Tab ist UNABHÄNGIG — Werte auf einem Tab wirken nicht auf die anderen. In mount()
     * initialisiert. Am Go zählt der Satz des Start-Tabs.
     * @var array<string,array{titel:string,brief:string,creative_mode:string}>
     */
    public array $eingabe = [];

    /**
     * Richtungs-Regler (Leitplanken) JE Scope — jeder Tab hat einen eigenen kompletten Satz
     * (inkl. favoriten/favoriten_conv_only/ziel_vk/voll_anreichern/ki_bilder). In mount() je Tab
     * aus REGLER_DEFAULT kopiert. **Kaskaden-Regel (User-Entscheid 2026-08-14):** am Go zählt NUR
     * der Satz des Start-Tabs; er wird als `generation_params` persistiert und propagiert die ganze
     * Kaskade nach unten (Start-Tab gilt für alles darunter). Spiegelt Generator-/VkGenerator-Modal.
     * @var array<string,array<string,mixed>>
     */
    public array $regler = [];

    /** Die drei Creation-Scopes (Tabs mit eigener Eingabe + Leitplanken). */
    public const SCOPES = ['rezept', 'gericht', 'concept'];

    /** Default-Leitplanken-Satz je Scope (in mount() je Tab kopiert). */
    public const REGLER_DEFAULT = [
        // Frische = Multi-Select Erlaubnis-Liste (L1.5): [] = egal (kein Zustands-Filter). Ein/mehrere
        // Werte = harte Erlaubnis (nur diese gps.condition). `bestand` wird aus dem Kreativ-Modus
        // abgeleitet (nicht mehr eigener Chip, s. reglerParams) — Default bleibt als Fallback stehen.
        'convenience' => '', 'frische' => [], 'bestand' => 'hybrid',
        'bio_praeferenz' => 'konventionell', 'level' => '', 'sektor' => '',
        // aroma = Freitext-Feinjustierung, aroma_kueche = kuratierte Küche (11 + Frei, L1.5).
        // allergen_nogo (L3): EU-14-Allergen-Ausschluss (hart geprüft, getrennt vom Diät-Ausschluss).
        'diaet_hart' => [], 'allergen_nogo' => [], 'aroma' => '', 'aroma_kueche' => '',
        // L6 »Menge & Ziel« (KI-Vorgaben): Pax (Gäste), Ziel-Portionsgröße g, Saison, Ziel-Wareneinsatz %.
        // Basisrezept (Halbfabrikat, kein Teller): Ziel-Menge + Einheit statt Pax/Portion (scope-abhängig, s. Blade).
        'pax' => '', 'ziel_portion_g' => '', 'saison' => '', 'ziel_we_pct' => '',
        'ziel_einheit' => '', 'ziel_menge' => '',
        'occasion' => '', 'serviceform' => '', 'kompositions_stil' => '',
        'favoriten' => false, 'favoriten_conv_only' => false,
        'ziel_vk' => '', 'voll_anreichern' => false, 'ki_bilder' => false,
        // Concept-Typ (#35): Menü (Gänge) vs. Buffet (Stationen) — steuert Label + Positionen-Logik.
        // Default 'menue' (byte-identisch bisher); nur 'buffet' ist ein Signal (station-Slots).
        'menue_typ' => 'menue',
        // Menü-Leitplanken (nur Concept-Tab, Etappe 2a): Anzahl Gänge + Zielpreis-Korridor je Person.
        // Als Text/Zahl gehalten (leer = keine Vorgabe); reglerParams parst sie in kanonische _pp-Keys.
        'menue_gaenge' => '', 'menue_preis_min' => '', 'menue_preis_ziel' => '', 'menue_preis_max' => '',
        // Diät-Quoten (Etappe 2a, Teil 2): Portfolio-Anteil vegan/vegetarisch in % (leer = keine Vorgabe).
        // reglerParams parst sie in kanonische _pct-Keys. Anteil ≠ harter Ausschluss (diaet_hart).
        'menue_quote_vegan' => '', 'menue_quote_vegetarisch' => '',
        // Portfolio-Balance (Etappe 2a, Rest Teil 2): Menü-Vielfalt (leer = keine Vorgabe). reglerParams
        // reicht nur einen erlaubten MENUE_BALANCE-Enum-Wert durch (Concept-Scope). Weich, kein Filter.
        'menue_balance' => '',
    ];

    /**
     * Portfolio-Balance-Achse (Menü-Vielfalt, Etappe 2a, Rest Teil 2) — Wert=>Label.
     * WEICHE Zusammenstellungs-Vorgabe (kein harter Filter): wie breit das Menü über Proteine/
     * Warengruppen/Garmethoden streut. Enum, damit reglerParams gegen einen definierten Satz prüft
     * (Select kann nicht vertippt werden → kein Zahl-Guard wie bei Gänge/Quote nötig). Nur Concept.
     */
    public const MENUE_BALANCE = [
        'ausgewogen' => 'Ausgewogen (breite Vielfalt)',
        'fokussiert' => 'Fokussiert (ein roter Faden)',
    ];

    /**
     * Concept-Typ-Achse (#35) — Wert=>Label. Bestimmt, WIE die Positionen des Concepts gebaut werden:
     * »Menü« = zeitliche Dramaturgie (gang-Slots, „Anzahl Gänge"), »Buffet« = parallele Stationen
     * (station-Slots, „Anzahl Stationen"). Ein Buffet ist KEIN Menü mit vielen Gängen — es hat eine
     * eigene Positionen-Logik (die Gänge-Achse gilt nur fürs Menü). Default = Menü: nur der explizite
     * `buffet`-Wert ist ein Signal, `menue`/leer bleibt byte-identisch zum bisherigen Verhalten.
     * Nur Concept-Scope.
     */
    public const MENUE_TYPEN = [
        'menue' => 'Menü (Gänge nacheinander)',
        'buffet' => 'Buffet (Stationen parallel)',
    ];

    /**
     * Brief-Vorlagen je Sektor/Anlass (Etappe 4 — „Schnellstart statt Blank Page"). Kuratierte
     * Starter-Briefings, die die leere Seite bewusst füllen; der Mensch passt danach an. Jede Vorlage
     * trägt den Kontext, für den sie steht — `sektor`/`occasion`/`serviceform` spiegeln 1:1 die
     * Leitplanken-Enums (leitplanken.blade.php), es werden also KEINE neuen Vokabeln erfunden.
     * `scopes` = auf welchen Creation-Tabs die Vorlage erscheint. Teil 1: nur Gericht (Sektor/Anlass
     * sind Gericht-/Menü-Kontext; Basisrezept ist komponentenhaft sektor-agnostisch, Concept eigener
     * Tab → Folge-Chunk). Der `brief` ist Guidance (nüchtern, keine harten Fakten/Werte als Wahrheit).
     * @var array<string,array{label:string,scopes:list<string>,titel:string,brief:string,sektor:string,occasion:string,serviceform:string}>
     */
    public const BRIEF_VORLAGEN = [
        'catering_empfang_flying' => [
            'label' => 'Catering — Empfang / Flying',
            'scopes' => ['gericht'],
            'titel' => '',
            'brief' => 'Fingerfood-Häppchen für einen Steh-Empfang, in einem Bissen ohne Besteck essbar und formstabil (auch nach kurzer Wartezeit auf der Platte). Ansprechend im Flying Service zu reichen.',
            'sektor' => 'catering',
            'occasion' => 'empfang',
            'serviceform' => 'flying',
        ],
        'catering_galadinner' => [
            'label' => 'Catering — Galadinner (Hauptgang)',
            'scopes' => ['gericht'],
            'titel' => '',
            'brief' => 'Warmer Hauptgang für ein gesetztes Galadinner im Tellerservice, gehobenes Niveau, sauber anrichtbar und regenerierfähig für den Bankett-Ausstoß.',
            'sektor' => 'catering',
            'occasion' => 'dinner',
            'serviceform' => 'tellerservice',
        ],
        'bgm_mittagstisch' => [
            'label' => 'Betriebsgastro — Mittagstisch',
            'scopes' => ['gericht'],
            'titel' => '',
            'brief' => 'Mittagsgericht für die Betriebsgastronomie am Buffet, warmhalte- und ausgabestabil über die Mittagslinie, kalkulierbarer Wareneinsatz und alltagstaugliche Zutaten.',
            'sektor' => 'betriebsgastronomie',
            'occasion' => 'lunch',
            'serviceform' => 'buffet',
        ],
        'care_mittag' => [
            'label' => 'Care / Klinik — Mittagsverpflegung',
            'scopes' => ['gericht'],
            'titel' => '',
            'brief' => 'Mittagsgericht für die Care-/Klinikverpflegung im Tellerservice, gut kaufähig und bekömmlich, zurückhaltend gewürzt und regenerierfähig aus der Zentralküche.',
            'sektor' => 'care',
            'occasion' => 'lunch',
            'serviceform' => 'tellerservice',
        ],
        'schule_mittag' => [
            'label' => 'Schule / Kita — Mittagsverpflegung',
            'scopes' => ['gericht'],
            'titel' => '',
            'brief' => 'Kindgerechtes Mittagsgericht für Schule/Kita am Buffet, mild gewürzt und akzeptanzstark, an DGE-Qualitätsstandards orientiert und in Serie ausgabefähig.',
            'sektor' => 'schule_kita',
            'occasion' => 'lunch',
            'serviceform' => 'buffet',
        ],
        'restaurant_hauptgang' => [
            'label' => 'Restaurant — à la carte Hauptgang',
            'scopes' => ['gericht'],
            'titel' => '',
            'brief' => 'À-la-carte-Hauptgang fürs Restaurant im Tellerservice, à la minute abrufbar, sauberes Plating und eine klare geschmackliche Handschrift.',
            'sektor' => 'restaurant',
            'occasion' => 'dinner',
            'serviceform' => 'tellerservice',
        ],
    ];

    /** #1b Grounding-Preview: welches Wissen/Pairing/Template ein Basisrezept-Lauf ziehen würde (on-demand, ohne Generierung). */
    public ?array $wissenVorschau = null;

    /** Welche Schnellstart-Vorlage je Scope aktuell geladen ist (id) — fürs Chip-Highlight. Eine manuelle Brief-Änderung hebt sie auf ({@see updated()}). */
    public array $aktiveVorlage = [];

    /** Eingabe für „Als Vorlage speichern" (Snapshot-Name), im Erstell-Tab gebunden. */
    public string $vorlageName = '';

    /**
     * A: Welche Draft-Steps ihren Inline-Zutaten-Editor offen haben (step_id-Liste).
     * Kontrolliertes On-Demand-Mounten statt N eingebetteter Editoren beim Fan-out —
     * erst beim Aufklappen wird der IngredientEditor für diesen Draft gerendert.
     */
    public array $zutatenOffen = [];

    /**
     * Pill-Gruppen fürs Cockpit-View (Parität zu GeneratorModal::RICHTUNGEN). Inline
     * gehalten statt aus dem Modal referenziert — die Leitstelle ist der neue Ort der
     * Steuerung; die Modal-Knöpfe der Browser-Seiten entfallen.
     */
    public const RICHTUNGEN = [
        ['field' => 'convenience', 'label' => 'Convenience (Eigenleistung)', 'optionen' => ['' => '(egal)', 'from_scratch' => 'From Scratch', 'teil_convenience' => 'Teil-Convenience', 'voll_convenience' => 'Voll-Convenience'], 'hint' => ['' => 'Keine Vorgabe', 'from_scratch' => 'alles selbst — Pool dreht auf Roh/Sub-Rezepte', 'teil_convenience' => 'Halbfabrikate erlaubt', 'voll_convenience' => 'Fertigprodukte bevorzugt']],
        ['field' => 'level', 'label' => 'Niveau', 'optionen' => ['' => '(egal)', 'haute_cuisine' => 'Haute Cuisine', 'gehoben' => 'Gehoben', 'klassisch' => 'Klassisch'], 'hint' => ['' => 'Keine Vorgabe']],
        // »Bestand-Nutzung« (Chips) entfernt (2026-08-17): die Reuse-Achse ist jetzt EIN Regler — der
        // Kreativ-Modus-Select im Eingabe-Block (voll_kreativ|hybrid|datenbank). `bestand` wird daraus
        // in reglerParams abgeleitet, keine zweite konkurrierende Achse mehr.
        ['field' => 'bio_praeferenz', 'label' => 'Bio-Präferenz', 'optionen' => ['konventionell' => 'Konventionell', 'bio' => 'Bio', 'egal' => 'Egal'], 'hint' => ['konventionell' => 'Standard — kein Bio erzwungen (Default)', 'bio' => 'Bio bevorzugt (nur auf Ansage)', 'egal' => 'keine Präferenz']],
        // »Frische-Hook« ist jetzt Multi-Select (Erlaubnis-Liste) → eigener Block in leitplanken.blade
        // (FRISCHE_OPTIONEN), nicht mehr Single-Pill hier.
    ];

    /**
     * Frische-Erlaubnis-Liste (L1.5, Multi-Select): UI-Slug => Label. Nichts angehakt = egal (kein
     * Zustands-Filter). Angehakt = harte Erlaubnis auf `gps.condition` (raw-Werte frisch|TK|trocken|
     * konserviert). Deckt endlich »trocken« ab (§9, 1.301 GPs) — im Gegensatz zum alten 3-Wert-Hook.
     */
    public const FRISCHE_OPTIONEN = [
        'frisch' => 'Frisch', 'tk' => 'TK', 'trocken' => 'Trocken', 'konserve' => 'Konserve/haltbar',
    ];

    /** UI-Slug => roher `gps.condition`-Wert (für den Post-Match-Zustands-Filter, spiegelt §9). */
    public const FRISCHE_CONDITION = [
        'frisch' => 'frisch', 'tk' => 'TK', 'trocken' => 'trocken', 'konserve' => 'konserviert',
    ];

    /**
     * Aroma-Küchen (L1.5, Achse 4 aus _Entscheidungsachsen.md v1.9): Slug => Label. »Frei« (leer) =
     * KI wählt zur Beschreibung. Jede Küche trägt zusätzlich Würz-Anker/Technik/Archetyp in den
     * Prompt (RecipeGenerationContextService::aromaKuecheBlock) — verbindliches Regelwerk, keine Erfindung.
     */
    public const AROMA_KUECHEN = [
        '' => 'Frei (KI wählt)',
        'klassisch_de' => 'Klassisch DE', 'franzoesisch' => 'Französisch', 'mediterran' => 'Mediterran',
        'italienisch' => 'Italienisch', 'asiatisch' => 'Asiatisch (allg.)', 'japanisch' => 'Japanisch',
        'thai' => 'Thai', 'indisch' => 'Indisch', 'orient' => 'Orient', 'lateinamerika' => 'Lateinamerika',
        'neu_nordisch' => 'Neu-Nordisch',
    ];

    /**
     * Saison-Achse (L6): Slug => Label. »Ganzjährig« (Default-leer = keine Vorgabe). Prompt-Vorgabe
     * (Erntefenster/Verfügbarkeit); die deterministische season_coverage-Frame-Rule ist Follow-up.
     */
    public const SAISON_OPTIONEN = [
        '' => 'Ganzjährig / egal',
        'fruehling' => 'Frühling', 'sommer' => 'Sommer', 'herbst' => 'Herbst', 'winter' => 'Winter',
    ];

    /**
     * Ziel-Mengen-Einheiten fürs BASISREZEPT (Halbfabrikat) — value=>Label. Ein Basisrezept ist keine
     * Teller-Portion für N Gäste, sondern eine Charge in einer Einheit (2 L Sauce, 5 kg Teig, 30 Stk).
     * Kuratierte KI-Vorgabe-Liste (kein Vocab-Zwang — reiner Prompt-Hint), reglerParams whitelistet dagegen.
     */
    public const MENGE_EINHEITEN = [
        '' => '(egal)',
        'l' => 'Liter', 'ml' => 'Milliliter', 'kg' => 'Kilogramm', 'g' => 'Gramm',
        'stk' => 'Stück', 'portionen' => 'Portionen',
    ];

    public ?string $meldung = null;

    /**
     * Etappe 6 Margen-Gate: Warnung, wenn bei einer Stufen-Freigabe Positionen UNTER ihrer
     * Aufschlagsklasse freigegeben wurden (manueller VK < Klassen-Vorschlag). Reine Rückkopplung,
     * keine harte Sperre — der Mensch entscheidet (Nordstern). Wird je Freigabe frisch gesetzt.
     */
    public ?string $margenWarnung = null;

    /** Aktiver Kaskaden-Lauf (in-place „Go") — Ziel des wire:poll. */
    public ?int $laufId = null;

    /** true, solange der Lauf im Hintergrund rechnet (steuert das Polling). */
    public bool $laeuft = false;

    /** Läuft nach einer Freigabe noch eine async Anreicherung (deferred.enrich) ODER KI-Foto-Erzeugung (deferred.bilder, „neu erzeugen") nach (queued|running)? Hält das Polling am Leben, bis das Ergebnis wirklich brauchbar ist. */
    public bool $anreicherungLaeuft = false;

    /**
     * Queue-Watchdog (2026-08): gesetzt, wenn der Lauf ungewöhnlich lange auf `running` steht,
     * OHNE dass ein Schritt je Fortschritt machte — fast sicher kein Queue-Worker aktiv (ein echter
     * Fehler ruft markStepFailed → status=failed). Kein Abbruch, nur ein sichtbarer Hinweis statt
     * endlosem Spinner. Die Leitstelle ist der EINZIGE KI-Erstell-Pfad → derselbe Schutz, den die
     * Modals in Phase 0 bekamen (HatGeneratorLauf), gehört auch hierher.
     */
    public ?string $hinweis = null;

    /**
     * Geplanter Pfad (Etappe 2b, „KI-Kopf"): die ID des vorab ausgearbeiteten, im Conceptor geprüften
     * Draft-Concepts ({@see kiKopf} → {@see ConceptGeneratorService::planAusBrief}). Gesetzt = der nächste
     * Concept-Go referenziert diesen Plan (`existing_concept_id`, KEIN neuer GenerateConceptJob), statt neu
     * zu generieren. `null` = Schnell-Pfad (Brief → Go generiert frisch). **Transient** (keine DB-Spalte) —
     * lebt nur, solange die Fläche gemountet ist; nach dem Go verbraucht ({@see goKaskade} setzt zurück).
     */
    public ?int $planConceptId = null;

    /**
     * Ursprungs-Skizze für den Gericht-Go (Etappe 4, Teil 2a — Lineage). {@see skizzeAlsGericht} merkt
     * die Skizze beim Übertragen in den Gericht-Tab; der nächste Gericht-`Go` stempelt sie als
     * `origin_dish_idea_id` auf den Lauf ({@see goKaskade}) → die Skizzen-Karte kann später den
     * Lauf-Status zurückspiegeln. **Transient** (keine DB-Spalte); nach dem Go verbraucht.
     */
    public ?int $skizzeGerichtId = null;

    /**
     * Gezielte Batch-Auswahl (Etappe 4, Teil 3b) — angehakte Skizzen-IDs im Divergenz-Board. Ist die
     * Liste NICHT leer, startet {@see skizzenBatchAlsGerichte} nur GENAU diese (Schnittmenge mit den
     * bearbeitbaren Skizzen); ist sie leer, bleibt es beim „alle"-Verhalten. Checkbox-Werte kommen als
     * Strings aus Livewire — beim Filtern nach int normalisieren.
     */
    public array $skizzenAuswahl = [];

    /** Sekunden auf `running` ohne jeden Step-Fortschritt, ab denen der Watchdog anschlägt (über der realistischen Erst-Dauer). */
    protected const WATCHDOG_SEKUNDEN = 90;

    /**
     * Batch-Kaskaden-Cap (Etappe 4, Teil 3) — Runaway-/Kosten-Guard: pro Klick höchstens so viele
     * Skizzen-Gerichte auf einmal starten. Spiegelt die `kiDivergenz`-Obergrenze (max. 12 Skizzen
     * je Lauf) — mehr wird gedeckelt und gesagt, nicht still verschluckt.
     */
    protected const BATCH_SKIZZEN_MAX = 12;

    /** Deep-Link `?session=X&open=1` (z.B. vom Trendradar-Carry-in) öffnet den Editor direkt. */
    public function mount(): void
    {
        // Per-Tab-State initialisieren — jeder Scope eigene Eingabe + eigener Leitplanken-Satz.
        foreach (self::SCOPES as $s) {
            $this->eingabe[$s] = ['titel' => '', 'brief' => '', 'creative_mode' => 'voll_kreativ'];
            $this->regler[$s] = self::REGLER_DEFAULT;
        }
        if (request()->boolean('open') && $this->sessionId !== null && $this->aktiveSession() !== null) {
            $this->ladeForm();
            $this->dispatch('modal.open', name: 'planung-editor');
        }
    }

    private function team(): ?Team
    {
        return Auth::user()?->currentTeamRelation;
    }

    // ── Session-Lifecycle ──────────────────────────────────────────────

    public function neuePlanung(PlanningSessionService $svc): void
    {
        $team = $this->team();
        if ($team === null) {
            $this->fehler = 'Kein Team zugeordnet — Planung kann nicht angelegt werden.';

            return;
        }
        // „+" ohne Titel legt trotzdem eine Planung an (Default = Placeholder) und öffnet sie
        // sofort — sonst reagiert der Button bei leerem Feld still gar nicht (Bug 2026-08-03).
        // Umbenennen geht danach im Editor-Kopf (form.title).
        $titel = trim($this->neuTitel) !== '' ? trim($this->neuTitel) : 'Neue Planung';
        $session = $svc->create($team, ['title' => $titel, 'created_via' => 'ui']);
        $this->neuTitel = '';
        $this->fehler = null;
        $this->oeffne($session->id);
    }

    /**
     * Freie 1-Klick-Erstellung (Leitstelle, de-trend): legt eine leichte Session an
     * (created_via=cockpit_frei, kein Trend) und öffnet den Editor direkt auf dem Planung-Tab,
     * wo die Regler-Leitplanken + der Go liegen. Ein Klick bis zum Regler — kein Trend-Umweg.
     */
    public function schnellErstellen(string $scope, PlanningSessionService $svc): void
    {
        if (! in_array($scope, ['rezept', 'gericht', 'concept'], true)) {
            return;
        }
        $team = $this->team();
        if ($team === null) {
            $this->fehler = 'Kein Team zugeordnet — Erstellung nicht möglich.';

            return;
        }
        $titel = match ($scope) {
            'gericht' => 'Freies Gericht',
            'concept' => 'Freies Concept',
            default => 'Freies Basisrezept',
        };
        $session = $svc->create($team, ['title' => $titel, 'created_via' => 'cockpit_frei']);
        $this->fehler = null;
        // Scope-Treue (Etappe 1): der Frei-Start setzt die Ebene korrekt — Basisrezept-Scope öffnet den
        // Basisrezept-Tab (nicht den Gericht-Tab). scope 'rezept' → Tab-Key 'basisrezept'; sonst = scope-Key.
        $startTab = $scope === 'rezept' ? 'basisrezept' : $scope;
        $this->oeffne($session->id, $startTab);
    }

    public function oeffne(int $id, ?string $startTab = null): void
    {
        $this->sessionId = $id;
        $this->fehler = null;
        $this->meldung = null;
        $this->ladeForm();
        $this->ladeLetztenLauf();
        // Scope-Treue: ein „Freies Basisrezept"-Start öffnet direkt auf dem Basisrezept-Tab (Ebene ≠ Gericht).
        // Ohne $startTab bleibt der Editor-Default (tabInit='analyse') — z.B. beim Öffnen aus der Liste.
        $this->dispatch('modal.open', name: 'planung-editor', tab: $startTab);
    }

    public function waehle(int $id): void
    {
        $this->sessionId = $id;
        $this->ladeLetztenLauf();
    }

    /**
     * Zuletzt-Karte: Planung verwerfen (Soft-Delete, reversibel; finale Etappe #17). Team-owned (D1)
     * über den Service. War die verworfene Session gerade aktiv, wird der Editor-/Lauf-Kontext gelöst.
     */
    public function planungVerwerfen(int $id, PlanningSessionService $svc): void
    {
        $team = $this->team();
        if ($team === null) {
            return;
        }
        try {
            $svc->verwerfen($team, $id);
            if ((int) $this->sessionId === $id) {
                $this->sessionId = null;
                $this->laufId = null;
                $this->laeuft = false;
            }
            $this->meldung = 'Planung verworfen.';
            $this->fehler = null;
        } catch (\Throwable $e) {
            $this->fehler = 'Verwerfen nicht möglich: ' . $e->getMessage();
        }
    }

    /**
     * Zuletzt-Karte: Planung duplizieren (frischer team-eigener Entwurf; finale Etappe #17). Team-owned
     * (D1) über den Service. Wählt die Kopie NICHT automatisch aus — sie erscheint in der Liste.
     */
    public function planungDuplizieren(int $id, PlanningSessionService $svc): void
    {
        $team = $this->team();
        if ($team === null) {
            return;
        }
        try {
            $kopie = $svc->duplizieren($team, $id);
            $this->meldung = 'Planung dupliziert — „' . $kopie->title . '".';
            $this->fehler = null;
        } catch (\Throwable $e) {
            $this->fehler = 'Duplizieren nicht möglich: ' . $e->getMessage();
        }
    }

    /** Beim Öffnen/Wählen den letzten Kaskaden-Lauf laden — läuft er noch, wird das Polling fortgesetzt. */
    private function ladeLetztenLauf(): void
    {
        $team = $this->team();
        if ($team === null || $this->sessionId === null) {
            $this->laufId = null;
            $this->laeuft = false;

            return;
        }
        $lauf = app(PlanningCascadeService::class)->letzterLauf($team, $this->sessionId);
        $this->laufId = $lauf?->id;
        $this->laeuft = $lauf !== null && $lauf->status === 'running';
    }

    private function ladeForm(): void
    {
        $session = $this->aktiveSession();
        if ($session === null) {
            return;
        }
        $this->form = [
            'title' => (string) $session->title,
            'brief' => (string) $session->brief,
            'analysis' => (string) $session->analysis,
            'creative_mode' => (string) $session->creative_mode,
        ];

        // Trendradar-Anbindung (Etappe 4, Teil 1): eine aus einem Trend eröffnete Session
        // (`source_knowledge_document_id`, Carry-in via `?open=1`) trägt Brief/Titel bisher nur in
        // die Analyse (`form`) — das eigentliche Go-Briefing je Tab (`eingabe[scope]`) blieb LEER,
        // der Trend erreichte die Generierung also nie. Hier wird der Trend-Brief ins Tab-Briefing
        // vorbefüllt, sodass der Nutzer mit einem gefüllten Briefing startet statt Blank Page.
        if ($session->source_knowledge_document_id !== null) {
            $this->seedBriefingAusTrendSession($session);
        }

        // #53 Persistenz: einen vorbereiteten KI-Kopf-Plan (plan_concept_id) über den Reload retten —
        // aber nur, wenn das Draft-Concept noch existiert + team-eigen ist (sonst still auf null; ein
        // toter/verwaister Zeiger darf den Concept-Go nicht auf einen Geister-Plan schicken). Spiegelt
        // die Fail-soft-Ownership-Prüfung in goKaskade.
        $this->planConceptId = null;
        $team = $this->team();
        if ($team !== null && $session->plan_concept_id !== null
            && FoodAlchemistConcept::where('team_id', $team->id)->whereKey($session->plan_concept_id)->exists()) {
            $this->planConceptId = (int) $session->plan_concept_id;
        }

        // L5: die beim Go persistierten Leitplanken (generation_params) in die Regler zurücklesen — sonst
        // zeigt jeder Tab nach einem Reload wieder die Defaults, während der Lauf mit anderen Werten fuhr.
        $this->rehydriereReglerAusParams($session);
    }

    /**
     * L5: generation_params → Regler zurückspiegeln (Umkehrung von {@see reglerParams}). Fail-soft; nur
     * gesetzte Achsen überschreiben den Default. Auf ALLE Scopes angewandt (die Session hält nur EINEN
     * Satz — den des Start-Tabs; ohne Tab-Herkunft ist der beste Reload derselbe Satz je Tab). Bewusst
     * NICHT rückformatiert: ziel_vk + menue_preis_* (Euro/Komma) — die tippt der Nutzer bei Bedarf neu.
     */
    private function rehydriereReglerAusParams(FoodAlchemistPlanningSession $session): void
    {
        $p = is_array($session->generation_params) ? $session->generation_params : [];
        if ($p === []) {
            return;
        }
        try {
            // Frische: rohe condition-Werte → UI-Slugs (Umkehrung FRISCHE_CONDITION).
            $condToSlug = array_flip(self::FRISCHE_CONDITION);
            $frischeSlugs = array_values(array_filter(array_map(
                static fn ($raw) => $condToSlug[$raw] ?? null,
                (array) ($p['frische_erlaubt'] ?? []),
            )));
            $bioPraef = match ($p['bio_pref'] ?? null) {
                'bio' => 'bio', 'neutral' => 'egal', 'conventional' => 'konventionell', default => null,
            };
            foreach (self::SCOPES as $scope) {
                if (! isset($this->regler[$scope])) {
                    continue;
                }
                foreach (['level', 'sektor', 'aroma', 'aroma_kueche', 'occasion', 'serviceform',
                    'kompositions_stil', 'menue_balance', 'menue_typ'] as $k) {
                    if (($p[$k] ?? '') !== '') {
                        $this->regler[$scope][$k] = $p[$k];
                    }
                }
                foreach (['diaet_hart', 'allergen_nogo'] as $k) {
                    if (! empty($p[$k]) && is_array($p[$k])) {
                        $this->regler[$scope][$k] = array_values($p[$k]);
                    }
                }
                if ($frischeSlugs !== []) {
                    $this->regler[$scope]['frische'] = $frischeSlugs;
                }
                if ($bioPraef !== null) {
                    $this->regler[$scope]['bio_praeferenz'] = $bioPraef;
                }
                if (array_key_exists('use_favorites_list', $p)) {
                    $this->regler[$scope]['favoriten'] = (bool) $p['use_favorites_list'];
                    $this->regler[$scope]['favoriten_conv_only'] = (bool) ($p['favorites_convenience_only'] ?? false);
                }
                if (array_key_exists('ki_bilder', $p)) {
                    $this->regler[$scope]['ki_bilder'] = (bool) $p['ki_bilder'];
                }
                // Menü-Gänge/Quoten als Zahl-String zurückschreiben (der Parser liest sie wieder als Zahl).
                foreach (['menue_gaenge' => 'menue_gaenge', 'menue_quote_vegan_pct' => 'menue_quote_vegan',
                    'menue_quote_vegetarisch_pct' => 'menue_quote_vegetarisch'] as $pk => $uiKey) {
                    if (($p[$pk] ?? null) !== null && $p[$pk] !== '') {
                        $this->regler[$scope][$uiKey] = (string) $p[$pk];
                    }
                }
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[Planung] Regler-Rehydrierung übersprungen', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Trend-Herkunft → Go-Briefing: den Brief/Titel einer Trend-Session in alle Tab-Briefings
     * ({@see SCOPES}) übertragen. **Nur befüllen, nie überschreiben** — ein bereits (vom Nutzer
     * oder einer Vorlage) getipptes Tab-Briefing bleibt unangetastet. Der Session-Brief ist bewusst
     * scope-agnostisch formuliert (die Session hat keine Ebene); beim Übertragen wird der Lead je Tab
     * ebenen-spezifisch geschärft ({@see PlanningSessionService::briefFuerScope} — Basisrezept/Gericht/
     * Konzept), Einordnung/Kernaussage bleiben unverändert. Kein „Go" — nur Prefill (Nordstern: nichts
     * läuft still).
     */
    private function seedBriefingAusTrendSession(FoodAlchemistPlanningSession $session): void
    {
        $brief = trim((string) $session->brief);
        $titel = trim((string) $session->title);
        if ($brief === '') {
            return;
        }
        foreach (self::SCOPES as $scope) {
            if (trim((string) ($this->eingabe[$scope]['brief'] ?? '')) === '') {
                $this->eingabe[$scope]['brief'] = PlanningSessionService::briefFuerScope($brief, $scope);
            }
            if ($titel !== '' && trim((string) ($this->eingabe[$scope]['titel'] ?? '')) === '') {
                $this->eingabe[$scope]['titel'] = $titel;
            }
        }
    }

    public function speichern(PlanningSessionService $svc): void
    {
        $team = $this->team();
        $session = $this->aktiveSession();
        if ($team === null || $session === null) {
            return;
        }
        $svc->update($team, $session->id, [
            'title' => $this->form['title'] ?? '',
            'brief' => $this->form['brief'] ?? null,
            'analysis' => $this->form['analysis'] ?? null,
        ]);
        if (in_array($this->form['creative_mode'] ?? null, FoodAlchemistPlanningSession::CREATIVE_MODES, true)) {
            $svc->setCreativeMode($team, $session->id, $this->form['creative_mode']);
        }
        $this->meldung = 'Gespeichert.';
    }

    // ── Skizzen (Divergenz-Board) ──────────────────────────────────────

    public function ideeHinzu(IdeenService $svc): void
    {
        $team = $this->team();
        $session = $this->aktiveSession();
        if ($team === null || $session === null || trim($this->ideeTitel) === '') {
            return;
        }
        try {
            $svc->add($team, ['planning_session_id' => $session->id, 'title' => $this->ideeTitel, 'created_via' => 'ui']);
            $this->ideeTitel = '';
            $this->fehler = null;
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function ideeVerwerfen(int $id, IdeenService $svc): void
    {
        $team = $this->team();
        if ($team === null) {
            return;
        }
        try {
            $svc->setStatus($team, $id, 'verworfen');
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function paketBilden(IdeenService $svc): void
    {
        $team = $this->team();
        $session = $this->aktiveSession();
        if ($team === null || $session === null || trim($this->paketName) === '') {
            return;
        }
        try {
            $svc->addGruppe($team, ['planning_session_id' => $session->id, 'name' => $this->paketName]);
            $this->paketName = '';
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
    }

    /**
     * Skizzen-Integration (Etappe 4) — Skizze als Kaskaden-EINGANG (erster Teilschritt): statt die
     * Skizze (wie der Foodbook-Pfad {@see MaterializeIdeaJob}) direkt zu materialisieren, überträgt
     * diese Aktion sie in den Gericht-Tab (Titel → Titel, Beschreibung → Brief). Der Mensch prüft
     * dort die Leitplanken und drückt „Go" ({@see goKaskade}) — der geführte, voll getestete
     * Kaskaden-Pfad, ohne neue Fan-out-Verdrahtung/Migration. Erfüllt den Nordstern „nichts läuft
     * still" (geführte Freigabe je Stufe).
     *
     * Scope-Entscheid: eine Divergenz-Board-Skizze IST eine Gericht-Idee → Gericht-Tab (nicht
     * Basisrezept/Concept). Die Skizze bleibt `entwurf` — das Prefill verbraucht sie NICHT (erst der
     * Go erzeugt etwas); der Mensch kann sie danach manuell verwerfen. Den Tab-Wechsel macht das
     * Blade (Alpine `tab='gericht'`), damit der Mensch direkt bei den Leitplanken landet.
     */
    public function skizzeAlsGericht(int $ideaId): void
    {
        $team = $this->team();
        $session = $this->aktiveSession();
        if ($team === null || $session === null) {
            return;
        }
        // Team-scoped + an die AKTIVE Session gebunden + nicht verworfen — eine fremde/gelöschte/
        // Papierkorb-Skizze wird nicht übernommen (gesagt, nicht still verschluckt).
        $idee = FoodAlchemistDishIdea::visibleToTeam($team)
            ->where('planning_session_id', $session->id)
            ->where('status', '!=', 'verworfen')
            ->whereKey($ideaId)
            ->first();
        if ($idee === null) {
            $this->fehler = 'Skizze nicht gefunden (oder verworfen) — bitte neu wählen.';

            return;
        }
        $this->eingabe['gericht']['titel'] = (string) $idee->title;
        $this->eingabe['gericht']['brief'] = (string) ($idee->description ?? '');
        // Lineage (Teil 2a): die Skizze für den nächsten Gericht-Go merken — der Lauf wird auf sie
        // zurückgestempelt (origin_dish_idea_id), damit die Karte später den Stand zeigen kann.
        $this->skizzeGerichtId = (int) $idee->id;
        $this->fehler = null;
        $this->meldung = 'Skizze in den Gericht-Tab übernommen — Leitplanken prüfen, dann „Go".';
    }

    /**
     * Skizzen-Integration (Etappe 4, Teil 3) — KI-Divergenz-Skizzen als BATCH-Kaskaden-Eingang:
     * statt jede Skizze einzeln in den Gericht-Tab zu übertragen (Teil 1, {@see skizzeAlsGericht}),
     * startet dieser Knopf für ALLE bearbeitbaren Session-Skizzen auf einmal je einen GESTUFTEN
     * Gericht-Lauf (staged → hält bei „prüfen" an; geführte Freigabe je Stück — Nordstern „nichts
     * läuft still"). Reust den voll getesteten `starteKaskade('gericht', …)`-Pfad je Skizze (kein
     * neuer Fan-out-Code) und stempelt jeden Lauf auf seine Ursprungs-Skizze (origin_dish_idea_id)
     * → die Karte zeigt den Stand (Teil 2b).
     *
     * Auswahl: alle Session-Skizzen (Einzel + Gruppen), die (a) nicht `verworfen` sind und (b) KEIN
     * Bestands-Gericht referenzieren (`sales_recipe_id` = Reuse-Zeiger, kein Generierungs-Brief). Ist
     * {@see $skizzenAuswahl} NICHT leer (Etappe 4, Teil 3b — Checkboxen je Karte), wird zusätzlich auf
     * GENAU diese IDs gefiltert (Schnittmenge mit den bearbeitbaren) — sonst „alle". Die Gericht-Tab-
     * Leitplanken (reglerParams) gelten für den ganzen Batch (Start-Tab-Regel). Cap
     * {@see BATCH_SKIZZEN_MAX} als Runaway-/Kosten-Guard — darüber wird gedeckelt und gesagt.
     *
     * KEIN Cockpit-Hijack: der Batch feuert N gestufte Läufe; ihr Stand erscheint je Skizzen-Karte
     * (Teil 2b), nicht im Einzel-Cockpit ($laufId/$laeuft bleiben unangetastet).
     */
    public function skizzenBatchAlsGerichte(PlanningCascadeService $cascade, PlanningSessionService $svc): void
    {
        $team = $this->team();
        $session = $this->aktiveSession();
        if ($team === null || $session === null) {
            return;
        }
        // Bearbeitbare Session-Skizzen: nicht verworfen + kein Bestands-Zeiger (das wäre Reuse eines
        // echten Gerichts, kein Brief zum Generieren). Reihenfolge = Board-Reihenfolge.
        $query = FoodAlchemistDishIdea::visibleToTeam($team)
            ->where('planning_session_id', $session->id)
            ->where('status', '!=', 'verworfen')
            ->whereNull('sales_recipe_id')
            ->orderBy('position')->orderBy('id');
        // Gezielte Auswahl (Teil 3b): nur die angehakten Skizzen — Strings aus Livewire nach int
        // normalisieren, leere/ungültige Einträge raus. Leere Auswahl = „alle" (Bestandsverhalten).
        $gewaehlt = collect($this->skizzenAuswahl)
            ->map(fn ($v) => (int) $v)->filter()->unique()->values();
        $mitAuswahl = $gewaehlt->isNotEmpty();
        if ($mitAuswahl) {
            $query->whereIn('id', $gewaehlt->all());
        }
        $kandidaten = $query->get();
        if ($kandidaten->isEmpty()) {
            $this->fehler = $mitAuswahl
                ? 'Keine der angehakten Skizzen ist startbar (verworfen oder Bestands-Übernahme) — Auswahl prüfen.'
                : 'Keine bearbeitbaren Skizzen — leg oben eine an (Bestands-Übernahmen zählen nicht).';

            return;
        }
        // Start-Tab-Regel: die Gericht-Tab-Leitplanken gelten für den ganzen Batch. Ein Ziel-VK-
        // Tippfehler wird GESAGT (nicht still verworfen) — sonst startet ein ganzer Batch mit falscher
        // Preis-Absicht (Spiegel goKaskade/VkGeneratorModal).
        if (trim((string) ($this->regler['gericht']['ziel_vk'] ?? '')) !== '' && $this->zielVkEur('gericht') === null) {
            $this->fehler = 'Ziel-VK: bitte einen Netto-Preis je Portion zwischen 0,50 € und 500,00 € angeben (z. B. 8,50) — oder das Feld leer lassen.';

            return;
        }

        $gedeckelt = $kandidaten->count() > self::BATCH_SKIZZEN_MAX;
        $auswahl = $kandidaten->take(self::BATCH_SKIZZEN_MAX);

        $params = $this->reglerParams('gericht');
        $creativeMode = (string) ($this->eingabe['gericht']['creative_mode'] ?? 'voll_kreativ');
        $vollAnreichern = (bool) ($this->regler['gericht']['voll_anreichern'] ?? false);
        // Fan-out-Vererbung wie beim Einzel-Go (fail-soft — kippt sie, darf der Batch NICHT sterben).
        try {
            $svc->setGenerationParams($team, $session->id, $params);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[Planung] Batch setGenerationParams übersprungen', ['error' => $e->getMessage()]);
        }

        $gestartet = 0;
        foreach ($auswahl as $idee) {
            $titel = trim((string) $idee->title);
            $besch = trim((string) ($idee->description ?? ''));
            // Brief wie effektiverBrief('gericht'): Titel — Beschreibung, sonst was da ist.
            $brief = ($titel !== '' && $besch !== '') ? $titel . ' — ' . $besch : ($besch !== '' ? $besch : $titel);
            if ($brief === '') {
                continue;   // titellose Skizze (Pflichtfeld sollte das verhindern) — kein Leer-Lauf
            }
            try {
                $cascade->starteKaskade($team, 'gericht', $session, $creativeMode, [
                    'created_via' => 'plan_batch_skizze',
                    'brief' => $brief,
                    'params' => $params,
                    'voll_anreichern' => $vollAnreichern,
                    'origin_dish_idea_id' => (int) $idee->id,
                ]);
                $gestartet++;
            } catch (\Throwable $e) {
                // Ein einzelner Fehlstart kippt den Batch NICHT — die restlichen Skizzen laufen weiter.
                \Illuminate\Support\Facades\Log::warning('[Planung] Batch-Skizze übersprungen', ['idea_id' => $idee->id, 'error' => $e->getMessage()]);
            }
        }

        if ($gestartet === 0) {
            $this->fehler = 'Keine Skizze konnte gestartet werden — bitte Leitplanken/Brief prüfen.';

            return;
        }
        // Auswahl ist verbraucht — der nächste Klick ist wieder „alle" (sofern nicht neu angehakt).
        $this->skizzenAuswahl = [];
        $this->fehler = null;
        $this->meldung = $gedeckelt
            ? "{$gestartet} Skizzen als Gerichte gestartet (auf ".self::BATCH_SKIZZEN_MAX.' gedeckelt) — Stand je Karte, dann prüfen/freigeben.'
            : "{$gestartet} Skizzen als Gerichte gestartet — Stand je Karte, dann prüfen/freigeben.";
    }

    /** Hebt die gezielte Skizzen-Auswahl (Teil 3b) auf → nächster Batch-Klick startet wieder „alle". */
    public function skizzenAuswahlLeeren(): void
    {
        $this->skizzenAuswahl = [];
    }

    // ── Leitstelle: Regler-Bedienung ───────────────────────────────────

    /** Multi-Select-Regler (Toggle in/aus der Liste): Diät + Frische-Erlaubnis + Allergen-No-Go. */
    private const MULTI_REGLER = ['diaet_hart', 'frische', 'allergen_nogo'];

    /**
     * EU-14-Allergen-No-Go (L3): Key => Label. Harter Ausschluss (getrennt vom Diät-Ausschluss) —
     * ein verdrahteter GP, der ein angehaktes Allergen »enthält«, wird im Diät-/Allergen-Gate
     * entdrahtet + gemeldet. Keys spiegeln FoodAlchemistGp::ALLERGEN_FIELDS 1:1.
     */
    public const ALLERGEN_LABELS = [
        'gluten' => 'Gluten', 'crustaceans' => 'Krebstiere', 'eggs' => 'Eier', 'fish' => 'Fisch',
        'peanuts' => 'Erdnüsse', 'soy' => 'Soja', 'milk' => 'Milch', 'tree_nuts' => 'Schalenfrüchte',
        'celery' => 'Sellerie', 'mustard' => 'Senf', 'sesame' => 'Sesam', 'sulphites' => 'Sulfite',
        'lupin' => 'Lupine', 'molluscs' => 'Weichtiere',
    ];

    /** Pill-Toggle für die Richtungs-Regler EINES Scopes (MULTI_REGLER togglen, sonst Single-Set). */
    public function reglerPill(string $scope, string $feld, string $wert): void
    {
        if (! isset($this->regler[$scope])) {
            return;
        }
        if (in_array($feld, self::MULTI_REGLER, true)) {
            $cur = (array) ($this->regler[$scope][$feld] ?? []);
            $this->regler[$scope][$feld] = in_array($wert, $cur, true)
                ? array_values(array_diff($cur, [$wert]))
                : [...$cur, $wert];

            return;
        }
        if (array_key_exists($feld, $this->regler[$scope])) {
            $this->regler[$scope][$feld] = $wert;
        }
    }

    /**
     * Brief-Vorlagen, die für den gegebenen Creation-Tab gelten (Schnellstart statt Blank Page,
     * Etappe 4). Die Blade blendet den Vorlagen-Block nur ein, wenn die Liste nicht leer ist —
     * so trägt der Basisrezept-Tab (Teil 1: keine Vorlagen) keine leere Auswahl.
     *
     * @return array<string,array<string,mixed>>
     */
    public function vorlagenFuer(string $scope): array
    {
        $team = $this->team();

        return $team ? app(\Platform\FoodAlchemist\Services\BriefTemplateService::class)->fuer($team, $scope) : [];
    }

    /**
     * Lädt eine Brief-Vorlage in den Start-Tab (Schnellstart statt Blank Page, Etappe 4). Füllt das
     * Briefing bewusst (Starter — die leere Seite soll weg) und setzt Sektor/Anlass/Serviceform als
     * Kontext-Vorschlag; der Titel wird NUR gesetzt, wenn der Nutzer noch keinen getippt hat (nicht
     * überschreiben). Alles bleibt frei anpassbar. Kein Go — der Mensch prüft und drückt selbst.
     */
    public function briefVorlage(string $scope, string $key): void
    {
        if (! isset($this->eingabe[$scope]) || ! isset($this->regler[$scope])) {
            return;
        }
        $team = $this->team();
        $tpl = $team ? app(\Platform\FoodAlchemist\Services\BriefTemplateService::class)->lade($team, (int) $key, $scope) : null;
        if ($tpl === null) {
            $this->fehler = 'Unbekannte oder für diesen Tab ungültige Vorlage.';

            return;
        }
        $payload = is_array($tpl->payload) ? $tpl->payload : [];
        $this->eingabe[$scope]['brief'] = (string) $tpl->brief;
        if (trim((string) ($this->eingabe[$scope]['titel'] ?? '')) === '' && trim((string) $tpl->titel) !== '') {
            $this->eingabe[$scope]['titel'] = (string) $tpl->titel;
        }
        if (! empty($payload['creative_mode']) && array_key_exists('creative_mode', $this->eingabe[$scope])) {
            $this->eingabe[$scope]['creative_mode'] = (string) $payload['creative_mode'];
        }
        // Leitplanken-Snapshot anwenden — NUR Keys, die der Ziel-Regler-Satz dieses Scopes führt
        // (kein Fremd-Key-Erbe; z. B. VK-/Menü-Achsen eines Gericht-Snapshots landen nicht im Basisrezept).
        foreach (($payload['regler'] ?? []) as $feld => $wert) {
            if (array_key_exists($feld, $this->regler[$scope])) {
                $this->regler[$scope][$feld] = $wert;
            }
        }
        $this->aktiveVorlage[$scope] = (string) $key;   // Chip-Highlight: sichtbar, WELCHE Vorlage geladen wurde
        $this->fehler = null;
        $this->meldung = 'Vorlage „'.$tpl->label.'" geladen — Briefing, Kreativ-Modus und Leitplanken gesetzt, bitte prüfen und anpassen.';
    }

    /**
     * Eine MANUELLE Brief-Änderung hebt die Schnellstart-Markierung des Scopes auf — die Vorlage war
     * nur Startpunkt, ab hier ist es ein eigenes Briefing (ein stehender Chip-Highlight wäre irreführend).
     * Feuert NICHT beim programmatischen Setzen in {@see briefVorlage()} (nur client-originierte Updates).
     */
    public function updated(string $name): void
    {
        if (preg_match('#^eingabe\.([^.]+)\.brief$#', $name, $m)) {
            unset($this->aktiveVorlage[$m[1]]);
        }
    }

    /**
     * „Als Vorlage speichern": nimmt den AKTUELLEN Tab-Stand (Brief + Kreativ-Modus + kompletter
     * Leitplanken-Satz) als benannte, team-eigene Schnellstart-Vorlage auf — genau dort, wo die Regler
     * schon eingestellt sind (kein nachgebautes Formular). Erscheint danach als Chip auf DIESEM Scope.
     */
    public function alsVorlageSpeichern(string $scope): void
    {
        $team = $this->team();
        if ($team === null || ! isset($this->eingabe[$scope]) || ! isset($this->regler[$scope])) {
            return;
        }
        try {
            $tpl = app(\Platform\FoodAlchemist\Services\BriefTemplateService::class)->speichere(
                $team, $scope, $this->vorlageName, (string) ($this->eingabe[$scope]['brief'] ?? ''),
                $this->regler[$scope],
                $this->eingabe[$scope]['titel'] ?? null,
                $this->eingabe[$scope]['creative_mode'] ?? null,
                Auth::id(),
            );
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        $this->vorlageName = '';
        $this->aktiveVorlage[$scope] = (string) $tpl->id;   // die gerade gespeicherte ist aktiv markiert
        $this->fehler = null;
        $this->meldung = 'Vorlage „'.$tpl->label.'" gespeichert — steht ab jetzt als Schnellstart bereit.';
    }

    /** Eine team-EIGENE Vorlage löschen (Globals sind read-only → Service wirft). Inline aus dem Chip. */
    public function loeschenVorlage(string $scope, int $id): void
    {
        $team = $this->team();
        if ($team === null) {
            return;
        }
        try {
            app(\Platform\FoodAlchemist\Services\BriefTemplateService::class)->loeschen($team, $id);
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        if (($this->aktiveVorlage[$scope] ?? null) === (string) $id) {
            unset($this->aktiveVorlage[$scope]);
        }
        $this->fehler = null;
        $this->meldung = 'Vorlage gelöscht.';
    }

    /**
     * Et.4 (Eingabe-Reife) »Titel-/Namensvorschlag aus dem Brief« — Teil 3 (UI). Schlägt aus dem
     * Tab-Briefing einen nüchternen, §-konformen Titel vor ({@see TitelVorschlagService}, Teil 2 —
     * `recipe.titel_vorschlag` §1 bzw. `vk.titel_vorschlag` §4.4-Pipe) und füllt ihn ins Tab-Titelfeld
     * — **nur wenn dort noch keiner steht** (empty-only, Muster {@see briefVorlage}/{@see skizzeAlsGericht});
     * ein bereits getippter Titel bleibt unangetastet. Kein Go — reiner Vorschlag, der Mensch prüft.
     *
     * `concept` ist bewusst außen vor (dessen `name_claim` liefert der kreative KI-Kopf, nicht der
     * nüchterne Titel-Prompt) — die Blade blendet den Knopf nur im Basisrezept-/Gericht-Tab ein; der
     * Scope-Guard hier fängt einen Fehl-Aufruf fail-soft ab.
     */
    public function titelVorschlagen(string $scope, TitelVorschlagService $svc): void
    {
        if (! in_array($scope, self::SCOPES, true) || ! isset($this->eingabe[$scope])) {
            return;
        }
        // Empty-only: ein bereits gesetzter Titel wird NIE überschrieben.
        if (trim((string) ($this->eingabe[$scope]['titel'] ?? '')) !== '') {
            $this->fehler = null;
            $this->meldung = 'Es steht bereits ein Titel — Vorschlag übersprungen (nicht überschrieben).';

            return;
        }
        $brief = trim((string) ($this->eingabe[$scope]['brief'] ?? ''));
        if ($brief === '') {
            $this->fehler = 'Für den Titelvorschlag erst ein Briefing im Tab eingeben.';

            return;
        }
        $vorschlag = $svc->titelVorschlag($scope, $brief);
        if ($vorschlag === null) {
            // concept (kein nüchterner Titel) ODER KI weg/leeres Ergebnis — fail-soft, kein Titel gefüllt.
            $this->fehler = $scope === 'concept'
                ? 'Für Concepts liefert der KI-Kopf den Namen — der Titelvorschlag gilt nur für Basisrezept/Gericht.'
                : 'Kein Titelvorschlag möglich — bitte das Briefing schärfen oder manuell benennen.';

            return;
        }
        $this->eingabe[$scope]['titel'] = $vorschlag;
        $this->fehler = null;
        $this->meldung = 'Titel vorgeschlagen: „'.$vorschlag.'" — bitte prüfen und anpassen.';
    }

    /**
     * Regler → Richtungs-Param-Bündel — spiegelt EXAKT die Param-Logik der abgelösten
     * Rich-Modals: bio-Bool aus bio_praeferenz, Leer-Hints strippen (diaet_hart-Array +
     * Bools bleiben), Favoriten opt-in, VK-Achsen + Ziel-VK nur bei $vk. Wird am Go an
     * die Kaskade UND (für den Fan-out) an generation_params gereicht.
     *
     * @return array<string,mixed>
     */
    private function reglerParams(string $scope): array
    {
        $r = $this->regler[$scope] ?? self::REGLER_DEFAULT;
        $vk = $scope !== 'rezept';                              // Basisrezept: keine VK-Achsen
        $menue = $scope === 'concept';                          // Menü-Leitplanken nur am Concept-Tab
        // Extra-Steuerwerte gesondert (sie werden übersetzt, nicht 1:1 durchgereicht).
        $favoriten = (bool) ($r['favoriten'] ?? false);
        $favConvOnly = (bool) ($r['favoriten_conv_only'] ?? false);
        $kiBilder = (bool) ($r['ki_bilder'] ?? false);
        $p = $r;
        // Bio dreiwertig weiterreichen (bio|conventional|neutral) — der Generator/Matcher kennt einen
        // NEUTRALEN Arm (Adjustment 0). Ohne ihn fiel „egal" auf das Bool false → 'conventional' → Bio-GPs
        // wurden aktiv mit −2 bestraft (Bug). Der Bool `bio` bleibt für den MCP-Pfad rückwärtskompatibel.
        $p['bio_pref'] = match ($r['bio_praeferenz'] ?? '') {
            'bio' => 'bio',
            'egal' => 'neutral',
            default => 'conventional',
        };
        $p['bio'] = ($r['bio_praeferenz'] ?? '') === 'bio';
        // Reuse-Achse: EINE Wahrheit ist der Kreativ-Modus (Select im Eingabe-Block). Der frühere
        // Doppel-Regler »Bestand-Nutzung« (Chips) ist entfernt; `bestand` wird hier deterministisch
        // aus dem Modus abgeleitet und reist über den bestehenden params/generation_params-Kanal in
        // Generator + Fan-out (datenbank = nur Bestand, hybrid = Bestand zuerst, voll_kreativ = neu).
        $p['bestand'] = match ((string) ($this->eingabe[$scope]['creative_mode'] ?? 'voll_kreativ')) {
            'datenbank' => 'nur_bestand',
            'hybrid' => 'hybrid',
            default => 'komplett_neu',   // voll_kreativ
        };
        // Frische (L1.5): Multi-Select Erlaubnis-Liste → harte gps.condition-Erlaubnis + primärer Pref
        // (Tiebreak). [] = egal (Key entfällt, kein Filter). Sonst: erlaubte Roh-Zustände + 'frisch'-Vorzug.
        $frischeSlugs = array_values(array_filter(
            (array) ($r['frische'] ?? []),
            static fn ($s) => isset(self::FRISCHE_CONDITION[$s]),
        ));
        unset($p['frische']);   // Roh-Array wird übersetzt, nicht 1:1 durchgereicht
        if ($frischeSlugs !== []) {
            $p['frische_erlaubt'] = array_map(static fn ($s) => self::FRISCHE_CONDITION[$s], $frischeSlugs);
            // Primärer Pref für den Match-Tiebreak (Generator mappt frisch|tk|konserve→VariantPref;
            // 'trocken' fällt auf fresh_first, egal — der harte Filter erledigt die Zustands-Auswahl).
            $p['frische'] = in_array('frisch', $frischeSlugs, true) ? 'frisch' : $frischeSlugs[0];
        }
        // L6 »Menge & Ziel«: Zahl-Achsen mit Band-Guard (ungültig/leer → Key entfällt = keine Vorgabe),
        // Saison als Enum-Durchreichung. Reine KI-Vorgaben (Prompt); deterministische Erzwingung Follow-up.
        unset($p['pax'], $p['ziel_portion_g'], $p['ziel_we_pct']);   // Roh-Felder → geparst durchreichen
        if (($pax = $this->intRegler($r['pax'] ?? '', 1, 100000)) !== null) {
            $p['pax'] = $pax;
        }
        if (($portion = $this->intRegler($r['ziel_portion_g'] ?? '', 1, 5000)) !== null) {
            $p['ziel_portion_g'] = $portion;
        }
        if (($wePct = $this->intRegler($r['ziel_we_pct'] ?? '', 1, 100)) !== null) {
            $p['ziel_we_pct'] = $wePct;
        }
        // Basisrezept-Ziel (scope=rezept): Menge (Dezimal erlaubt) + Einheit statt Pax/Portion — nur
        // zusammen und mit gültiger Einheit durchreichen (reiner KI-Hint, whitelist gegen MENGE_EINHEITEN).
        unset($p['ziel_menge'], $p['ziel_einheit']);
        $mengeRaw = str_replace(',', '.', trim((string) ($r['ziel_menge'] ?? '')));
        if ($mengeRaw !== '' && is_numeric($mengeRaw) && (float) $mengeRaw > 0
            && ($r['ziel_einheit'] ?? '') !== '' && isset(self::MENGE_EINHEITEN[$r['ziel_einheit']])) {
            $p['ziel_menge'] = (float) $mengeRaw;
            $p['ziel_einheit'] = (string) $r['ziel_einheit'];
        }
        if (! isset(self::SAISON_OPTIONEN[$p['saison'] ?? '']) || ($p['saison'] ?? '') === '') {
            unset($p['saison']);   // leer/unbekannt = keine Saison-Vorgabe
        }
        if (! $vk) {
            unset($p['occasion'], $p['serviceform'], $p['kompositions_stil']);
        }
        // Roh-Felder raus, die übersetzt (nicht 1:1) durchgereicht werden — inkl. der Menü-Roh-Eingaben
        // (sie werden unten als kanonische _pp-Keys geparst und nur für den Concept-Scope gesetzt).
        unset($p['favoriten'], $p['favoriten_conv_only'], $p['ki_bilder'], $p['ziel_vk'], $p['voll_anreichern'],
            $p['menue_typ'], $p['menue_gaenge'], $p['menue_preis_min'], $p['menue_preis_ziel'], $p['menue_preis_max'],
            $p['menue_quote_vegan'], $p['menue_quote_vegetarisch'], $p['menue_balance']);
        $p = array_filter($p, fn ($v) => $v !== '' && $v !== null && $v !== []);
        $p['use_favorites_list'] = $favoriten;
        $p['favorites_convenience_only'] = $favoriten && $favConvOnly;
        $p['ki_bilder'] = $kiBilder;   // Preisfrage: KI-Fotos bei Anreicherung ja/nein
        if ($vk && ($ziel = $this->zielVkEur($scope)) !== null) {
            $p['ziel_vk_eur'] = $ziel;
        }
        if ($menue) {
            // Concept-Typ (#35): nur 'buffet' fließt als Signal ein — Menü/leer lässt den Key weg
            // (byte-identisch zum bisherigen Verhalten). Steuert Slot-Typ (station) + Gänge-Cap.
            if (($typ = $this->menueTyp($scope)) !== null) {
                $p['menue_typ'] = $typ;
            }
            // Menü-Leitplanken (Zusammenstellung): Anzahl Gänge + Zielpreis-Korridor je Person.
            // Nur gültige Werte fließen ein (ungültige/leer = keine Vorgabe → kein Key, Prompt unverändert).
            if (($g = $this->menueGaenge($scope)) !== null) {
                $p['menue_gaenge'] = $g;
            }
            if (($mn = $this->menuePreisEur($scope, 'menue_preis_min')) !== null) {
                $p['menue_preis_min_pp'] = $mn;
            }
            if (($mz = $this->menuePreisEur($scope, 'menue_preis_ziel')) !== null) {
                $p['menue_preis_ziel_pp'] = $mz;
            }
            if (($mx = $this->menuePreisEur($scope, 'menue_preis_max')) !== null) {
                $p['menue_preis_max_pp'] = $mx;
            }
            // Diät-Quoten (Portfolio-Anteil, weich): mind. X % der Positionen vegan/vegetarisch.
            if (($qv = $this->menueQuote($scope, 'menue_quote_vegan')) !== null) {
                $p['menue_quote_vegan_pct'] = $qv;
            }
            if (($qg = $this->menueQuote($scope, 'menue_quote_vegetarisch')) !== null) {
                $p['menue_quote_vegetarisch_pct'] = $qg;
            }
            // Portfolio-Balance (Menü-Vielfalt, weich): nur ein erlaubter Enum-Wert fliesst ein.
            if (($bal = $this->menueBalance($scope)) !== null) {
                $p['menue_balance'] = $bal;
            }
        }

        return $p;
    }

    /**
     * Menü-Leitplanken (nur Concept) auf ihre kanonischen menue_*-Keys reduziert — dieselbe Teilmenge,
     * die {@see PlanningCascadeService::dispatchConceptStep} an die Konzept-Erzeugung reicht. Damit
     * bekommen BEIDE Concept-Wege (Schnell-Go über den Job UND KI-Kopf inline) exakt denselben
     * Leitplanken-Satz. Leer für nicht-Concept-Scopes (keine Menü-Achsen).
     *
     * @return array<string,mixed>
     */
    private function menueAchsenFuer(string $scope): array
    {
        if ($scope !== 'concept') {
            return [];
        }

        return array_filter(
            $this->reglerParams($scope),
            static fn ($k) => str_starts_with((string) $k, 'menue_'),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * Prüft die Concept-Menü-Leitplanken auf mistgetippte Zahlen und liefert eine Klartext-Fehlermeldung
     * (oder null, wenn alles gültig/leer ist). Ein Mensch, der 45,x statt 45 meinte, wird GESAGT statt
     * still verworfen — derselbe Grundsatz wie bei Ziel-VK. Geteilt von {@see goKaskade} und {@see kiKopf},
     * damit der KI-Kopf-Pfad nicht mit einer stillschweigend verworfenen Vorgabe generiert.
     */
    private function menueLeitplankenFehler(string $scope): ?string
    {
        if ($scope !== 'concept') {
            return null;
        }
        foreach (['menue_preis_min' => 'Preis-Untergrenze', 'menue_preis_ziel' => 'Zielpreis', 'menue_preis_max' => 'Preis-Obergrenze'] as $feld => $lbl) {
            if (trim((string) ($this->regler[$scope][$feld] ?? '')) !== '' && $this->menuePreisEur($scope, $feld) === null) {
                return "Menü-{$lbl}: bitte einen Netto-Preis je Person zwischen 0,50 € und 2.000,00 € angeben (z. B. 45,00) — oder das Feld leer lassen.";
            }
        }
        if (trim((string) ($this->regler[$scope]['menue_gaenge'] ?? '')) !== '' && $this->menueGaenge($scope) === null) {
            return 'Menü-Gänge: bitte eine ganze Zahl zwischen 1 und 20 angeben (z. B. 4) — oder das Feld leer lassen.';
        }
        foreach (['menue_quote_vegan' => 'Vegan-Anteil', 'menue_quote_vegetarisch' => 'Vegetarisch-Anteil'] as $feld => $lbl) {
            if (trim((string) ($this->regler[$scope][$feld] ?? '')) !== '' && $this->menueQuote($scope, $feld) === null) {
                return "Menü-{$lbl}: bitte einen Prozentwert zwischen 0 und 100 angeben (z. B. 30) — oder das Feld leer lassen.";
            }
        }

        return null;
    }

    /** L6: ganze Zahl im Band [min,max] aus einem Roh-Regler, sonst null (leer/ungültig = keine Vorgabe). */
    private function intRegler(mixed $roh, int $min, int $max): ?int
    {
        $s = trim((string) $roh);
        if ($s === '' || ! ctype_digit($s)) {
            return null;
        }
        $n = (int) $s;

        return ($n >= $min && $n <= $max) ? $n : null;
    }

    /** Menü-Gänge/Positionen: ganze Zahl 1–20, sonst null (leer/ungültig = keine Vorgabe). Concept-Scope. */
    private function menueGaenge(string $scope): ?int
    {
        $roh = trim((string) ($this->regler[$scope]['menue_gaenge'] ?? ''));
        if ($roh === '' || ! ctype_digit($roh)) {
            return null;
        }
        $n = (int) $roh;

        return $n >= 1 && $n <= 20 ? $n : null;
    }

    /** Diät-Quote (Portfolio-Anteil): ganze Prozentzahl 0–100, sonst null (leer/ungültig = keine Vorgabe). Concept-Scope. */
    private function menueQuote(string $scope, string $feld): ?int
    {
        $roh = str_replace(['%', ' '], '', trim((string) ($this->regler[$scope][$feld] ?? '')));
        if ($roh === '' || ! ctype_digit($roh)) {
            return null;
        }
        $n = (int) $roh;

        return $n >= 0 && $n <= 100 ? $n : null;
    }

    /** Portfolio-Balance (Menü-Vielfalt): erlaubter {@see MENUE_BALANCE}-Enum-Wert, sonst null (leer/unbekannt = keine Vorgabe). Concept-Scope. */
    private function menueBalance(string $scope): ?string
    {
        $roh = trim((string) ($this->regler[$scope]['menue_balance'] ?? ''));

        return isset(self::MENUE_BALANCE[$roh]) ? $roh : null;
    }

    /**
     * Concept-Typ (#35): NUR 'buffet' ist ein Signal (station-Slots + eigene Positionen-Logik). Menü/leer
     * → null (kein Key → byte-identisch: gang-Slots wie bisher). Bewusst asymmetrisch, damit bestehende
     * Menü-Flows unverändert bleiben. Concept-Scope. {@see MENUE_TYPEN}
     */
    private function menueTyp(string $scope): ?string
    {
        $roh = trim((string) ($this->regler[$scope]['menue_typ'] ?? ''));

        return $roh === 'buffet' ? 'buffet' : null;
    }

    /** Menü-Preis je Person (min/ziel/max): „45,00 €" → 45.0; außerhalb 0,50–2.000,00 € → null. Analog {@see zielVkEur}. */
    private function menuePreisEur(string $scope, string $feld): ?float
    {
        $roh = str_replace([' ', '€'], '', trim((string) ($this->regler[$scope][$feld] ?? '')));
        if ($roh === '') {
            return null;
        }
        $roh = str_replace(',', '.', $roh);
        if (! is_numeric($roh)) {
            return null;
        }
        $eur = round((float) $roh, 2);

        return $eur >= 0.5 && $eur <= 2000.0 ? $eur : null;
    }

    /** „8,50 €" → 8.5; außerhalb 0,50–500,00 € → null (Aufrufer meldet). Spiegel VkGeneratorModal. Per Scope. */
    private function zielVkEur(string $scope): ?float
    {
        $roh = str_replace([' ', '€'], '', trim((string) ($this->regler[$scope]['ziel_vk'] ?? '')));
        if ($roh === '') {
            return null;
        }
        $roh = str_replace(',', '.', $roh);
        if (! is_numeric($roh)) {
            return null;
        }
        $eur = round((float) $roh, 2);

        return $eur >= 0.5 && $eur <= 500.0 ? $eur : null;
    }

    /**
     * Effektiver Brief für die Kaskade: Basisrezept/Gericht = „Titel — Beschreibung" (Titel = form.title,
     * Beschreibung = form.brief), Concept = das Briefing (form.brief). Platzhalter-Titel der Schnell-
     * Erstellung zählen nicht als Titel. Leer → starteKaskade fällt auf briefAusSession zurück.
     */
    private function effektiverBrief(string $scope): string
    {
        $titel = trim((string) ($this->eingabe[$scope]['titel'] ?? ''));
        $besch = trim((string) ($this->eingabe[$scope]['brief'] ?? ''));
        if ($scope === 'concept') {
            return $besch;   // das Briefing ist die ganze Concept-Eingabe
        }
        if ($titel !== '' && $besch !== '') {
            return $titel . ' — ' . $besch;
        }

        return $besch !== '' ? $besch : $titel;
    }

    /**
     * #1b Grounding-Preview: zeigt VOR dem Go, welches Wissen/Pairing/Template die Generierung für die
     * aktuellen Regler ziehen würde — OHNE zu generieren. Ruft denselben Kontext-Bau wie die Generierung
     * ({@see RecipeGenerationContextService::build}) und legt das `kontext`-Bündel für den Kontext-Inspektor
     * ab. Fail-soft: eine Preview darf den Editor nie brechen.
     */
    public function wissenVorschau(string $scope): void
    {
        $team = $this->team();
        if ($team === null || ! in_array($scope, self::SCOPES, true)) {
            return;
        }
        $brief = $this->effektiverBrief($scope);
        if ($brief === '') {
            $this->fehler = 'Für die Wissens-Vorschau erst Titel oder Beschreibung im Tab setzen.';

            return;
        }
        try {
            $ctx = app(\Platform\FoodAlchemist\Services\RecipeGenerationContextService::class)
                ->build($team, $brief, $this->reglerParams($scope), $scope !== 'rezept');
            $this->wissenVorschau = is_array($ctx['kontext'] ?? null) ? $ctx['kontext'] : null;
            $this->fehler = null;
        } catch (\Throwable $e) {
            $this->fehler = 'Wissens-Vorschau fehlgeschlagen: ' . $e->getMessage();
        }
    }

    // ── „KI-Kopf" — Concept-Plan vorab ausarbeiten (Etappe 2b, geplanter Pfad) ──

    /**
     * „KI-Kopf" (Etappe 2b): arbeitet aus dem Concept-Briefing einen vollständigen Plan-Entwurf aus
     * ({@see ConceptGeneratorService::planAusBrief} → Draft-Concept + Gerüst + kreative Canvas + LEERE
     * Fan-out-Slots) und öffnet den vollen inline-Conceptor direkt auf „Konzept & Planung" ({@see
     * Concepter\Editor::oeffnen} Start-Tab 'konzept') zur Prüfung/Korrektur. Startet KEINE Kaskade —
     * der geprüfte Plan geht später als `existing_concept_id` in den Go (nächster Chunk). Nur für den
     * Concept-Scope sinnvoll (ein Concept ist ein Menü, kein Einzelrezept).
     *
     * Fail-soft (Nordstern): ein Fehler (leerer Brief / KI aus) wird gesagt, nicht geschluckt — der
     * Absender ist ein Mensch, der korrigieren kann.
     */
    public function kiKopf(ConceptGeneratorService $svc, PlanningSessionService $sessions): void
    {
        $team = $this->team();
        $session = $this->aktiveSession();
        if ($team === null || $session === null) {
            $this->fehler = 'Kein Team/Session — KI-Kopf nicht möglich.';

            return;
        }
        $brief = $this->effektiverBrief('concept');
        if ($brief === '') {
            $this->fehler = 'Für den KI-Kopf erst ein Concept-Briefing eingeben.';

            return;
        }
        // Menü-Leitplanken zuerst validieren (wie beim Go) — der KI-Kopf darf nicht mit einer
        // stillschweigend verworfenen Zahl-Vorgabe generieren.
        if (($menueFehler = $this->menueLeitplankenFehler('concept')) !== null) {
            $this->fehler = $menueFehler;

            return;
        }
        // Concept-Briefing auf die Session spiegeln + persistieren (nicht verlieren) — wie beim Go.
        $this->form['brief'] = (string) ($this->eingabe['concept']['brief'] ?? '');
        $this->form['creative_mode'] = (string) ($this->eingabe['concept']['creative_mode'] ?? 'voll_kreativ');
        $this->speichern($sessions);

        $titel = trim((string) ($this->eingabe['concept']['titel'] ?? ''));
        try {
            // Menü-Leitplanken (Gänge/Preis-Korridor/Quoten/Balance/Buffet) an den Plan durchreichen —
            // sonst arbeitet der plan-first-Standard-Pfad ohne sie (Bug: nur der Schnell-Go über den Job
            // reichte sie bisher). Gleiche menue_*-Teilmenge wie dispatchConceptStep. $via bleibt 'ui'
            // (der created_via-Marker `concept_plan_ui` wird andernorts erwartet — nicht verändern).
            $plan = $svc->planAusBrief($team, $brief, [], $titel !== '' ? $titel : null, 'ui', $this->menueAchsenFuer('concept'));
        } catch (\Throwable $e) {
            $this->fehler = 'KI-Kopf fehlgeschlagen: ' . $e->getMessage();

            return;
        }
        $this->fehler = null;
        // Draft-ID merken: der nächste Concept-Go referenziert diesen geprüften Plan statt neu zu
        // generieren („Beide Pfade behalten", Etappe 2b). Verbraucht wird sie in goKaskade.
        $this->planConceptId = (int) $plan['concept']->id;
        // #53 persistent: an die Session schreiben, damit der geprüfte Plan einen Reload übersteht
        // (rehydriert in ladeForm). Fail-soft: kippt die Persistenz, bleibt der Plan wenigstens in der
        // laufenden Session (transiente Prop) nutzbar — ein Speicher-Fehler darf den KI-Kopf nicht kippen.
        try {
            $session->update(['plan_concept_id' => $this->planConceptId]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[Planung] plan_concept_id-Persistenz übersprungen (KI-Kopf bleibt für diese Session nutzbar) — evtl. Migration fehlt', ['error' => $e->getMessage()]);
        }
        $this->meldung = 'KI-Kopf: Plan ausgearbeitet — prüfe/korrigiere im Conceptor, dann „Go aus geprüftem Plan".';
        // Vollen inline-Conceptor direkt auf „Konzept & Planung" öffnen (Start-Tab 'konzept').
        $this->dispatch('concepter-editor.oeffnen', type: 'concepts', id: (int) $plan['concept']->id, startTab: 'konzept');
    }

    /**
     * Den vorbereiteten KI-Kopf-Plan verwerfen und zurück auf den Schnell-Pfad wechseln („Beide Pfade
     * behalten", Etappe 2b): nur die transiente Referenz wird gelöst — der Concept-Go generiert dann
     * wieder frisch aus dem Briefing. Das Draft-Concept selbst bleibt bestehen (löscht nichts).
     */
    public function planVerwerfen(): void
    {
        $this->planConceptId = null;
        // #53 persistent: auch den gespeicherten Zeiger lösen, sonst holt der Reload den verworfenen
        // Plan wieder (rehydrate in ladeForm). Das Draft-Concept selbst bleibt bestehen (löscht nichts).
        $session = $this->aktiveSession();
        if ($session !== null && $session->plan_concept_id !== null) {
            try {
                $session->update(['plan_concept_id' => null]);
            } catch (\Throwable) {
                // Persistenz-Fehler darf das Verwerfen nicht kippen — die Prop ist bereits null.
            }
        }
        $this->meldung = 'Vorbereiteter Plan verworfen — der Go generiert wieder frisch aus dem Briefing.';
        $this->fehler = null;
    }

    // ── „Go" — Tiefen-Leiter über den geteilten Kaskaden-Motor ─────────

    /**
     * Go → in-place Generierung über {@see PlanningCascadeService}. `$scope` = Einstiegs-Stufe
     * (`rezept`|`gericht`|`concept`). Sammelt die Richtungs-Regler (rezept/gericht), persistiert
     * sie als `generation_params` (Fan-out-Vererbung) und reicht sie als Lauf-`params` an den Motor.
     * Startet im Hintergrund; die Fläche pollt {@see pruefeLauf}. Kein Redirect.
     */
    public function goKaskade(string $scope, PlanningCascadeService $cascade, PlanningSessionService $svc): void
    {
        $team = $this->team();
        $session = $this->aktiveSession();
        if ($team === null || $session === null || ! in_array($scope, self::SCOPES, true)) {
            return;
        }
        $vk = $scope !== 'rezept';
        // Ziel-VK-Eingabe (nur wo VK) wird GESAGT statt still verworfen — der Absender ist ein
        // Mensch, der 8,5 statt 850 meinte, und kann korrigieren (L8b-2, Spiegel VkGeneratorModal).
        if ($vk && trim((string) ($this->regler[$scope]['ziel_vk'] ?? '')) !== '' && $this->zielVkEur($scope) === null) {
            $this->fehler = 'Ziel-VK: bitte einen Netto-Preis je Portion zwischen 0,50 € und 500,00 € angeben (z. B. 8,50) — oder das Feld leer lassen.';

            return;
        }
        // Menü-Leitplanken (nur Concept): eine mistgetippte Zahl wird GESAGT statt still verworfen —
        // derselbe Grundsatz wie bei Ziel-VK (der Absender ist ein Mensch, der korrigieren kann).
        if (($menueFehler = $this->menueLeitplankenFehler($scope)) !== null) {
            $this->fehler = $menueFehler;

            return;
        }
        // Kontext des Start-Tabs auf die Session spiegeln (Dashboard-Anzeige + creative_mode) und persistieren.
        $this->form['brief'] = (string) ($this->eingabe[$scope]['brief'] ?? '');
        $this->form['creative_mode'] = (string) ($this->eingabe[$scope]['creative_mode'] ?? 'voll_kreativ');
        $this->speichern($svc);
        $session = $this->aktiveSession();
        if ($session === null) {
            return;
        }
        // NUR der Start-Tab zählt: seine Leitplanken werden persistiert und propagieren die ganze
        // Kaskade nach unten (Start-Tab gilt für alles darunter — User-Entscheid 2026-08-14).
        $params = $this->reglerParams($scope);
        // FAIL-SOFT: die Regler fließen ohnehin über die Lauf-`params` in den Depth-1-Job — die
        // Session-Persistenz ist NUR für die Fan-out-Vererbung. Kippt sie, darf der Go NICHT sterben.
        try {
            $svc->setGenerationParams($team, $session->id, $params);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[Planung] setGenerationParams übersprungen (Fan-out-Vererbung aus) — evtl. Migration fehlt', ['error' => $e->getMessage()]);
        }
        // L5: Titel als Namens-Anker — getippten Titel getrennt vom Brief in die Lauf-Params geben
        // (NICHT in die persistierten Session-Params, sonst erbte JEDES Fan-out-Kind den Gericht-Titel
        // als Namen). Nur der Depth-1-Job sieht `titel_vorgabe`; der Generator nimmt ihn als Namen.
        $laufParams = $params;
        $titelVorgabe = trim((string) ($this->eingabe[$scope]['titel'] ?? ''));
        if ($titelVorgabe !== '' && $scope !== 'concept') {   // concept: Name kommt aus dem KI-Kopf (name_claim)
            $laufParams['titel_vorgabe'] = $titelVorgabe;
        }
        $optionen = [
            'created_via' => 'plan_go',
            'brief' => $this->effektiverBrief($scope),
            'params' => $laufParams,
            'voll_anreichern' => (bool) ($this->regler[$scope]['voll_anreichern'] ?? false),   // recipe-first: default AUS
        ];
        // GEPLANTER PFAD (Etappe 2b, „Beide Pfade behalten"): wurde vorab ein KI-Kopf-Plan ausgearbeitet
        // und im Conceptor geprüft ($planConceptId), referenziert der Concept-Go dieses Draft-Concept
        // (existing_concept_id → kein neuer GenerateConceptJob), statt neu zu generieren. Ohne Plan =
        // SCHNELL-PFAD (frische Generierung). FAIL-SOFT: ist der Plan inzwischen weg (gelöscht/Team-fremd),
        // NICHT hart blocken — Prop still verwerfen und frisch generieren (ein leerer/toter Plan kippt den Go nicht).
        if ($scope === 'concept' && (int) ($this->planConceptId ?? 0) > 0) {
            if (FoodAlchemistConcept::where('team_id', $team->id)->whereKey($this->planConceptId)->exists()) {
                $optionen['existing_concept_id'] = (int) $this->planConceptId;
            } else {
                $this->planConceptId = null;
            }
        }
        // SKIZZEN-LINEAGE (Etappe 4, Teil 2a): kam der Gericht-Go aus einer übertragenen Skizze
        // ({@see skizzeAlsGericht} → $skizzeGerichtId), wird der Lauf auf sie zurückgestempelt
        // (origin_dish_idea_id) — Voraussetzung für die Status-Rückkopplung auf die Skizzen-Karte.
        // FAIL-SOFT: ist die Skizze inzwischen weg (gelöscht/verworfen/Team-fremd), NICHT blocken —
        // Prop still verwerfen und ohne Herkunft generieren (eine tote Skizze kippt den Go nicht).
        if ($scope === 'gericht' && (int) ($this->skizzeGerichtId ?? 0) > 0) {
            $skizzeDa = FoodAlchemistDishIdea::visibleToTeam($team)
                ->where('status', '!=', 'verworfen')
                ->whereKey($this->skizzeGerichtId)
                ->exists();
            if ($skizzeDa) {
                $optionen['origin_dish_idea_id'] = (int) $this->skizzeGerichtId;
            } else {
                $this->skizzeGerichtId = null;
            }
        }
        try {
            $run = $cascade->starteKaskade($team, $scope, $session, (string) ($this->eingabe[$scope]['creative_mode'] ?? 'voll_kreativ'), $optionen);
            $this->laufId = $run->id;
            $this->laeuft = true;
            $this->hinweis = null;
            // Verbrauch AN DEN SCOPE binden: nur der Concept-Go verbraucht den KI-Kopf-Plan, nur der
            // Gericht-Go die Skizzen-Herkunft. Sonst löschte ein Basisrezept-/Gericht-Go den vorbereiteten
            // Concept-Plan, obwohl er für diesen Lauf nie gelesen wurde (und umgekehrt).
            if ($scope === 'concept') {
                $this->planConceptId = null;    // Plan verbraucht (referenziert ODER frisch generiert) → nächster Go Schnell-Pfad
                // #53 persistent: auch den gespeicherten Zeiger lösen (Plan verbraucht) — sonst böte ein
                // Reload nach dem Go den bereits verbrauchten Plan wieder an. Fail-soft.
                if ($session->plan_concept_id !== null) {
                    try {
                        $session->update(['plan_concept_id' => null]);
                    } catch (\Throwable) {
                        // Persistenz-Fehler darf den bereits gestarteten Lauf nicht kippen.
                    }
                }
            }
            if ($scope === 'gericht') {
                $this->skizzeGerichtId = null;  // Skizzen-Ursprung verbraucht (auf den Lauf gestempelt) → nächster Go ohne Herkunft
            }
            $this->wissenVorschau = null;   // neue Kaskade → Vorschau weg; die Steps zeigen dann das ECHTE Wissen (#1a)
            $this->meldung = 'Kaskade gestartet — Entwurf wird erzeugt …';
            $this->fehler = null;
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
    }

    /** Poll-Ziel (wire:poll während $laeuft): Lauf-Status aus der DB lesen. */
    public function pruefeLauf(PlanningCascadeService $cascade): void
    {
        $team = $this->team();
        if ($team === null || $this->laufId === null) {
            $this->laeuft = false;

            return;
        }
        $lauf = $cascade->lauf($team, $this->laufId);
        if ($lauf === null || $lauf->status !== 'running') {
            $this->laeuft = false;
            $this->hinweis = null;
            if ($lauf !== null && $lauf->status === 'review') {
                $this->meldung = 'Entwurf erzeugt — im Ergebnis unten prüfen.';
            } elseif ($lauf !== null && $lauf->status === 'failed') {
                $this->fehler = 'Generierung fehlgeschlagen — Details im Ergebnis unten.';
            }

            return;
        }
        // Queue-Watchdog: „Job-Beweis" = ein Schritt ist done/failed (ein Generator-Job ist BEWIESEN
        // gelaufen). freigegeben/skipped/verworfen zählen bewusst NICHT — sonst wird der Wächter blind,
        // sobald eine Stufe freigegeben ist (Bug: stiller Endlos-Spinner, wenn danach der Worker fehlt).
        // Läuft der Run ungewöhnlich lange OHNE Job-Beweis und warten Schritte → fast sicher kein Worker.
        $jobBewiesen = $lauf->steps->contains(fn ($s) => in_array($s->status, ['done', 'failed'], true));
        $wartet = $lauf->steps->contains(fn ($s) => in_array($s->status, ['queued', 'running'], true));
        $alterSek = $lauf->created_at !== null ? $lauf->created_at->diffInSeconds(now()) : 0;
        $this->hinweis = (! $jobBewiesen && $wartet && $alterSek > self::WATCHDOG_SEKUNDEN)
            ? 'Der Lauf läuft ungewöhnlich lange und kein Schritt kommt voran — vermutlich läuft kein Hintergrund-Worker (Queue). Sobald er die Jobs abarbeitet, geht es automatisch weiter.'
            : null;
    }

    /**
     * Recovery bei hängendem Lauf (Idempotenz/Resume, Etappe 8): verwaiste in-flight Steps als
     * `failed` markieren, damit der Lauf wieder handlungsfähig wird (neu generieren / verwerfen).
     * Greift NUR bei wirklich verwaisten Steps ({@see PlanningCascadeService::VERWAIST_NACH_MINUTEN})
     * — ein junger, evtl. noch lebender Job wird nicht abgewürgt; dann sagt es das ehrlich.
     */
    public function laufFortsetzen(PlanningCascadeService $cascade): void
    {
        $team = $this->team();
        if ($team === null || $this->laufId === null) {
            return;
        }
        $n = $cascade->reapeVerwaisteSteps($team, $this->laufId);
        if ($n > 0) {
            $this->meldung = $n . ' abgebrochene(r) Schritt(e) freigeräumt — jetzt unten neu generieren oder verwerfen.';
            $this->fehler = null;
        } else {
            $this->meldung = 'Kein abgebrochener Schritt gefunden — der Lauf arbeitet vermutlich noch. Kurz warten.';
        }
        $this->refreshLaeuft($cascade);
    }

    /**
     * Echtes Resume (Idempotenz/Resume, Etappe 8 Teil 3): alle GESCHEITERTEN generierbaren Steps des
     * Laufs auf einmal wieder aufnehmen — statt sie einzeln über {@see regeneriereStep} („neu
     * generieren") zu bedienen. Ergänzt {@see laufFortsetzen}: der Reaper (Teil 1) macht harte Hänger
     * erst zu `failed`, dieser Resume ({@see PlanningCascadeService::setzeLaufFort}) nimmt dann alle
     * `failed`-Steps (verwaist ODER regulär gescheitert) gebündelt wieder auf. Idempotent gegen
     * Doppel-Jobs (Service-Vertrag: nur `failed`-Steps, die sofort auf `running` flippen).
     */
    public function laufWiederAufnehmen(PlanningCascadeService $cascade): void
    {
        $team = $this->team();
        if ($team === null || $this->laufId === null) {
            return;
        }
        $n = $cascade->setzeLaufFort($team, $this->laufId);
        if ($n > 0) {
            $this->meldung = $n . ' gescheiterte(r) Schritt(e) werden neu erzeugt — der Fortschritt läuft oben durch.';
            $this->fehler = null;
        } else {
            $this->meldung = 'Kein gescheiterter Schritt zum Fortsetzen.';
        }
        $this->refreshLaeuft($cascade);
    }

    // ── Freigabe / Verwerfen (Gate 2 — inline im Editor) ───────────────

    /** Einen erzeugten Draft freigeben (→ live) — Rezept approved / Concept active. */
    public function gibFrei(int $stepId, PlanningCascadeService $cascade): void
    {
        $team = $this->team();
        if ($team === null) {
            return;
        }
        try {
            $cascade->gibStepFrei($team, $stepId);
            $this->meldung = 'Freigegeben.';
            $this->fehler = null;
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
        $this->refreshLaeuft($cascade);
    }

    /** Einen Draft verwerfen (soft-delete). */
    public function verwirf(int $stepId, PlanningCascadeService $cascade): void
    {
        $team = $this->team();
        if ($team === null) {
            return;
        }
        try {
            $cascade->verwirfStep($team, $stepId);
            $this->meldung = 'Verworfen.';
            $this->fehler = null;
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
        $this->refreshLaeuft($cascade);
    }

    /**
     * Ein geplantes Sub-Rezept einzeln JETZT erzeugen (Etappe 1, Teil 2) — vorziehen, ohne auf die
     * Freigabe der Stufe darüber zu warten. Der Step geht auf `running`; die Fläche pollt wie beim Go.
     */
    public function erzeugeGeplant(int $stepId, PlanningCascadeService $cascade): void
    {
        $team = $this->team();
        if ($team === null) {
            return;
        }
        try {
            $cascade->erzeugeGeplantenStep($team, $stepId);
            $this->meldung = 'Sub-Rezept wird erzeugt …';
            $this->fehler = null;
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
        $this->refreshLaeuft($cascade);
    }

    /**
     * Manuell ein Basisrezept in die Basisrezepte-Stufe ergänzen (T2): ein Sub-Rezept, das die KI nicht
     * als Komponente erkannt hat (z. B. ein fehlender Jus), als `geplant`-Step nachziehen. Erzeugt wird
     * es danach je Zeile mit „jetzt erzeugen". Fail-soft; kein aktiver Lauf/leerer Name = No-op.
     */
    public function ergaenzeSubRezept(PlanningCascadeService $cascade): void
    {
        $team = $this->team();
        $name = trim($this->neuerSubName);
        if ($team === null || $this->laufId === null || $name === '') {
            return;
        }
        try {
            $cascade->ergaenzeManuellenSubStep($team, (int) $this->laufId, $name);
            $this->neuerSubName = '';
            $this->meldung = 'Basisrezept ergänzt — mit „jetzt erzeugen" generieren.';
            $this->fehler = null;
        } catch (\Throwable $e) {
            $this->fehler = 'Ergänzen nicht möglich: ' . $e->getMessage();
        }
        $this->refreshLaeuft($cascade);
    }

    /** Ein geplantes Sub-Rezept verwerfen („brauche ich nicht") — es wird bei der Stufen-Freigabe nicht erzeugt. */
    public function verwirfGeplant(int $stepId, PlanningCascadeService $cascade): void
    {
        $team = $this->team();
        if ($team === null) {
            return;
        }
        try {
            $cascade->verwirfGeplantenStep($team, $stepId);
            $this->meldung = 'Sub-Rezept verworfen.';
            $this->fehler = null;
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
        $this->refreshLaeuft($cascade);
    }

    /**
     * A: Inline-Zutaten-Review eines Drafts auf-/zuklappen (voll editierbar vor Freigabe).
     * On-Demand — der IngredientEditor wird erst beim Öffnen gemountet.
     */
    public function toggleZutaten(int $stepId): void
    {
        if (in_array($stepId, $this->zutatenOffen, true)) {
            $this->zutatenOffen = array_values(array_diff($this->zutatenOffen, [$stepId]));
        } else {
            $this->zutatenOffen[] = $stepId;
        }
    }

    /** Alle offenen Entwürfe des Laufs freigeben. */
    public function alleFrei(PlanningCascadeService $cascade): void
    {
        $team = $this->team();
        if ($team === null || $this->laufId === null) {
            return;
        }
        $cascade->gibRunFrei($team, $this->laufId);
        $this->meldung = 'Alle Entwürfe freigegeben.';
        $this->refreshLaeuft($cascade);
    }

    /** Alle offenen Entwürfe des Laufs verwerfen. */
    public function alleVerwerfen(PlanningCascadeService $cascade): void
    {
        $team = $this->team();
        if ($team === null || $this->laufId === null) {
            return;
        }
        $cascade->verwirfRun($team, $this->laufId);
        $this->meldung = 'Alle Entwürfe verworfen.';
        $this->refreshLaeuft($cascade);
    }

    /**
     * Ganze Stufe freigeben (Stufen-Knopf im Cockpit): gibt alle offenen Entwürfe einer `kind` frei —
     * das startet die nächste Stufe (siehe PlanningCascadeService::gibStufeFrei/gibStepFrei).
     */
    public function gibStufeFrei(string $kind, PlanningCascadeService $cascade): void
    {
        $team = $this->team();
        if ($team === null || $this->laufId === null) {
            return;
        }
        // Etappe 6 Margen-Gate: die anstehenden Drafts VOR der Freigabe prüfen — danach sind sie
        // `freigegeben`, nicht mehr `done`, und der Klassen-Vergleich fände nichts mehr.
        $unterKlasse = $this->stepsUnterAufschlagsklasse($team, $kind, $cascade);
        $this->margenWarnung = null;
        try {
            $cascade->gibStufeFrei($team, $this->laufId, $kind);
            $this->meldung = 'Stufe freigegeben — die nächste Stufe wird erzeugt.';
            $this->fehler = null;
            $this->margenWarnung = $this->margenWarnungText($unterKlasse);
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
        $this->refreshLaeuft($cascade);
    }

    /**
     * Etappe 6 Margen-Gate — welche der bei der Freigabe der Stufe $kind anstehenden Rezept-Drafts
     * würden UNTER ihrer Aufschlagsklasse freigegeben? „unter Aufschlagsklasse" = ein MANUELLER VK,
     * der den Klassen-Vorschlag unterschreitet (source=`manuell` und sales_net < vorschlag.sales_net).
     *
     * Reuse `SalesRecipeService::cockpit` (die EINE Kalkulations-Wahrheit, GL-02 I9) — keine neue
     * Rechenlogik. Ein Auto-VK (source=`class`) trifft die Klasse exakt → nie drunter; ohne Vorschlag
     * (kein EK / keine Portionierung / Formel fehlt) gibt es keine Klassen-Schwelle → keine Aussage,
     * nicht geraten. Bewusst am Klassen-Vorschlag statt an der Food-Cost-Ampel (Team-Ziel-Wareneinsatz
     * ist oft nicht gepflegt → Ampel `unbekannt`, s. Backlog #87); die Klassen-Schwelle trägt das
     * Rezept selbst über seine markup_class.
     *
     * @return list<string> Namen der Positionen unter Aufschlagsklasse (leer = nichts zu warnen)
     */
    private function stepsUnterAufschlagsklasse(Team $team, string $kind, PlanningCascadeService $cascade): array
    {
        if ($this->laufId === null) {
            return [];
        }
        $lauf = $cascade->lauf($team, $this->laufId);
        if ($lauf === null) {
            return [];
        }
        // Nur die freizugebenden Drafts (`done`) dieser Stufe — exakt die, die gibStufeFrei scharf schaltet.
        $refIds = $lauf->steps
            ->where('kind', $kind)
            ->where('ref_type', 'recipe')
            ->where('status', 'done')
            ->pluck('ref_id')
            ->filter()
            ->map(fn ($v) => (int) $v)
            ->unique()
            ->values()
            ->all();
        if ($refIds === []) {
            return [];
        }
        $sales = app(SalesRecipeService::class);
        $rezepte = FoodAlchemistRecipe::visibleToTeam($team)->whereIn('id', $refIds)->get();
        $unter = [];
        foreach ($rezepte as $rz) {
            $c = $sales->cockpit($rz, $team);
            $vk = is_array($c['vk'] ?? null) ? $c['vk'] : [];
            $vorschlag = is_array($vk['vorschlag'] ?? null) ? $vk['vorschlag'] : null;
            if (($vk['source'] ?? null) === 'manuell'
                && isset($vk['sales_net'], $vorschlag['sales_net'])
                && (float) $vk['sales_net'] < (float) $vorschlag['sales_net']) {
                $unter[] = (string) ($rz->name ?? ('#'.$rz->id));
            }
        }

        return $unter;
    }

    /**
     * Formuliert die Margen-Gate-Warnung aus den Namen der Positionen unter Aufschlagsklasse.
     * `null`, wenn nichts drunter liegt (kein Rauschen bei sauberer Freigabe).
     *
     * @param  list<string>  $namen
     */
    private function margenWarnungText(array $namen): ?string
    {
        if ($namen === []) {
            return null;
        }
        $anzahl = count($namen);
        $kopf = $anzahl === 1 ? '1 Position unter Aufschlagsklasse freigegeben' : $anzahl.' Positionen unter Aufschlagsklasse freigegeben';

        return sprintf('%s: %s — VK prüfen (Marge unter Klassen-Vorgabe).', $kopf, implode(', ', $namen));
    }

    /** Per-Step-KI: einen Entwurf neu generieren (altes Draft wird verworfen). */
    public function neuGenerieren(int $stepId, PlanningCascadeService $cascade): void
    {
        $team = $this->team();
        if ($team === null) {
            return;
        }
        try {
            $cascade->regeneriereStep($team, $stepId);
            $this->meldung = 'Wird neu generiert …';
            $this->fehler = null;
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
        $this->refreshLaeuft($cascade);
    }

    /**
     * Stufen-Ableitung fürs Cockpit: je Ebene (concept · gericht · rezept) Zähler + Zustand. Nur
     * erreichte Stufen (mind. 1 Step) — so enthüllt sich die Kaskade fortschreitend. Rein für die Anzeige.
     *
     * Die Basisrezepte-Stufe ist erreicht, sobald das Gericht als Entwurf steht: ihre Zeilen sind
     * dann `geplant` (Sub-Rezept benannt, erzeugt wird es bei der Freigabe der Stufe darüber) bzw.
     * `skipped` (Bestands-Rezept übernommen). Zustand `geplant` heisst also: sichtbar, aber noch
     * nicht erzeugt — kein Freigabe-Knopf (der käme erst bei `prüfen`).
     *
     * @param  \Illuminate\Support\Collection<int,\Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep>  $steps
     * @return list<array<string,mixed>>
     */
    public function stufenAusSteps($steps): array
    {
        $defs = [['kind' => 'concept', 'label' => 'Concept'], ['kind' => 'gericht', 'label' => 'Gerichte'], ['kind' => 'rezept', 'label' => 'Basisrezepte']];
        $out = [];
        foreach ($defs as $d) {
            $grp = $steps->where('kind', $d['kind']);
            $total = $grp->count();
            if ($total === 0) {
                continue;
            }
            $running = $grp->whereIn('status', ['queued', 'running'])->count();
            $done = $grp->where('status', 'done')->count();
            $freigegeben = $grp->where('status', 'freigegeben')->count();
            $verworfen = $grp->where('status', 'verworfen')->count();
            $failed = $grp->where('status', 'failed')->count();
            // `geplant` = benanntes Sub-Rezept, das auf die Freigabe der Stufe darüber wartet;
            // `skipped` = übernommenes Bestands-Rezept (Reuse) — nichts zu erzeugen, also fertig.
            $geplant = $grp->where('status', 'geplant')->count();
            $uebernommen = $grp->where('status', 'skipped')->count();
            $zustand = $running > 0 ? 'läuft' : ($done > 0 ? 'prüfen' : ($geplant > 0 ? 'geplant' : 'erledigt'));
            $out[] = [
                'kind' => $d['kind'], 'label' => $d['label'], 'total' => $total,
                'running' => $running, 'done' => $done, 'freigegeben' => $freigegeben,
                'verworfen' => $verworfen, 'failed' => $failed, 'geplant' => $geplant,
                'uebernommen' => $uebernommen, 'fertig' => $done + $freigegeben + $uebernommen,
                'zustand' => $zustand,
            ];
        }

        return $out;
    }

    /**
     * Landing-Kaskaden-Status je Session (finale Etappe — Hauptseite): der JÜNGSTE Lauf einer Session
     * bestimmt ihr Status-Badge und ihren Stufen-Fortschritt. Zwei Query-Pässe (Läufe + deren Steps),
     * in PHP gruppiert — KEIN N+1 über die Session-Liste. Sessions ohne Lauf tauchen nicht auf → die
     * Blade zeigt sie als „Entwurf" (verwaister Entwurf, sichtbar). Rein für die Anzeige.
     *
     * Status-Ableitung = jüngster Lauf-Status (running→läuft · review→prüfen · done→fertig ·
     * failed→fehlgeschlagen), Spiegel von {@see PlanningCascadeService::recomputeRunStatus}.
     *
     * @param  list<int>  $sessionIds
     * @return array<int,array<string,mixed>>  sessionId → {status, running, run_id, scope, stufen}
     */
    public function landingKaskadenMap(?Team $team, array $sessionIds): array
    {
        if ($team === null || $sessionIds === []) {
            return [];
        }
        // Jüngster Lauf je Session (orderByDesc id → erster Treffer je Session gewinnt = Retry gewinnt).
        $laeufe = FoodAlchemistCascadeRun::visibleToTeam($team)
            ->whereIn('planning_session_id', $sessionIds)
            ->orderByDesc('id')
            ->get(['id', 'planning_session_id', 'scope', 'status']);
        $latest = [];
        foreach ($laeufe as $r) {
            $sid = (int) $r->planning_session_id;
            if (! isset($latest[$sid])) {
                $latest[$sid] = $r;
            }
        }
        if ($latest === []) {
            return [];
        }
        // Steps aller jüngsten Läufe in EINEM Pass holen → je Lauf gruppieren (kein N+1).
        $runIds = array_map(fn ($r) => (int) $r->id, array_values($latest));
        $stepsByRun = \Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep::whereIn('cascade_run_id', $runIds)
            ->get(['cascade_run_id', 'kind', 'status'])
            ->groupBy('cascade_run_id');

        $badge = ['running' => 'läuft', 'review' => 'prüfen', 'done' => 'fertig', 'failed' => 'fehlgeschlagen'];
        $out = [];
        foreach ($latest as $sid => $r) {
            $steps = $stepsByRun->get((int) $r->id) ?? collect();
            $out[$sid] = [
                'status' => $badge[$r->status] ?? (string) $r->status,
                'running' => $r->status === 'running',
                'run_id' => (int) $r->id,
                'scope' => (string) $r->scope,
                'stufen' => $this->stufenAusSteps($steps),
            ];
        }

        return $out;
    }

    /** Test-/Direkteinstieg: Stufen des aktiven Laufs (lädt den Run selbst). */
    public function stufen(): array
    {
        $team = $this->team();
        if ($team === null || $this->laufId === null) {
            return [];
        }
        $lauf = app(PlanningCascadeService::class)->lauf($team, $this->laufId);

        return $lauf === null ? [] : $this->stufenAusSteps($lauf->steps);
    }

    /** Nach einer Freigabe/Regenerierung neu bestimmen, ob der Lauf (wieder) rechnet → Polling steuern. */
    private function refreshLaeuft(PlanningCascadeService $cascade): void
    {
        $team = $this->team();
        if ($team === null || $this->laufId === null) {
            $this->laeuft = false;

            return;
        }
        $lauf = $cascade->lauf($team, $this->laufId);
        $this->laeuft = $lauf !== null && $lauf->status === 'running';
        $this->anreicherungLaeuft = $this->anreicherungOffen($lauf);
    }

    /**
     * Läuft nach einer Freigabe noch eine async Anreicherung (deferred.enrich=queued|running) ODER eine
     * KI-Foto-Erzeugung (deferred.bilder=queued|running, z. B. „neu erzeugen", Etappe 7 Teil 2b) eines
     * Rezept-/Gericht-Steps? Dann pollt die Fläche weiter, obwohl der Run schon „done" sein kann — so
     * kippt das Status-Badge live vom Spinner auf das Ergebnis (oder den sichtbaren Fehler).
     */
    private function anreicherungOffen($lauf): bool
    {
        if ($lauf === null) {
            return false;
        }

        return $lauf->steps->contains(function ($s) {
            if ($s->status !== 'freigegeben' || ! in_array($s->kind, ['rezept', 'gericht'], true)) {
                return false;
            }
            $deferred = is_array($s->deferred) ? $s->deferred : [];
            $enrich = is_array($deferred['enrich'] ?? null) ? ($deferred['enrich']['status'] ?? null) : null;
            $bilder = is_array($deferred['bilder'] ?? null) ? ($deferred['bilder']['status'] ?? null) : null;

            return in_array($enrich, ['queued', 'running'], true)
                || in_array($bilder, ['queued', 'running'], true);
        });
    }

    /** „Neu anreichern" (Cockpit): Anreicherung eines freigegebenen Drafts erneut anstoßen (nach Fehler). */
    public function neuAnreichern(int $stepId, PlanningCascadeService $cascade): void
    {
        $team = $this->team();
        if ($team === null) {
            return;
        }
        try {
            $cascade->reAnreichern($team, $stepId);
            $this->meldung = 'Anreicherung neu gestartet …';
            $this->fehler = null;
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
        $this->refreshLaeuft($cascade);
    }

    /** „Neu erzeugen" (Cockpit, Etappe 7 Teil 2b): NUR die KI-Fotos eines freigegebenen Drafts erneut
     *  anstoßen (ohne Voll-Anreicherung) — nach deferred.bilder=failed. */
    public function bilderNeu(int $stepId, PlanningCascadeService $cascade): void
    {
        $team = $this->team();
        if ($team === null) {
            return;
        }
        try {
            $cascade->reBilder($team, $stepId);
            $this->meldung = 'KI-Fotos werden neu erzeugt …';
            $this->fehler = null;
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
        $this->refreshLaeuft($cascade);
    }

    /** Manuelles Foto hochladen (Cockpit, Etappe 7 Teil 2): ein eigenes Bild als Rezept-Foto eines
     *  freigegebenen Rezept-/Gericht-Drafts übernehmen — die NICHT-KI-Alternative zur Foto-Erzeugung,
     *  neben „neu erzeugen". `$istErgebnis=true` = Hero/Ergebnis-Foto (max. 1), sonst Pool-Foto. Kein
     *  KI-Call → das Foto überlebt einen späteren KI-Re-Trigger (loescheKiFotos). Empty-only: ohne
     *  gewählte Datei passiert nichts (gesagt). */
    public function fotoHochladen(int $stepId, bool $istErgebnis = false, ?PlanningCascadeService $cascade = null): void
    {
        $cascade ??= app(PlanningCascadeService::class);
        $team = $this->team();
        if ($team === null) {
            return;
        }
        $datei = $this->fotoUploads[$stepId] ?? null;
        if ($datei === null) {
            $this->fehler = 'Kein Foto gewählt.';

            return;
        }
        $this->validate([
            "fotoUploads.$stepId" => 'image|max:8192',   // max. 8 MB, nur Bilder
        ]);
        try {
            $cascade->uebernimmManuellesFotoFuerStep($team, $stepId, $datei, $istErgebnis);
            $this->meldung = $istErgebnis ? 'Ergebnis-Foto übernommen.' : 'Foto übernommen.';
            $this->fehler = null;
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
        unset($this->fotoUploads[$stepId]);
        $this->refreshLaeuft($cascade);
    }

    /** Foto-Wiederverwendungs-Picker öffnen (Etappe 7 Teil 3b): zeigt vorhandene Team-Fotos zur
     *  Übernahme auf diesen Draft. Nur einer offen zur Zeit; die Kandidaten baut render(). */
    public function fotoPickerOeffnen(int $stepId): void
    {
        $this->fotoPickerStep = $stepId;
    }

    /** Foto-Wiederverwendungs-Picker schliessen. */
    public function fotoPickerSchliessen(): void
    {
        $this->fotoPickerStep = null;
    }

    /** Ein vorhandenes Team-Foto (aus dem Picker) auf den Draft übernehmen (Etappe 7 Teil 3b) —
     *  COPY-ON-REUSE, kein KI-Call. `$istErgebnis=true` = Hero/Ergebnis-Foto (max. 1), sonst Pool.
     *  Verdrahtet fotoUebernehmen → uebernimmVorhandenesFotoFuerStep (Teil 3a-Primitive). */
    public function fotoUebernehmen(int $stepId, int $fotoId, bool $istErgebnis = false, ?PlanningCascadeService $cascade = null): void
    {
        $cascade ??= app(PlanningCascadeService::class);
        $team = $this->team();
        if ($team === null) {
            return;
        }
        try {
            $cascade->uebernimmVorhandenesFotoFuerStep($team, $stepId, $fotoId, $istErgebnis);
            $this->meldung = $istErgebnis ? 'Ergebnis-Foto übernommen.' : 'Foto übernommen.';
            $this->fehler = null;
            $this->fotoPickerStep = null;
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
        $this->refreshLaeuft($cascade);
    }

    // ── Composer-Tab (Foodpairing-Fläche: Anker zusammenstellen) ───────

    /** Einen Anker in die Auswahl aufnehmen (aus Suchtreffer ODER Kandidaten-Klick im Netz). */
    public function composerAdd(int $id): void
    {
        $ids = array_column($this->composerAnker, 'id');
        if (in_array($id, $ids, true) || count($this->composerAnker) >= 12) {
            return;
        }
        $a = DB::table('foodalchemist_vocab_pairing_anchors')->where('id', $id)->first(['id', 'slug', 'display_de']);
        if ($a === null) {
            return;
        }
        $this->composerAnker[] = ['id' => (int) $a->id, 'slug' => $a->slug, 'label' => $a->display_de ?: $a->slug];
        $this->composerTerm = '';
    }

    /** Einen Anker aus der Auswahl entfernen. */
    public function composerRemove(int $id): void
    {
        $this->composerAnker = array_values(array_filter(
            $this->composerAnker,
            fn ($a) => (int) $a['id'] !== $id
        ));
        if ($this->composerFocus === $id) {
            $this->composerFocus = null;
        }
    }

    /** Fokus auf einen Anker setzen/aufheben (Klick im Netz; 0 oder erneut derselbe = aufheben). */
    public function composerFocus(int $id): void
    {
        if ($id === 0 || $this->composerFocus === $id) {
            $this->composerFocus = null;

            return;
        }
        if (in_array($id, array_map('intval', array_column($this->composerAnker, 'id')), true)) {
            $this->composerFocus = $id;
        }
    }

    // ── Datenbeschaffung ───────────────────────────────────────────────

    private function aktiveSession(): ?FoodAlchemistPlanningSession
    {
        if ($this->sessionId === null) {
            return null;
        }
        $team = $this->team();

        return FoodAlchemistPlanningSession::visibleToTeam($team)->find($this->sessionId);
    }

    public function render()
    {
        $team = $this->team();

        // Sessions team-sichtbar + Trend-Kategorie/Klasse (loser Join über die Herkunft).
        $sessions = TeamScope::applyVisible(
            DB::table('foodalchemist_planning_sessions as s')
                ->leftJoin('foodalchemist_trend_meta as m', 'm.knowledge_document_id', '=', 's.source_knowledge_document_id')
                ->whereNull('s.deleted_at'),
            's.team_id', $team
        )->orderByDesc('s.updated_at')
            ->get(['s.id', 's.title', 's.status', 's.source_knowledge_document_id', 's.updated_at', 'm.category', 'm.trend_class']);

        // Finale Etappe (Hauptseite): Kaskaden-Status je Session (Badge + Stufen-Fortschritt) — ein
        // Query-Pass über die VOLLE Liste (vor dem Filter, damit der Status-Filter greifen kann).
        $kaskaden = $this->landingKaskadenMap($team, $sessions->pluck('id')->map(fn ($v) => (int) $v)->all());

        // Filter/Suche (finale Etappe #17): Volltext über den Titel + Status-Filter (aus $kaskaden).
        // Filtert Liste UND Zuletzt-Karten konsistent; leere Filter = unveränderte Liste.
        $suche = mb_strtolower(trim($this->sucheListe));
        $filterStatus = trim($this->filterStatus);
        $filterTyp = trim($this->filterTyp);
        if ($suche !== '' || $filterStatus !== '' || $filterTyp !== '') {
            $sessions = $sessions->filter(function ($s) use ($suche, $filterStatus, $filterTyp, $kaskaden) {
                if ($suche !== '' && ! str_contains(mb_strtolower((string) $s->title), $suche)) {
                    return false;
                }
                if ($filterStatus !== '' && ($kaskaden[(int) $s->id]['status'] ?? 'entwurf') !== $filterStatus) {
                    return false;
                }
                // Typ = Scope des jüngsten Laufs; Sessions ohne Lauf (kein Scope) fallen bei gesetztem Typ raus.
                if ($filterTyp !== '' && ($kaskaden[(int) $s->id]['scope'] ?? null) !== $filterTyp) {
                    return false;
                }

                return true;
            })->values();
        }

        // Baum: Kategorie → Sessions (Frei-Bucket für ohne-Trend). Auf der gefilterten Liste.
        $baum = $sessions->groupBy(fn ($s) => $s->category ?: '__frei')
            ->map(fn ($grp, $cat) => [
                'category' => $cat === '__frei' ? 'Frei / ohne Kategorie' : $cat,
                'sessions' => $grp->values(),
            ])->values();

        $active = $this->aktiveSession();
        $skizzen = null;
        if ($active !== null) {
            $skizzen = app(IdeenService::class)->liste($team, null, null, false, $active->id);
        }

        // Status-Rückkopplung auf die Skizzen-Karte (Etappe 4, Teil 2b): der aus einer Skizze
        // gestartete Gericht-Go stempelt sich per origin_dish_idea_id (Teil 2a) auf die Ursprungs-
        // Skizze zurück. Hier den jüngsten verknüpften Lauf je Skizze auflösen → die Karte zeigt
        // läuft/prüfen/fertig/fehlgeschlagen, ohne den Worker zu öffnen. Map: idea_id → {run_id,status,scope}.
        $skizzenLauf = [];
        if ($skizzen !== null && $team !== null) {
            $ideaIds = collect($skizzen['einzel'])->pluck('id')
                ->merge(collect($skizzen['gruppen'])->flatMap(fn ($g) => collect($g['ideen'])->pluck('id')))
                ->filter()->map(fn ($v) => (int) $v)->unique()->values()->all();
            if ($ideaIds !== []) {
                // orderByDesc('id') → erster Treffer je Skizze = jüngster Lauf (Retry gewinnt).
                $laeufe = FoodAlchemistCascadeRun::visibleToTeam($team)
                    ->whereIn('origin_dish_idea_id', $ideaIds)
                    ->orderByDesc('id')
                    ->get(['id', 'scope', 'status', 'origin_dish_idea_id']);
                foreach ($laeufe as $r) {
                    $oid = (int) $r->origin_dish_idea_id;
                    if (! isset($skizzenLauf[$oid])) {
                        $skizzenLauf[$oid] = [
                            'run_id' => (int) $r->id,
                            'status' => (string) $r->status,
                            'scope' => (string) $r->scope,
                        ];
                    }
                }
            }
        }

        // Live-Poll der Karten-Badges (Etappe 4, Teil 3b-b): solange ein aus einer Skizze gestarteter
        // Lauf noch `running` ist, refresht sich die Karte selbst (bare wire:poll → $refresh → render()
        // liest $skizzenLauf frisch), ohne das Einzel-Cockpit ($laufId/$laeuft) anzuwerfen. Nur `running`
        // ist transient (Worker-getrieben); `review`/`done`/`failed` warten auf den Menschen bzw. sind
        // terminal → kein Poll-Grund → das Poll-Element entfällt und das Polling stoppt.
        $skizzenLaufAktiv = false;
        foreach ($skizzenLauf as $l) {
            if (($l['status'] ?? null) === 'running') {
                $skizzenLaufAktiv = true;
                break;
            }
        }

        // Aktiver Kaskaden-Lauf (in-place „Go") inkl. Steps — für Fortschritt + Ergebnis-Liste.
        $lauf = ($team !== null && $this->laufId !== null)
            ? app(PlanningCascadeService::class)->lauf($team, $this->laufId)
            : null;

        // Anreicherung läuft async NACH der Freigabe → Polling am Leben halten, bis das Ergebnis
        // wirklich angereichert (oder der Fehler sichtbar) ist. Bei einem flachen Gericht ist der Run
        // sonst sofort „done", während das Gericht noch ein roher Entwurf wäre.
        $this->anreicherungLaeuft = $this->anreicherungOffen($lauf);

        // Etappe 6: EK/VK/Marge je Stufe im Cockpit sichtbar — schon am Draft, nicht erst nach dem
        // Speichern/Öffnen im VK-Editor. Für jeden Rezept-/Gericht-Step mit erzeugtem Artefakt
        // (ref_type=recipe) die ABGELEITETE Kalkulation über den EINEN Bündler ziehen
        // (SalesRecipeService::cockpit → MargeService; GL-02 I9: VK/Marge sind abgeleitet, nicht
        // persistiert). Rezepte gebündelt per whereIn laden (kein N+1 über die Blade-Step-Schleife).
        // Concept-Steps tragen keine Rezept-Marge (Menü ≠ Rezept) → hier bewusst nicht gerechnet.
        // Map: ref_id → kompakte Kachel-Werte (EK gesamt · VK netto · Marge % · Wareneinsatz % + Ampel).
        $kalkulation = [];
        // Etappe 7: Kosten-Transparenz je Call — Map recipe_id → {n, model} der KI-Bild-Calls.
        $bildCalls = [];
        // Etappe 7 — Bild-Status im Cockpit (Teil 1): wurden KI-Fotos beim Go angefordert
        // (run-level `ki_bilder`) + Map recipe_id → Zahl real existierender Fotos. Ehrlicher
        // Status-Badge (erzeugt / angefordert-aber-leer), analog zum Anreicherungs-Badge.
        $bilderAngefordert = $lauf !== null && (bool) (is_array($lauf->params ?? null) ? ($lauf->params['ki_bilder'] ?? false) : false);
        $fotoCounts = [];
        if ($lauf !== null && $team !== null) {
            $rezeptRefIds = $lauf->steps
                ->whereIn('kind', ['rezept', 'gericht'])
                ->where('ref_type', 'recipe')
                ->whereIn('status', ['done', 'freigegeben', 'skipped'])
                ->pluck('ref_id')
                ->filter()
                ->map(fn ($v) => (int) $v)
                ->unique()
                ->values()
                ->all();
            if ($rezeptRefIds !== []) {
                $sales = app(SalesRecipeService::class);
                $rezepte = FoodAlchemistRecipe::visibleToTeam($team)->whereIn('id', $rezeptRefIds)->get();
                foreach ($rezepte as $rz) {
                    $c = $sales->cockpit($rz, $team);
                    $marge = is_array($c['marge'] ?? null) ? $c['marge'] : [];
                    // Etappe 6: unvollständige Bepreisung ehrlich markieren. »teil-unbepreist« =
                    // ein EK IST da, aber nicht alle costed Zutaten tragen einen Preis — die
                    // gezeigte EK/Marge ist damit ZU GÜNSTIG gerechnet (die Lücken tragen 0 €).
                    // 1:1 die kanonische Wahrheit aus DataQualityService (SignalTyp::EkKetteUnvollstaendig
                    // `vk_ek_teil`/`br_ek_teil`): ek_total_eur != null && priced < total — KEINE zweite,
                    // qs-schlaue Definition (das wäre ein Widerspruch zum Signal-Cockpit).
                    $nTotal = $rz->ek_n_ingredients_total !== null ? (int) $rz->ek_n_ingredients_total : null;
                    $nPriced = $rz->ek_n_ingredients_priced !== null ? (int) $rz->ek_n_ingredients_priced : null;
                    $kalkulation[(int) $rz->id] = [
                        'ek_total' => $rz->ek_total_eur !== null ? (float) $rz->ek_total_eur : null,
                        'vk_netto' => $c['vk']['sales_net'] ?? null,
                        'marge_pct' => $marge['marge_pct'] ?? null,
                        'we_pct' => $marge['wareneinsatz_pct'] ?? null,
                        'ampel' => (string) ($c['ampel'] ?? 'unbekannt'),
                        'formel_fehlt' => (bool) ($c['formel_fehlt'] ?? false),
                        'ek_n_priced' => $nPriced,
                        'ek_n_total' => $nTotal,
                        'ek_teil_unbepreist' => $rz->ek_total_eur !== null
                            && $nTotal !== null && $nPriced !== null && $nPriced < $nTotal,
                    ];
                }
            }

            // Etappe 7 — Kosten-Transparenz je Call: die bei der Anreicherung erzeugten KI-Fotos
            // werden je Call in foodalchemist_ai_call_log protokolliert (RecipeImageService). Hier
            // die Zahl der kostenpflichtigen Bild-Calls je Rezept-/Gericht-Draft ins Cockpit heben,
            // damit die KI-Foto-Kosten dort sichtbar sind, wo der Mensch den Go setzt. BEWUSST KEIN
            // EUR-Betrag (keine Preisquelle im Code → wäre Erfindung): gezeigt werden Call-Anzahl +
            // genutztes Modell. Calls → Rezept über die erzeugten Fotos (target_id = photo.id,
            // target_table = photos). Team-eigener Log (die Kosten dieses Teams). Map: recipe_id → {n, model}.
            if ($rezeptRefIds !== []) {
                $bildRows = DB::table('foodalchemist_ai_call_log as l')
                    ->join('foodalchemist_recipe_step_photos as p', function ($j) {
                        $j->on('p.id', '=', 'l.target_id')->whereNull('p.deleted_at');
                    })
                    ->where('l.team_id', $team->id)
                    ->where('l.target_table', 'foodalchemist_recipe_step_photos')
                    ->whereIn('l.feature', RecipeImageService::BILD_FEATURES)
                    ->whereIn('p.recipe_id', $rezeptRefIds)
                    ->groupBy('p.recipe_id')
                    ->selectRaw('p.recipe_id as rid, COUNT(*) as n, MAX(l.model) as model')
                    ->get();
                foreach ($bildRows as $row) {
                    $bildCalls[(int) $row->rid] = ['n' => (int) $row->n, 'model' => (string) ($row->model ?? '')];
                }
            }

            // Etappe 7 — Bild-Status (Teil 1): Zahl der real existierenden Fotos je Draft
            // (team-eigen, nicht gelöscht). NUR wenn KI-Fotos angefordert waren — sonst gibt es
            // keinen Status zu zeigen (Bestandsverhalten). Ehrlich ableitbar OHNE neue Persistenz:
            // Die KI-Fotos laufen im EnrichRecipeJob NACH der Anreicherung → am freigegebenen Step
            // mit deferred.enrich=done heisst »0 Fotos trotz angefordert« = nichts erzeugt (die
            // Bild-Erzeugung ist still fail-soft, ein expliziter »fehlgeschlagen«-Zustand wird
            // heute NICHT protokolliert → kein erfundenes Fehler-Badge). Explizite Fehler-
            // Persistenz + „neu erzeugen" = Folge-Chunk. Actual-Fotos statt bildCalls, weil ein
            // manueller Upload keinen Kosten-Call trägt, für »erzeugt« aber zählt.
            if ($bilderAngefordert && $rezeptRefIds !== []) {
                $fotoRows = DB::table('foodalchemist_recipe_step_photos')
                    ->whereNull('deleted_at')
                    ->where('team_id', $team->id)
                    ->whereIn('recipe_id', $rezeptRefIds)
                    ->groupBy('recipe_id')
                    ->selectRaw('recipe_id as rid, COUNT(*) as n')
                    ->get();
                foreach ($fotoRows as $row) {
                    $fotoCounts[(int) $row->rid] = (int) $row->n;
                }
            }
        }

        // Composer-Tab: Ad-hoc-Netz + Kohäsion (fit/orphan je Anker) + browsebarer Picker.
        $composerNetz = ['nodes' => [], 'edges' => [], 'meta' => []];
        $composerCohesion = null;
        $composerBrowse = ['items' => [], 'total' => 0, 'kategorien' => []];
        if ($team !== null) {
            $pairing = app(PairingService::class);
            $composerIds = array_map('intval', array_column($this->composerAnker, 'id'));
            if ($composerIds !== []) {
                // Netz inkl. Brücken-Ebene — das Orphan-Flag (bridge-basiert) steckt schon
                // in den Anker-Knoten, die Brücken-Zusammenfassung in meta.bridge.
                $composerNetz = $pairing->pairingNetzForAnkers($team, $composerIds);
                // Direkt-Pairing-Kohäsion nur als Sekundär-Info im Readout (Brücken-Metrik = meta.bridge).
                $composerCohesion = $pairing->composerCohesion($composerIds);
            }
            // Fokus (falls gesetzt) → Picker-Badge/Sortierung relativ zum fokussierten Anker.
            $composerBrowse = $pairing->composerAnkerBrowse(
                $team, (string) $this->composerTerm, $this->composerCategory !== '' ? $this->composerCategory : null,
                $composerIds, 200, $this->composerFocus
            );
        }

        // Fokus-Label (und Fokus verwerfen, wenn der Anker nicht mehr in der Auswahl ist).
        $composerFokusLabel = null;
        if ($this->composerFocus !== null) {
            foreach ($this->composerAnker as $a) {
                if ((int) $a['id'] === $this->composerFocus) {
                    $composerFokusLabel = $a['label'];
                    break;
                }
            }
            if ($composerFokusLabel === null) {
                $this->composerFocus = null;
            }
        }

        // Etappe 7 Teil 3b — Foto-Wiederverwendungs-Picker: NUR wenn offen, die Kandidaten bauen
        // (vorhandene Team-Fotos zur Übernahme auf den offenen Draft). Team-scoped (visibleToTeam),
        // OHNE die eigenen Fotos des Ziel-Rezepts (Reuse = von woanders holen), jüngste zuerst,
        // gedeckelt (Kosten-/Runaway-Guard beim Rendern der Vorschau-URLs). Kein KI-Call — reine
        // Auswahl; die Übernahme selbst läuft über fotoUebernehmen (COPY-ON-REUSE, Teil 3a).
        $fotoPickerKandidaten = [];
        if ($team !== null && $this->fotoPickerStep !== null) {
            $pStep = DB::table('foodalchemist_cascade_run_steps')
                ->where('team_id', $team->id)->where('id', $this->fotoPickerStep)
                ->first(['ref_id', 'ref_type', 'kind']);
            if ($pStep !== null && $pStep->ref_type === 'recipe' && in_array($pStep->kind, ['rezept', 'gericht'], true)) {
                $kandidaten = FoodAlchemistRecipeStepPhoto::visibleToTeam($team)
                    ->when($pStep->ref_id !== null, fn ($q) => $q->where('recipe_id', '!=', (int) $pStep->ref_id))
                    ->with('recipe:id,name')
                    ->orderByDesc('id')
                    ->limit(24)
                    ->get();
                foreach ($kandidaten as $foto) {
                    $fotoPickerKandidaten[] = [
                        'id' => (int) $foto->id,
                        'url' => $foto->url(),
                        'caption' => (string) ($foto->caption ?? ''),
                        'rezept' => (string) ($foto->recipe?->name ?? ''),
                        'ergebnis' => (bool) $foto->is_result,
                    ];
                }
            }
        }

        // Worker-Präsenz PROAKTIV (Etappe 8, Teil 2): die Ampel des Heartbeat-Service (Teil 1) VOR dem
        // Go zeigen — ergänzend zum reaktiven Watchdog `hinweis` (der erst nach ~90 s eines hängenden
        // Laufs anschlägt). Ist kein lebender `queue:work` da (`still`/`unbekannt`), bleibt jeder Go in
        // der Queue liegen → der Nutzer sieht nur einen Spinner. Rein lesend/fail-soft; `gesund` = keine
        // Warnung (Prompt/Fläche byte-unverändert wie bisher).
        $workerState = app(WorkerHealthService::class)->status()['state'];
        $workerWarnung = $workerState === 'gesund'
            ? null
            : 'Kein Hintergrund-Worker aktiv — ein Go bleibt in der Warteschlange liegen, bis der Worker (queue:work) läuft.';

        return view('foodalchemist::livewire.planung.index', [
            'sessions' => $sessions,
            'baum' => $baum,
            'kaskaden' => $kaskaden,
            'workerState' => $workerState,
            'workerWarnung' => $workerWarnung,
            'active' => $active,
            'skizzen' => $skizzen,
            'skizzenLauf' => $skizzenLauf,
            'skizzenLaufAktiv' => $skizzenLaufAktiv,
            'lauf' => $lauf,
            'kalkulation' => $kalkulation,
            'bildCalls' => $bildCalls,
            'bilderAngefordert' => $bilderAngefordert,
            'fotoCounts' => $fotoCounts,
            'fotoPickerStep' => $this->fotoPickerStep,
            'fotoPickerKandidaten' => $fotoPickerKandidaten,
            'composerNetz' => $composerNetz,
            'composerCohesion' => $composerCohesion,
            'composerBrowse' => $composerBrowse,
            'composerFocus' => $this->composerFocus,
            'composerFokusLabel' => $composerFokusLabel,
        ])->layout('platform::layouts.app');
    }
}
