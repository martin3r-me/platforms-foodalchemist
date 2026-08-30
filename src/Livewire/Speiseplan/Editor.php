<?php

namespace Platform\FoodAlchemist\Livewire\Speiseplan;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistPaket;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Enums\AusgabeStatus;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplan;
use Platform\FoodAlchemist\Services\PresentationDesignService;
use Platform\FoodAlchemist\Services\PresentationService;
use Platform\FoodAlchemist\Services\SpeiseplanService;

/**
 * Speiseplan-Editor (Fullscreen-Dark-Modal, pro Plan) — herausgezogen aus dem bisherigen
 * Master-Detail-Vollbild (Speiseplan\Index). Tabs: Kalender (Wochen-Matrix/Monat + Inline-Picker)
 * · Menü-Linien · Stammdaten (+ Zyklus-Ausrollen). Rechts eine Live-Kennzahlen-Rail
 * (VK/EK · Veggie-Tagescheck · Wiederholungs-Konflikte), die bei jeder Zellen-Änderung
 * mit-rechnet. Geöffnet per `speiseplan-editor.bearbeiten` {id}; meldet Änderungen per
 * `speiseplan-geaendert` an den Browser (Index) zurück. Schreiben durch den D1-gescopten
 * SpeiseplanService (isOwnedBy + Guard).
 */
class Editor extends Component
{
    use WithFileUploads;
    use \Platform\FoodAlchemist\Livewire\Concerns\InteractsWithSavedToast;

    public ?int $planId = null;

    // ── Spec 43: Branding (neu) + Präsentation (digitaler Aushang) ──
    public string $brandColor = '#6d28d9';

    public ?string $bandColor = null;

    public ?string $footerText = null;

    public $logoUpload = null;

    public $coverUpload = null;

    public ?int $brandingLoadedId = null;

    public ?string $brandingFehler = null;

    public string $presentationDesign = 'kiosk';

    public ?string $presentationGueltigBis = null;

    public bool $presentationPreisAnzeige = false;   // GV-Aushang ist preislos

    /** Republish-Preis-Schutz (Ebene 2, nur relevant mit Preisen): AUS = eingefrorene Preise
     *  behalten; AN = aktuelle VK ziehen. Greift nur beim erneuten Veröffentlichen. */
    public bool $presentationPreiseAktualisieren = false;

    public ?string $presentationCtaText = null;

    public ?string $presentationCtaLink = null;

    public ?int $presentationLoadedId = null;

    public ?string $presentationFehler = null;

    public ?string $presentationHinweis = null;

    // Slice F: publish-per-Betrieb — eigener Aushang-Link je Betrieb (eigene Vorlage + Slug + Freigabe).
    public ?int $outletPublishId = null;

    public ?string $outletPublishGueltigBis = null;

    public ?string $outletPublishDesign = '';

    public ?string $outletPublishSlug = '';

    public function brandingSpeichern(SpeiseplanService $svc): void
    {
        $this->brandingFehler = null;
        if ($this->planId === null) {
            return;
        }
        try {
            $svc->setBranding($this->team(), $this->planId, [
                'brand_color' => $this->brandColor ?: '#6d28d9',
                'band_color' => $this->bandColor ?? '',
                'footer_text' => $this->footerText ?? '',
            ]);
        } catch (\RuntimeException $e) {
            $this->brandingFehler = $e->getMessage();
        }
    }

    public function updatedLogoUpload(): void
    {
        if ($this->planId === null || $this->logoUpload === null) {
            return;
        }
        $this->validate(['logoUpload' => 'image|max:8192']);
        try {
            app(SpeiseplanService::class)->storeLogo($this->team(), $this->planId, $this->logoUpload);
        } catch (\RuntimeException $e) {
            $this->brandingFehler = $e->getMessage();
        }
        $this->reset('logoUpload');
    }

    public function updatedCoverUpload(): void
    {
        if ($this->planId === null || $this->coverUpload === null) {
            return;
        }
        $this->validate(['coverUpload' => 'image|max:8192']);
        try {
            app(SpeiseplanService::class)->storeCover($this->team(), $this->planId, $this->coverUpload);
        } catch (\RuntimeException $e) {
            $this->brandingFehler = $e->getMessage();
        }
        $this->reset('coverUpload');
    }

    public function brandingLogoEntfernen(SpeiseplanService $svc): void
    {
        if ($this->planId !== null) {
            $svc->clearLogo($this->team(), $this->planId);
        }
    }

    public function brandingCoverEntfernen(SpeiseplanService $svc): void
    {
        if ($this->planId !== null) {
            $svc->clearCover($this->team(), $this->planId);
        }
    }

    public function veroeffentlichen(): void
    {
        $this->presentationFehler = null;
        $this->presentationHinweis = null;
        if ($this->planId === null) {
            return;
        }
        try {
            app(PresentationService::class)->publish($this->team(), 'speiseplan', $this->planId, [
                'design' => $this->presentationDesign,
                'expires_at' => $this->presentationGueltigBis,
                'price_display' => $this->presentationPreisAnzeige,
                'price_mode' => $this->presentationPreiseAktualisieren ? 'auto' : 'preserve',
                'cta' => ['text' => $this->presentationCtaText, 'link' => $this->presentationCtaLink],
            ]);
            $this->presentationLoadedId = null;
            $this->presentationHinweis = 'Veröffentlicht — der Aushang-Link ist aktiv.';
        } catch (\Throwable $e) {
            $this->presentationFehler = $e->getMessage();
        }
    }

    public function zuruckziehen(): void
    {
        $this->presentationFehler = null;
        $this->presentationHinweis = null;
        if ($this->planId === null) {
            return;
        }
        try {
            app(PresentationService::class)->withdraw($this->team(), 'speiseplan', $this->planId);
            $this->presentationLoadedId = null;
            $this->presentationHinweis = 'Veröffentlichung zurückgezogen — der Link ist inaktiv (404).';
        } catch (\Throwable $e) {
            $this->presentationFehler = $e->getMessage();
        }
    }

    /** Slice F: einen zusätzlichen Aushang-Link FÜR einen Betrieb (eigene Vorlage + Name, eigene Freigabe). */
    public function betriebVeroeffentlichen(): void
    {
        $this->presentationFehler = null;
        $this->presentationHinweis = null;
        if ($this->planId === null) {
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
                'cta' => ['text' => $this->presentationCtaText, 'link' => $this->presentationCtaLink],
            ];
            // Nur setzen, wenn aktiv gewählt — sonst Fallback-Kette (Betriebs-Vorlage → Dokument) bzw. Zufalls-Token.
            if (trim((string) $this->outletPublishDesign) !== '') {
                $settings['design'] = $this->outletPublishDesign;
            }
            if (trim((string) $this->outletPublishSlug) !== '') {
                $settings['slug'] = $this->outletPublishSlug;
            }
            app(PresentationService::class)->publishForOutlet($this->team(), 'speiseplan', $this->planId, $this->outletPublishId, $settings);
            $this->outletPublishId = null;
            $this->outletPublishGueltigBis = null;
            $this->outletPublishDesign = '';
            $this->outletPublishSlug = '';
            $this->presentationHinweis = 'Betriebs-Link veröffentlicht — eigener Aushang mit der Vorlage und dem Namen dieses Betriebs.';
        } catch (\Throwable $e) {
            $this->presentationFehler = $e->getMessage();
        }
    }

    /** Slice F: einen Betriebs-Aushang-Link vom Netz nehmen (Standard-Link bleibt unberührt). */
    public function betriebZuruckziehen(int $outletId): void
    {
        $this->presentationFehler = null;
        $this->presentationHinweis = null;
        if ($this->planId === null) {
            return;
        }
        try {
            app(PresentationService::class)->withdrawForOutlet($this->team(), 'speiseplan', $this->planId, $outletId);
            $this->presentationHinweis = 'Betriebs-Link zurückgezogen.';
        } catch (\Throwable $e) {
            $this->presentationFehler = $e->getMessage();
        }
    }

    public array $form = ['name' => '', 'start_date' => null, 'cycle_weeks' => 4, 'min_abstand_tage' => 0, 'status' => 'draft', 'default_pax' => 100, 'budget_wareneinsatz' => null];

    // Stufe C: Rückmeldung der Produktions-Übergabe
    public ?string $prodHinweis = null;

    public ?string $prodFehler = null;

    public string $firmaSuche = '';

    public string $kontaktSuche = '';

    public string $mahlzeit = 'mittag';

    public string $ansicht = 'woche';                 // woche | monat

    public ?string $montag = null;                    // Y-m-d (Montag der sichtbaren Woche)

    public ?string $monatStr = null;                  // Y-m-01

    // Linien-Editor
    public string $neueLinie = '';

    public ?int $editLinieId = null;

    public array $linieForm = ['name' => '', 'color' => '', 'is_vegetarian' => false];

    // Zellen-Picker
    public ?string $cellDatum = null;

    public ?int $cellLinie = null;

    public string $pickerTyp = 'gericht';             // concept | paket | gericht

    public string $pickerSuche = '';

    // Spec 42: Facetten im Zell-Picker (gericht-Typ) — wie Speisekarte/Verkauf-Browser.
    public ?int $pickerHauptgruppe = null;

    public ?int $pickerDishClass = null;

    // Ausrollen
    public ?string $ausrollenBis = null;

    public ?string $ausrollenInfo = null;

    /** Meldung des Voll-Kaskade-Go (P5). */
    public ?string $kaskadeMeldung = null;

    #[On('speiseplan-editor.bearbeiten')]
    public function oeffnenBearbeiten(int $id): void
    {
        $svc = app(SpeiseplanService::class);
        $sp = $svc->detail($this->team(), $id);
        if ($sp === null) {
            return;
        }
        $this->planId = $id;
        $this->form = [
            'name' => $sp->name,
            'start_date' => optional($sp->start_date)->format('Y-m-d'),
            'cycle_weeks' => $sp->cycle_weeks,
            'min_abstand_tage' => $sp->min_abstand_tage,
            'status' => $sp->statusWert()->value,   // Spec 33 P0: gecastet, Form-Array braucht String
            'default_pax' => $sp->default_pax,
            'budget_wareneinsatz' => $sp->budget_wareneinsatz,
            // Spec 33 P2: beide Zuordnungsachsen — vorher hing der Plan nur an team_id,
            // zwei Kantinen im selben Team waren nicht unterscheidbar.
            'outlet_id' => $sp->outlet_id,
        ];
        $this->prodHinweis = null;
        $this->prodFehler = null;
        $start = $sp->start_date ?? Carbon::now();
        $this->montag = $start->copy()->startOfWeek(Carbon::MONDAY)->format('Y-m-d');
        $this->monatStr = $start->copy()->startOfMonth()->format('Y-m-d');
        $this->ausrollenBis = $start->copy()->addMonths(3)->format('Y-m-d');
        $this->ausrollenInfo = null;
        $this->cellSchliessen();
        $this->editLinieId = null;
        $this->dispatch('modal.open', name: 'speiseplan-editor');
    }

    public function speichern(SpeiseplanService $svc): void
    {
        if ($this->planId !== null) {
            $svc->update($this->team(), $this->planId, $this->form);
            $this->dispatch('speiseplan-geaendert');
            $this->savedToast('Speiseplan gespeichert');
        }
    }

    public function verknuepfeFirma(int $companyId, SpeiseplanService $svc): void
    {
        if ($this->planId === null) {
            return;
        }
        $plan = $svc->detail($this->team(), $this->planId);
        $svc->verknuepfeKunde($this->team(), $this->planId, $companyId, $plan?->crm_contact_id);
        $this->firmaSuche = '';
    }

    public function verknuepfeKontakt(int $contactId, SpeiseplanService $svc): void
    {
        if ($this->planId === null) {
            return;
        }
        $plan = $svc->detail($this->team(), $this->planId);
        $svc->verknuepfeKunde($this->team(), $this->planId, $plan?->crm_company_id, $contactId);
        $this->kontaktSuche = '';
    }

    public function loeseKunde(SpeiseplanService $svc): void
    {
        if ($this->planId === null) {
            return;
        }
        $svc->verknuepfeKunde($this->team(), $this->planId, null, null);
    }

    /** Spec 33 P5 — Schnellschalter aktiv ⇄ inaktiv (ohne Umweg über das Dropdown, ohne Archiv). */
    public function aktivUmschalten(SpeiseplanService $svc): void
    {
        $plan = $this->planId !== null
            ? FoodAlchemistSpeiseplan::visibleToTeam($this->team())->find($this->planId) : null;
        if ($plan === null) {
            return;
        }

        $neu = $plan->statusWert() === AusgabeStatus::Aktiv ? AusgabeStatus::Inaktiv : AusgabeStatus::Aktiv;
        $svc->update($this->team(), $this->planId, ['status' => $neu->value]);
        $this->form['status'] = $neu->value;
    }

    public function loeschen(int $id, SpeiseplanService $svc): void
    {
        $svc->delete($this->team(), $id);
        if ($this->planId === $id) {
            $this->planId = null;
        }
        $this->dispatch('speiseplan-geaendert');
        $this->dispatch('modal.close', name: 'speiseplan-editor');
    }

    // ── Navigation ───────────────────────────────────────────────────────

    public function wocheVerschieben(int $wochen): void
    {
        $this->montag = Carbon::parse($this->montag ?? 'now')->startOfWeek(Carbon::MONDAY)->addWeeks($wochen)->format('Y-m-d');
        $this->cellSchliessen();
    }

    public function heute(): void
    {
        $this->montag = Carbon::now()->startOfWeek(Carbon::MONDAY)->format('Y-m-d');
        $this->cellSchliessen();
    }

    public function monatVerschieben(int $monate): void
    {
        $this->monatStr = Carbon::parse($this->monatStr ?? 'now')->startOfMonth()->addMonths($monate)->format('Y-m-d');
    }

    public function tagOeffnen(string $datum): void
    {
        $this->montag = Carbon::parse($datum)->startOfWeek(Carbon::MONDAY)->format('Y-m-d');
        $this->ansicht = 'woche';
        $this->cellSchliessen();
    }

    public function ansichtSetzen(string $a): void
    {
        $this->ansicht = in_array($a, ['woche', 'monat'], true) ? $a : 'woche';
        $this->cellSchliessen();
    }

    public function mahlzeitSetzen(string $m): void
    {
        $this->mahlzeit = array_key_exists($m, SpeiseplanService::MAHLZEITEN) ? $m : 'mittag';
        $this->cellSchliessen();
    }

    // ── Linien ─────────────────────────────────────────────────────────

    public function linieAdd(SpeiseplanService $svc): void
    {
        if ($this->planId === null || trim($this->neueLinie) === '') {
            return;
        }
        $svc->addLinie($this->team(), $this->planId, ['name' => $this->neueLinie]);
        $this->neueLinie = '';
    }

    public function linieEdit(int $id, SpeiseplanService $svc): void
    {
        $sp = $svc->detail($this->team(), (int) $this->planId);
        $linie = $sp?->lines->firstWhere('id', $id);
        if ($linie === null) {
            return;
        }
        $this->editLinieId = $id;
        $this->linieForm = ['name' => $linie->name, 'color' => $linie->color ?? '', 'is_vegetarian' => (bool) $linie->is_vegetarian];
    }

    public function linieSpeichern(SpeiseplanService $svc): void
    {
        if ($this->editLinieId !== null) {
            $svc->updateLinie($this->team(), $this->editLinieId, $this->linieForm);
            $this->editLinieId = null;
        }
    }

    public function linieRaus(int $id, SpeiseplanService $svc): void
    {
        $svc->removeLinie($this->team(), $id);
        if ($this->editLinieId === $id) {
            $this->editLinieId = null;
        }
    }

    public function linieVerschieben(int $id, int $richtung, SpeiseplanService $svc): void
    {
        $svc->reorderLinie($this->team(), $id, $richtung);
    }

    // ── Zellen-Picker ────────────────────────────────────────────────────

    public function zelleOeffnen(string $datum, ?int $linieId): void
    {
        $this->cellDatum = $datum;
        $this->cellLinie = $linieId;
        $this->pickerSuche = '';
        $this->pickerHauptgruppe = null;
        $this->pickerDishClass = null;
    }

    public function cellSchliessen(): void
    {
        $this->cellDatum = null;
        $this->cellLinie = null;
        $this->pickerSuche = '';
        $this->pickerHauptgruppe = null;
        $this->pickerDishClass = null;
    }

    /** Spec 42: Hauptgruppen-Facette umschalten (klick-erneut = löschen); Unterklasse zurücksetzen. */
    public function pickerWaehleHg(?int $hauptgruppe): void
    {
        $this->pickerHauptgruppe = ($this->pickerHauptgruppe === $hauptgruppe) ? null : $hauptgruppe;
        $this->pickerDishClass = null;
    }

    /** Spec 42: Unterklassen-Facette (dish_class) umschalten. */
    public function pickerWaehleKlasse(?int $dishClassId): void
    {
        $this->pickerDishClass = ($this->pickerDishClass === $dishClassId) ? null : $dishClassId;
    }

    public function inhaltHinzu(string $typ, int $id, SpeiseplanService $svc): void
    {
        if ($this->planId === null || $this->cellDatum === null) {
            return;
        }
        $feld = ['concept' => 'concept_id', 'paket' => 'package_id', 'gericht' => 'sales_recipe_id'][$typ] ?? 'sales_recipe_id';
        $svc->addEintrag($this->team(), $this->planId, [
            'entry_date' => $this->cellDatum, 'line_id' => $this->cellLinie, 'mahlzeit' => $this->mahlzeit, $feld => $id,
        ]);
        $this->pickerSuche = '';
        $this->dispatch('speiseplan-geaendert');
    }

    public function eintragRaus(int $id, SpeiseplanService $svc): void
    {
        $svc->removeEintrag($this->team(), $id);
        $this->dispatch('speiseplan-geaendert');
    }

    /** Stufe C: Pax-Override je Eintrag (leer/0 → Plan-Default gilt). */
    public function setPax(int $id, $wert, SpeiseplanService $svc): void
    {
        $svc->setEintragPax($this->team(), $id, $wert);
        $this->dispatch('speiseplan-geaendert');
    }

    /** Stufe C: die sichtbare Woche + Mahlzeit an die Produktion übergeben (je Werktag ein Auftrag). */
    public function anProduktion(SpeiseplanService $svc): void
    {
        $this->prodHinweis = null;
        $this->prodFehler = null;
        if ($this->planId === null) {
            return;
        }
        try {
            $sp = $svc->detail($this->team(), $this->planId);
            if ($sp === null) {
                return;
            }
            $montag = Carbon::parse($this->montag ?? 'now')->startOfWeek(Carbon::MONDAY);
            $res = $svc->wocheAnProduktion($this->team(), $sp, $this->mahlzeit, $montag, \Illuminate\Support\Facades\Auth::id());
            $this->prodHinweis = $res['auftraege'] > 0
                ? $res['auftraege'] . ' Produktionsauftrag(e) mit ' . $res['ziele'] . ' Ziel(en) angelegt.'
                : 'Nichts zu übergeben — keine Belegung in dieser Woche/Mahlzeit.';
        } catch (\Throwable $e) {
            $this->prodFehler = $e->getMessage();
        }
    }

    public function ausrollen(SpeiseplanService $svc): void
    {
        if ($this->planId === null || $this->ausrollenBis === null) {
            return;
        }
        $n = $svc->vorlageAusrollen($this->team(), $this->planId, $this->ausrollenBis);
        $this->ausrollenInfo = $n > 0 ? "{$n} Einträge ausgerollt." : 'Nichts auszurollen (Vorlage leer oder schon belegt).';
        $this->dispatch('speiseplan-geaendert');
    }

    /**
     * Voll-Kaskade (P5): füllt die leeren Zyklus-Zellen (Mo–Fr × Mittag × Linien) mit erfundenen Gerichten.
     * Legt eine Planungs-Session als Review-Wurzel an und leitet in den Planung-Editor (Fortschritt + Freigabe).
     */
    public function vollKaskadeStarten(
        \Platform\FoodAlchemist\Services\PlanningCascadeService $cascade,
        \Platform\FoodAlchemist\Services\PlanningSessionService $sessions
    ) {
        $this->kaskadeMeldung = null;
        $team = $this->team();
        if ($team === null || $this->planId === null) {
            return null;
        }
        $plan = FoodAlchemistSpeiseplan::visibleToTeam($team)->find($this->planId);
        if ($plan === null) {
            return null;
        }
        try {
            $session = $sessions->create($team, [
                'title' => 'Voll-Kaskade: ' . ($plan->name ?: ('Speiseplan #' . $this->planId)),
                'created_via' => 'speiseplan_vollkaskade',
            ]);
            $cascade->starteKaskade($team, 'vollkaskade', $session, 'voll_kreativ', [
                'owner_type' => 'speiseplan', 'owner_id' => (int) $this->planId, 'created_via' => 'speiseplan_vollkaskade',
            ]);

            return redirect()->route('foodalchemist.planung.index', ['session' => $session->id, 'open' => 1]);
        } catch (\Throwable $e) {
            $this->kaskadeMeldung = $e->getMessage();

            return null;
        }
    }

    public function render(SpeiseplanService $svc)
    {
        $team = $this->team();
        $sp = $this->planId !== null ? $svc->detail($team, $this->planId) : null;

        // Spec 43: Branding + Präsentation nur bei Selektions-WECHSEL laden (kein Edit-Verlust).
        if ($sp !== null && $this->brandingLoadedId !== $sp->id) {
            $this->brandColor = $sp->brand_color ?: '#6d28d9';
            $this->bandColor = $sp->band_color;
            $this->footerText = $sp->footer_text;
            $this->brandingLoadedId = $sp->id;
        }
        if ($sp !== null && $this->presentationLoadedId !== $sp->id) {
            $s = $sp->presentationSettings();
            $this->presentationDesign = $sp->presentation_design ?: 'kiosk';
            $this->presentationGueltigBis = $sp->presentation_expires_at?->format('Y-m-d');
            $this->presentationCtaText = $s['cta']['text'] ?? null;
            $this->presentationCtaLink = $s['cta']['link'] ?? null;
            $this->presentationLoadedId = $sp->id;
        }

        if ($sp !== null && $this->montag === null) {
            $start = $sp->start_date ?? Carbon::now();
            $this->montag = $start->copy()->startOfWeek(Carbon::MONDAY)->format('Y-m-d');
            $this->monatStr = $start->copy()->startOfMonth()->format('Y-m-d');
        }

        $montag = Carbon::parse($this->montag ?? 'now')->startOfWeek(Carbon::MONDAY);
        $wochenTage = [];
        for ($i = 0; $i < 5; $i++) {                  // Mo–Fr (Werktage)
            $wochenTage[] = $montag->copy()->addDays($i);
        }
        $monatStart = Carbon::parse($this->monatStr ?? 'now')->startOfMonth();

        // Spec 42: reicher Zell-Picker (wie Speisekarte/Verkauf-Browser) — Browse ohne Tippzwang,
        // Facetten (Hauptgruppe → Unterklasse) für den gericht-Typ, Kandidaten über den Service.
        $kandidaten = collect();
        $pickerHauptgruppen = collect();
        $pickerUntergruppen = collect();
        if ($sp !== null && $this->cellDatum !== null) {
            $kandidaten = match ($this->pickerTyp) {
                'paket' => $svc->paketKandidaten($team, $this->pickerSuche, 50),
                'concept' => $svc->conceptKandidaten($team, $this->pickerSuche, 50),
                default => $svc->gerichtKandidaten($team, $this->pickerSuche, 50, $this->pickerHauptgruppe, $this->pickerDishClass),
            };
            if ($this->pickerTyp === 'gericht') {
                $pickerHauptgruppen = app(\Platform\FoodAlchemist\Services\SalesRecipeService::class)->dishMainGroups($team);
                if ($this->pickerHauptgruppe !== null) {
                    $pickerUntergruppen = \Platform\FoodAlchemist\Models\FoodAlchemistDishClass::visibleToTeam($team)
                        ->where('dish_main_group_id', $this->pickerHauptgruppe)->orderBy('label')->get(['id', 'label']);
                }
            }
        }

        // Spec 43: Präsentations-Status + Link + Design-Auswahl + aktuelle Branding-Bilder.
        $presentationInfo = null;
        $presentationLink = null;
        $brandingBilder = ['logo' => null, 'cover' => null];
        if ($sp !== null) {
            $presentationInfo = [
                'enabled' => (bool) $sp->presentation_enabled,
                'live' => $sp->isPresentationLive(),
                'published_at' => $sp->presentation_published_at?->format('d.m.Y H:i'),
                'expires_at' => $sp->presentation_expires_at?->format('d.m.Y'),
            ];
            if ($sp->presentation_enabled && $sp->presentation_token) {
                $presentationLink = url('/p/speiseplan/' . $sp->presentation_token);
            }
            $media = app(\Platform\FoodAlchemist\Services\FoodAlchemistMediaService::class);
            if ($sp->logo_context_file_id || $sp->logo_path) {
                $brandingBilder['logo'] = $media->url($sp->logo_context_file_id, $sp->logo_path);
            }
            if ($sp->cover_context_file_id || $sp->cover_image_path) {
                $brandingBilder['cover'] = $media->url($sp->cover_context_file_id, $sp->cover_image_path);
            }
        }
        $presentationDesignOptionen = app(PresentationDesignService::class)->pickerOptions($team, 'speiseplan');
        // Ebene 2 (D3): Kosten/Belegung folgen dem Betrieb (dokument-gebunden ?? aktiver Betrieb).
        $outlet = $sp !== null && $sp->outlet_id !== null
            ? \Platform\FoodAlchemist\Models\FoodAlchemistOutlet::where('team_id', $team->id)->find($sp->outlet_id)
            : ($team !== null ? app(\Platform\FoodAlchemist\Services\ActiveOutletContext::class)->current($team) : null);

        // Slice F: bestehende Betriebs-Aushang-Links + wählbare Betriebe (aktiv, team-scoped).
        $betriebsLinks = [];
        $betriebsOptionen = [];
        if ($sp !== null && $team !== null) {
            $betriebsLinks = app(PresentationService::class)->outletPresentations($team, 'speiseplan', $sp->id);
            $betriebsOptionen = \Platform\FoodAlchemist\Models\FoodAlchemistOutlet::where('team_id', $team->id)
                ->where('is_inactive', false)->orderBy('sort_order')->orderBy('name')->get(['id', 'name'])
                ->map(fn ($o) => ['id' => (int) $o->id, 'name' => (string) $o->name])->all();
        }

        return view('foodalchemist::livewire.speiseplan.editor', [
            'presentationInfo' => $presentationInfo,
            'presentationLink' => $presentationLink,
            'presentationDesignOptionen' => $presentationDesignOptionen,
            'betriebsLinks' => $betriebsLinks,
            'betriebsOptionen' => $betriebsOptionen,
            'brandingBilder' => $brandingBilder,
            'sp' => $sp,
            // Spec 33 P5: das Bauteil erwartet die Ausgabe selbst; `sp` ist derselbe Datensatz,
            // nur unter dem Namen, den das Bauteil in allen drei Editoren benutzt.
            'plan' => $sp,
            'betriebe' => \Platform\FoodAlchemist\Models\FoodAlchemistOutlet::where('team_id', $this->team()->id)
                ->where('is_inactive', false)->orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
            // Der Plan hat kein eigenes Fenster — es steht in seinen Einträgen. Statt eines
            // toten Datumsfelds zeigt das Bauteil den abgeleiteten Zeitraum als Klartext.
            'fensterHinweis' => $sp === null ? null : (
                $sp->gueltigVon() === null
                    ? 'Noch keine Einträge — der Zeitraum ergibt sich aus dem ersten und letzten Plantag.'
                    : $sp->gueltigVon()->format('d.m.Y') . ' – ' . $sp->gueltigBis()?->format('d.m.Y')
                      . ' (aus den Einträgen abgeleitet)'
            ),
            // Spec 33 P3: Hinweis, kein Verbot.
            'portfolioKonflikt' => $sp === null ? null
                : app(\Platform\FoodAlchemist\Services\PortfolioService::class)
                    ->konfliktHinweis($this->team(), 'speiseplan', (int) $sp->id),
            'crmVerfuegbar' => $svc->crmVerfuegbar(),
            'firmen' => $svc->sucheFirmen($this->firmaSuche),
            'kontakte' => $svc->sucheKontakte($this->kontaktSuche),
            'linien' => $sp !== null ? $sp->lines : collect(),
            'wochenTage' => $wochenTage,
            'montagDt' => $montag,
            'monatStart' => $monatStart,
            'raster' => $sp !== null ? $svc->wochenRaster($sp, $this->mahlzeit, $montag) : [],
            'monatsRaster' => $sp !== null ? $svc->monatsRaster($sp, (int) $monatStart->year, (int) $monatStart->month, $this->mahlzeit, $outlet) : [],
            'kosten' => $sp !== null ? $svc->wochenKosten($sp, $this->mahlzeit, $montag, $outlet) : null,
            'veggie' => $sp !== null ? $svc->veggieCheck($sp, $this->mahlzeit, $montag) : null,
            'kostformen' => $sp !== null ? $svc->kostformAbdeckung($sp, $this->mahlzeit, $montag) : [],
            'kennzeichnung' => $sp !== null ? $svc->wochenKennzeichnung($sp, $this->mahlzeit, $montag) : null,
            'naehrwerte' => $sp !== null ? $svc->wochenNaehrwerte($sp, $this->mahlzeit, $montag) : null,
            'abwechslung' => $sp !== null ? $svc->wochenAbwechslung($sp, $this->mahlzeit, $montag) : null,
            'wiederholungen' => $sp !== null ? collect($svc->wiederholungen($sp))->where('konflikt', true)->values()->all() : [],
            'mahlzeiten' => SpeiseplanService::MAHLZEITEN,
            'kandidaten' => $kandidaten,
            'pickerHauptgruppen' => $pickerHauptgruppen,
            'pickerUntergruppen' => $pickerUntergruppen,
        ]);
    }

    private function team()
    {
        return Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
    }
}
