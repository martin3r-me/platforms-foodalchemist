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
                // Spec 57 · 0.4: die Woche und Mahlzeit, die der Mensch gerade sieht — vorher fror der
                // Aushang immer Plan-Startwoche + Mittag ein.
                'mahlzeit' => $this->mahlzeit,
                'montag' => $this->montag,
                // Spec 57 · E9: statt einer festen Woche immer die laufende (Montags-Job erneuert).
                'laufende_woche' => $this->presentationLaufendeWoche,
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
                'mahlzeit' => $this->mahlzeit,
                'montag' => $this->montag,
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

    /** Slice F: einen zurückgezogenen Betriebs-Aushang-Link wieder live nehmen (Snapshot bleibt eingefroren). */
    public function betriebWiederFreigeben(int $outletId): void
    {
        $this->presentationFehler = null;
        $this->presentationHinweis = null;
        if ($this->planId === null) {
            return;
        }
        try {
            app(PresentationService::class)->republishForOutlet($this->team(), 'speiseplan', $this->planId, $outletId, $this->presentationGueltigBis);
            $this->presentationHinweis = 'Betriebs-Link wieder freigegeben — gleiche URL, eingefrorener Aushang bleibt.';
        } catch (\Throwable $e) {
            $this->presentationFehler = $e->getMessage();
        }
    }

    public array $form = ['name' => '', 'start_date' => null, 'cycle_weeks' => 4, 'min_abstand_tage' => 0, 'status' => 'entwurf', 'default_pax' => 100, 'budget_wareneinsatz' => null, 'opening_days' => [1, 2, 3, 4, 5]];

    /** Spec 57 · Paket 1: Zell-Dichte der Matrix — `kompakt` (Kennzahlen) oder `detail` (+ Komponenten). */
    public string $dichte = 'kompakt';

    /** Spec 57 · Paket 10.1: Vorschau vor dem Kaskaden-Start (null = Bestätigung nicht offen). */
    public ?array $kaskadeVorschau = null;

    /** Spec 57 · 0.3: Ausrollen darf belegte Zellen ersetzen (Standard: belegte bleiben unberührt). */
    public bool $ausrollenErsetzen = false;

    // ── Spec 57 · Paket 5: Eintrag-Detail (Ersetzen/Verschieben/Kopieren) + Woche kopieren ──
    public ?int $detailEintragId = null;

    public ?string $verschiebeDatum = null;

    /** Untypisiert: das Select liefert "" für „Ohne Linie“ (wird in eintragVerschieben zu null). */
    public $verschiebeLinie = null;

    /** @var list<string> Zieltage (Y-m-d) für „auf andere Tage kopieren“. */
    public array $kopierTage = [];

    /** Picker im Ersetzen-Modus: dieser Eintrag bekommt den gewählten Inhalt. */
    public ?int $pickerErsetzenId = null;

    public bool $wocheKopierenOffen = false;

    public ?string $wocheKopierenZiel = null;

    public bool $wocheKopierenMerge = false;

    public bool $wocheKopierenPax = true;

    public ?string $umbauHinweis = null;

    // ── Spec 57 · Paket 3: Mengen ──
    public string $mengenFaktor = '1';

    public ?string $mengenHinweis = null;

    // ── Spec 57 · Paket 4: Bedarf (erst auf Knopfdruck — die Stücklisten-Auflösung ist teuer) ──
    public bool $bedarfAn = false;

    /** null = ganze Woche, sonst ein Tag (Y-m-d). */
    public ?string $bedarfTag = null;

    // ── Spec 57 · Paket 6: Druck & Export ──
    public ?string $ausgabeTag = null;

    /** Untypisiert: Select liefert "" für „alle Linien“. */
    public $ausgabeLinie = '';

    public bool $ausgabePreise = false;

    /** Spec 57 · E9: Aushang zeigt immer die laufende Woche (wöchentlich neu eingefroren). */
    public bool $presentationLaufendeWoche = false;

    // ── Spec 57 · Paket 7: Vorlage für Betriebe ──
    /** Untypisiert: Select liefert "" solange kein Betrieb gewählt ist. */
    public $kopieOutletId = '';

    public ?string $vorlageHinweis = null;

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

    public array $linieForm = [
        'name' => '', 'color' => '', 'is_vegetarian' => false,
        // Spec 57 · Paket 2: Linie als Ausgabestelle
        'role' => '', 'plu' => '', 'price_mode' => 'auto', 'price_value' => null,
        'target_wes_min_pct' => null, 'target_wes_max_pct' => null, 'default_pax' => null,
        'is_standing' => false, 'meal' => '',
    ];

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
            'opening_days' => $sp->oeffnungstage(),
        ];
        $this->prodHinweis = null;
        $this->prodFehler = null;
        $this->kaskadeVorschau = null;
        $this->kaskadeMeldung = null;
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
        $this->eintragSchliessen();
        $this->wocheKopierenOffen = false;
    }

    public function heute(): void
    {
        $this->montag = Carbon::now()->startOfWeek(Carbon::MONDAY)->format('Y-m-d');
        $this->cellSchliessen();
        $this->eintragSchliessen();
        $this->wocheKopierenOffen = false;
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

    /** Spec 57 · Paket 1: Zell-Dichte umschalten (kompakt | detail). */
    public function dichteSetzen(string $d): void
    {
        $this->dichte = $d === 'detail' ? 'detail' : 'kompakt';
    }

    /** Spec 57 · Paket 9: einen Öffnungstag (ISO 1–7) an/aus — der letzte bleibt immer an. */
    public function oeffnungstagUmschalten(int $iso, SpeiseplanService $svc): void
    {
        if ($this->planId === null || $iso < 1 || $iso > 7) {
            return;
        }
        $tage = array_map('intval', (array) ($this->form['opening_days'] ?? []));
        $tage = in_array($iso, $tage, true) ? array_values(array_diff($tage, [$iso])) : [...$tage, $iso];
        if ($tage === []) {
            return;
        }
        sort($tage);
        $this->form['opening_days'] = $tage;
        $svc->update($this->team(), $this->planId, ['opening_days' => $tage]);
        $this->dispatch('speiseplan-geaendert');
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
        $this->linieForm = [
            'name' => $linie->name, 'color' => $linie->color ?? '', 'is_vegetarian' => (bool) $linie->is_vegetarian,
            'role' => (string) ($linie->role ?? ''), 'plu' => (string) ($linie->plu ?? ''),
            'price_mode' => $linie->price_mode ?: 'auto', 'price_value' => $linie->price_value,
            'target_wes_min_pct' => $linie->target_wes_min_pct, 'target_wes_max_pct' => $linie->target_wes_max_pct,
            'default_pax' => $linie->default_pax, 'is_standing' => (bool) $linie->is_standing,
            'meal' => (string) ($linie->meal ?? ''),
        ];
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
        $this->pickerErsetzenId = null;
    }

    // ── Spec 57 · Paket 5: Umbauen ─────────────────────────────────────────

    /** Eintrag-Detail öffnen (Klick auf den Eintrag oder Enter) — Tastatur-Weg zu allen Aktionen. */
    public function eintragOeffnen(int $id, SpeiseplanService $svc): void
    {
        $e = $this->eintragDesPlans($svc, $id);
        if ($e === null) {
            return;
        }
        $this->detailEintragId = $id;
        $this->verschiebeDatum = $e->entry_date?->format('Y-m-d');
        $this->verschiebeLinie = $e->line_id !== null ? (int) $e->line_id : null;
        $this->kopierTage = [];
        $this->umbauHinweis = null;
    }

    public function eintragSchliessen(): void
    {
        $this->detailEintragId = null;
        $this->kopierTage = [];
    }

    /** Drag & Drop in der Matrix (und „Verschieben“ im Detail): Eintrag in Zelle Datum × Linie legen. */
    public function eintragVerschieben(int $id, string $datum, $lineId, SpeiseplanService $svc): void
    {
        if ($this->planId === null || $this->eintragDesPlans($svc, $id) === null) {
            return;
        }
        $lineId = (int) $lineId > 0 ? (int) $lineId : null;
        $svc->verschiebeEintrag($this->team(), $id, $datum, $lineId, $this->mahlzeit);
        $this->umbauHinweis = 'Eintrag verschoben.';
        $this->dispatch('speiseplan-geaendert');
    }

    public function eintragVerschiebenAusDetail(SpeiseplanService $svc): void
    {
        if ($this->detailEintragId === null || $this->verschiebeDatum === null) {
            return;
        }
        $this->eintragVerschieben($this->detailEintragId, $this->verschiebeDatum, $this->verschiebeLinie, $svc);
    }

    /** Ersetzen: öffnet den Picker an der Zelle des Eintrags; die Auswahl tauscht den Inhalt. */
    public function eintragErsetzenStarten(int $id, SpeiseplanService $svc): void
    {
        $e = $this->eintragDesPlans($svc, $id);
        if ($e === null) {
            return;
        }
        $this->zelleOeffnen((string) $e->entry_date?->format('Y-m-d'), $e->line_id !== null ? (int) $e->line_id : null);
        $this->pickerErsetzenId = $id;
    }

    public function eintragKopieren(SpeiseplanService $svc): void
    {
        if ($this->detailEintragId === null || $this->kopierTage === []) {
            $this->umbauHinweis = 'Mindestens einen Zieltag wählen.';

            return;
        }
        $n = $svc->kopiereEintrag($this->team(), $this->detailEintragId, $this->kopierTage);
        $this->kopierTage = [];
        $this->umbauHinweis = $n > 0 ? "Auf {$n} Tag(e) kopiert." : 'Nichts kopiert — der Inhalt steht dort schon.';
        $this->dispatch('speiseplan-geaendert');
    }

    public function wocheKopierenOeffnen(): void
    {
        $this->wocheKopierenOffen = true;
        $this->wocheKopierenZiel = Carbon::parse($this->montag ?? 'now')->startOfWeek(Carbon::MONDAY)->addWeek()->format('Y-m-d');
        $this->umbauHinweis = null;
    }

    public function wocheKopieren(SpeiseplanService $svc): void
    {
        if ($this->planId === null || $this->wocheKopierenZiel === null) {
            return;
        }
        try {
            $res = $svc->kopiereWoche($this->team(), $this->planId, (string) $this->montag, $this->wocheKopierenZiel, $this->wocheKopierenMerge, $this->wocheKopierenPax);
            $ziel = Carbon::parse($this->wocheKopierenZiel);
            $this->umbauHinweis = $res['kopiert'] . ' Einträge nach KW ' . $ziel->isoWeek() . ' kopiert'
                . ($res['ersetzt'] > 0 ? ', ' . $res['ersetzt'] . ' ersetzt' : '') . '.';
            $this->wocheKopierenOffen = false;
            $this->dispatch('speiseplan-geaendert');
        } catch (\RuntimeException $e) {
            $this->umbauHinweis = $e->getMessage();
        }
    }

    // ── Spec 57 · Paket 3: Mengen ──────────────────────────────────────────

    public function mengenSetzen(int $lineId, string $datum, $wert, SpeiseplanService $svc): void
    {
        if ($this->planId === null) {
            return;
        }
        $svc->setzeZellenPax($this->team(), $this->planId, $lineId, $datum, $this->mahlzeit, $wert);
        $this->mengenHinweis = null;
        $this->dispatch('speiseplan-geaendert');
    }

    public function mengenVorwoche(SpeiseplanService $svc): void
    {
        if ($this->planId === null) {
            return;
        }
        $n = $svc->uebernehmeVorwoche($this->team(), $this->planId, $this->mahlzeit, Carbon::parse($this->montag ?? 'now'));
        $this->mengenHinweis = $n > 0 ? "Mengen aus der Vorwoche übernommen ({$n} Einträge)." : 'Die Vorwoche hat für diese Linien keine Mengen.';
        $this->dispatch('speiseplan-geaendert');
    }

    public function mengenSkalieren(SpeiseplanService $svc): void
    {
        if ($this->planId === null) {
            return;
        }
        $faktor = (float) str_replace(',', '.', $this->mengenFaktor);
        try {
            $n = $svc->skaliereWoche($this->team(), $this->planId, $this->mahlzeit, Carbon::parse($this->montag ?? 'now'), $faktor);
            $this->mengenHinweis = "{$n} Einträge mit Faktor " . str_replace('.', ',', (string) $faktor) . ' skaliert.';
            $this->mengenFaktor = '1';
            $this->dispatch('speiseplan-geaendert');
        } catch (\RuntimeException $e) {
            $this->mengenHinweis = $e->getMessage();
        }
    }

    // ── Spec 57 · Paket 7: Vorlage für Betriebe ──────────────────────────────

    public function vorlageUmschalten(SpeiseplanService $svc): void
    {
        $this->vorlageHinweis = null;
        $plan = $this->planId !== null ? FoodAlchemistSpeiseplan::visibleToTeam($this->team())->find($this->planId) : null;
        if ($plan === null) {
            return;
        }
        try {
            $svc->setzeVorlage($this->team(), $this->planId, ! $plan->is_template);
            $this->vorlageHinweis = ! $plan->is_template ? 'Als Vorlage für Betriebe freigegeben.' : 'Vorlage zurückgenommen — bestehende Kopien bleiben verknüpft.';
        } catch (\RuntimeException $e) {
            $this->vorlageHinweis = $e->getMessage();
        }
    }

    public function betriebsKopieAnlegen(SpeiseplanService $svc): void
    {
        $this->vorlageHinweis = null;
        if ($this->planId === null || (int) $this->kopieOutletId <= 0) {
            $this->vorlageHinweis = 'Bitte einen Betrieb wählen.';

            return;
        }
        try {
            $kopie = $svc->betriebsKopieAnlegen($this->team(), $this->planId, (int) $this->kopieOutletId);
            $this->vorlageHinweis = 'Kopie „' . $kopie->name . '“ angelegt (Entwurf).';
            $this->kopieOutletId = '';
            $this->dispatch('speiseplan-geaendert');
        } catch (\RuntimeException $e) {
            $this->vorlageHinweis = $e->getMessage();
        }
    }

    /** Änderungen der Vorlage in diese Betriebs-Kopie übernehmen — alle oder eine Zelle ($key). */
    public function ausVorlageUebernehmen(?string $key, SpeiseplanService $svc): void
    {
        $this->vorlageHinweis = null;
        if ($this->planId === null) {
            return;
        }
        try {
            $n = $svc->ausVorlageUebernehmen($this->team(), $this->planId, $key !== null && $key !== '' ? [$key] : null);
            $this->vorlageHinweis = $n > 0 ? "{$n} Zelle(n) aus der Vorlage übernommen." : 'Keine Änderungen aus der Vorlage offen.';
            $this->dispatch('speiseplan-geaendert');
        } catch (\RuntimeException $e) {
            $this->vorlageHinweis = $e->getMessage();
        }
    }

    // ── Spec 57 · Paket 4: Bedarf ──────────────────────────────────────────

    public function bedarfBerechnen(): void
    {
        $this->bedarfAn = true;
    }

    public function bedarfTagSetzen(?string $tag): void
    {
        $this->bedarfTag = $tag !== null && $tag !== '' ? Carbon::parse($tag)->format('Y-m-d') : null;
        $this->bedarfAn = true;
    }

    /** Eintrag nur, wenn er zu DIESEM Plan gehört (Payload-IDs aus dem Browser nie blind nehmen). */
    private function eintragDesPlans(SpeiseplanService $svc, int $id): ?\Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplanEintrag
    {
        if ($this->planId === null) {
            return null;
        }

        return \Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplanEintrag::visibleToTeam($this->team())
            ->where('menu_plan_id', $this->planId)->find($id);
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
        // Spec 57 · Paket 5: Picker im Ersetzen-Modus tauscht den Inhalt statt einen neuen Eintrag anzulegen.
        if ($this->pickerErsetzenId !== null && $this->eintragDesPlans($svc, $this->pickerErsetzenId) !== null) {
            $svc->ersetzeEintrag($this->team(), $this->pickerErsetzenId, [$feld => $id]);
            $this->umbauHinweis = 'Eintrag ersetzt.';
            $this->cellSchliessen();
            $this->dispatch('speiseplan-geaendert');

            return;
        }
        $svc->addEintrag($this->team(), $this->planId, [
            'entry_date' => $this->cellDatum, 'line_id' => $this->cellLinie, 'mahlzeit' => $this->mahlzeit, $feld => $id,
        ]);
        $this->pickerSuche = '';
        $this->dispatch('speiseplan-geaendert');
    }

    public function eintragRaus(int $id, SpeiseplanService $svc): void
    {
        $svc->removeEintrag($this->team(), $id);
        if ($this->detailEintragId === $id) {
            $this->eintragSchliessen();
        }
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
            // Spec 57 · 0.2: ein zweiter Klick aktualisiert statt zu verdoppeln — und sagt das auch.
            $teile = array_filter([
                $res['auftraege'] > 0 ? $res['auftraege'] . ' Auftrag/Aufträge angelegt' : null,
                ($res['aktualisiert'] ?? 0) > 0 ? $res['aktualisiert'] . ' aktualisiert' : null,
                ($res['gesperrt'] ?? 0) > 0 ? $res['gesperrt'] . ' schon in Produktion (unverändert)' : null,
            ]);
            $this->prodHinweis = $teile !== []
                ? implode(' · ', $teile) . ' — ' . $res['ziele'] . ' Ziel(e).'
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
        $n = $svc->vorlageAusrollen($this->team(), $this->planId, $this->ausrollenBis, $this->ausrollenErsetzen);
        $this->ausrollenInfo = $n > 0 ? "{$n} Einträge ausgerollt." : 'Nichts auszurollen (Vorlage leer oder schon belegt).';
        $this->dispatch('speiseplan-geaendert');
    }

    /**
     * Spec 57 · Paket 10.1: erster Klick auf „Voll-Kaskade“ — zeigt, was passieren würde (leere
     * Zellen, Zell-Läufe dieses Laufs, Deckel), statt sofort KI-Läufe zu starten. Erst „Starten“
     * im Bestätigungsfeld ruft {@see vollKaskadeStarten}.
     */
    public function vollKaskadePruefen(\Platform\FoodAlchemist\Services\PlanningCascadeService $cascade): void
    {
        $this->kaskadeMeldung = null;
        $this->kaskadeVorschau = null;
        if ($this->planId === null) {
            return;
        }
        try {
            $v = $cascade->speiseplanVorschau($this->team(), $this->planId);
        } catch (\Throwable $e) {
            $this->kaskadeMeldung = $e->getMessage();

            return;
        }
        if ($v['linien'] === 0) {
            $this->kaskadeMeldung = 'Speiseplan hat keine Menü-Linien — erst Linien anlegen.';

            return;
        }
        if ($v['leer'] === 0) {
            $this->kaskadeMeldung = 'Alle Zellen des Zyklus sind belegt — nichts zu füllen.';

            return;
        }
        $this->kaskadeVorschau = $v;
    }

    public function vollKaskadeAbbrechen(): void
    {
        $this->kaskadeVorschau = null;
    }

    /**
     * Voll-Kaskade (P5): füllt die leeren Zyklus-Zellen (Öffnungstage × Linien, jede Linie in ihrer
     * Mahlzeit) mit erfundenen Gerichten. Legt eine Planungs-Session als Review-Wurzel an und leitet
     * in den Planung-Editor (Fortschritt + Freigabe).
     */
    public function vollKaskadeStarten(
        \Platform\FoodAlchemist\Services\PlanningCascadeService $cascade,
        \Platform\FoodAlchemist\Services\PlanningSessionService $sessions
    ) {
        $this->kaskadeMeldung = null;
        $this->kaskadeVorschau = null;
        $team = $this->team();
        if ($team === null || $this->planId === null) {
            return null;
        }
        $plan = FoodAlchemistSpeiseplan::visibleToTeam($team)->find($this->planId);
        if ($plan === null) {
            return null;
        }
        // Backlog #54: ohne Linien gar nicht erst eine Session anlegen (sonst blieb sie verwaist).
        if ($plan->lines()->count() === 0) {
            $this->kaskadeMeldung = 'Speiseplan hat keine Menü-Linien — erst Linien anlegen.';

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
            $this->presentationLaufendeWoche = (bool) ($s['laufende_woche'] ?? false);
            $this->presentationLoadedId = $sp->id;
        }

        if ($sp !== null && $this->montag === null) {
            $start = $sp->start_date ?? Carbon::now();
            $this->montag = $start->copy()->startOfWeek(Carbon::MONDAY)->format('Y-m-d');
            $this->monatStr = $start->copy()->startOfMonth()->format('Y-m-d');
        }

        $montag = Carbon::parse($this->montag ?? 'now')->startOfWeek(Carbon::MONDAY);
        // Spec 57 · Paket 9: Spalten = Öffnungstage des Plans (Standard Mo–Fr).
        $wochenTage = $sp !== null
            ? $svc->wochenTage($sp, $montag)
            : array_map(fn ($i) => $montag->copy()->addDays($i), range(0, 4));
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
                // Bug-Runde 2026-09-17 #2: Design nach der Veröffentlichung geändert → Link hinkt hinterher.
                'design_veraltet' => app(PresentationService::class)->designGeaendertSeitPublish($sp),
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

        // Spec 57 · Paket 1: Kennzahlen je Zelle/Tag/Linie in EINEM Service-Aufruf (keine Rechnung im Blade).
        $kosten = $sp !== null ? $svc->wochenKosten($sp, $this->mahlzeit, $montag, $outlet) : null;
        $zk = $sp !== null
            ? $svc->zellenKennzahlen($team, $sp, $this->mahlzeit, $montag, $outlet, $this->ansicht === 'woche' && $this->dichte === 'detail')
            : null;
        // Spec 57 · Paket 5: Eintrag-Detail (nur Einträge dieses Plans; verschwundener Eintrag schließt es).
        $detailEintrag = $sp !== null && $this->detailEintragId !== null ? $sp->entries->firstWhere('id', $this->detailEintragId) : null;
        if ($this->detailEintragId !== null && $detailEintrag === null) {
            $this->detailEintragId = null;
        }
        $detailKennzahlen = $detailEintrag !== null
            ? ($zk['eintraege'][$detailEintrag->id] ?? null)
            : null;
        // Spec 57 · Paket 3: Mengen-Matrix der sichtbaren Woche/Mahlzeit.
        $mengen = $sp !== null ? $svc->mengenMatrix($team, $sp, $this->mahlzeit, $montag, $outlet) : null;
        $zielWochen = collect([-4, -3, -2, -1, 1, 2, 3, 4, 5, 6, 7, 8])
            ->map(fn (int $w) => $montag->copy()->addWeeks($w))->all();
        // Spec 57 · Paket 7: Vorlage (Kopien + Stand) bzw. Kopie (Abgleich mit der Vorlage).
        $betriebsKopien = $sp !== null && $sp->is_template ? $svc->betriebsKopien($team, $sp->id) : [];
        $vorlagenAbgleich = $sp !== null && $sp->source_plan_id !== null ? $svc->vorlagenAbgleich($team, $sp->id) : null;
        // Spec 57 · Paket 8: Plan/Ist der sichtbaren Woche (eine Abfrage aufs Verkaufsjournal).
        $planIst = $sp !== null ? $svc->planIst($team, $sp, $this->mahlzeit, $montag, $outlet) : null;
        // Spec 57 · Paket 4: Bedarf nur, wenn angefordert (Stücklisten-Auflösung bis GP-Ebene).
        $bedarf = $sp !== null && $this->bedarfAn
            ? $svc->wochenBedarf($team, $sp, $this->mahlzeit, $montag, $this->bedarfTag)
            : null;
        // Spec 57 · Paket 6: Druck-Links der sichtbaren Woche/Mahlzeit (Tag Standard = erster Öffnungstag).
        $ausgabeTag = $this->ausgabeTag !== null && collect($wochenTage)->contains(fn ($t) => $t->format('Y-m-d') === $this->ausgabeTag)
            ? $this->ausgabeTag
            : ($wochenTage[0] ?? $montag)->format('Y-m-d');
        $ausgabeLinks = [];
        if ($sp !== null) {
            $basis = ['id' => $sp->id, 'mahlzeit' => $this->mahlzeit, 'montag' => $montag->format('Y-m-d')] + ($this->ausgabePreise ? ['preise' => 1] : []);
            $linie = (int) $this->ausgabeLinie > 0 ? ['linie' => (int) $this->ausgabeLinie] : [];
            $ausgabeLinks = [
                'woche' => route('foodalchemist.speiseplan.dokument', $basis),
                'tag' => route('foodalchemist.speiseplan.dokument', $basis + ['format' => 'tag', 'tag' => $ausgabeTag]),
                'schild' => route('foodalchemist.speiseplan.dokument', $basis + ['format' => 'schild', 'tag' => $ausgabeTag] + $linie),
                'buffet' => route('foodalchemist.speiseplan.dokument', $basis + ['format' => 'buffet', 'tag' => $ausgabeTag] + $linie),
                'liste_woche' => route('foodalchemist.speiseplan.dokument', $basis + ['format' => 'liste']),
                'liste_tag' => route('foodalchemist.speiseplan.dokument', $basis + ['format' => 'liste', 'tag' => $ausgabeTag]),
                'csv' => route('foodalchemist.speiseplan.dokument', ['id' => $sp->id, 'mahlzeit' => $this->mahlzeit, 'montag' => $montag->format('Y-m-d'), 'format' => 'csv']),
            ];
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
            // Spec 57 · E11: Matrix-Zeilen = Linien DIESER Mahlzeit (ohne feste Mahlzeit: überall).
            'matrixLinien' => $sp !== null ? $sp->lines->filter(fn ($l) => $l->giltFuerMahlzeit($this->mahlzeit))->values() : collect(),
            'rollen' => \Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplanLinie::ROLLEN,
            'wochenTage' => $wochenTage,
            'montagDt' => $montag,
            'monatStart' => $monatStart,
            'raster' => $sp !== null ? $svc->wochenRaster($sp, $this->mahlzeit, $montag) : [],
            'monatsRaster' => $sp !== null ? $svc->monatsRaster($sp, (int) $monatStart->year, (int) $monatStart->month, $this->mahlzeit, $outlet) : [],
            'kosten' => $kosten,
            'zk' => $zk,
            'detailEintrag' => $detailEintrag,
            'detailKennzahlen' => $detailKennzahlen,
            'mengen' => $mengen,
            'zielWochen' => $zielWochen,
            'bedarf' => $bedarf,
            'betriebsKopien' => $betriebsKopien,
            'vorlagenAbgleich' => $vorlagenAbgleich,
            'planIst' => $planIst,
            'ausgabeLinks' => $ausgabeLinks,
            'ausgabeTagEffektiv' => $ausgabeTag,
            'budget' => $sp !== null && $zk !== null && $kosten !== null ? $svc->budgetAmpel($sp, $zk, $kosten) : null,
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

    // ── Spec 59: Vorgaben je Woche (Reiter Stammdaten, Partial `partials/vorgaben`) ──
    // Kein eigener Formular-State: jede Änderung schreibt sofort über den Service (gleiche
    // Validierung wie MCP `speiseplaene.PUT`), das Partial liest `$sp->vorgaben`.

    /** Untypisiert: Select liefert "" solange kein Chip gewählt ist. */
    public $neueVorgabeChip = '';

    public ?string $vorgabenFehler = null;

    /** Sichtbarer Chip-Katalog des Teams (inkl. stillgelegter — bestehende Vorgaben brauchen ihr Label). */
    #[\Livewire\Attributes\Computed]
    public function vorgabenKatalog(): \Illuminate\Support\Collection
    {
        return app(\Platform\FoodAlchemist\Services\SpeiseplanVorgabenService::class)->katalog($this->team());
    }

    /** Vorgabe aus dem gewählten Chip hinzufügen — übernimmt dessen Standardwerte (mind./höchstens). */
    public function vorgabeHinzu(): void
    {
        $chip = $this->vorgabenKatalog->first(fn ($c) => (int) $c->id === (int) $this->neueVorgabeChip && $c->is_active);
        if ($chip === null) {
            $this->vorgabenFehler = 'Bitte einen Chip wählen.';

            return;
        }
        $svc = app(\Platform\FoodAlchemist\Services\SpeiseplanVorgabenService::class);
        if ($this->vorgabenSchreiben(fn (array $v) => [...$v, $svc->vorgabeAusChip($chip)])) {
            $this->neueVorgabeChip = '';
        }
    }

    /** Ein Feld einer Vorgabe setzen: chip_id | mahlzeit | min | max (Index = Position in der Liste). */
    public function vorgabeSetzen(int $index, string $feld, $wert): void
    {
        if (! in_array($feld, ['chip_id', 'mahlzeit', 'min', 'max'], true)) {
            return;
        }
        $this->vorgabenSchreiben(function (array $v) use ($index, $feld, $wert) {
            if (isset($v[$index])) {
                $v[$index][$feld] = $feld === 'chip_id' ? (int) $wert : $wert;
            }

            return $v;
        });
    }

    public function vorgabeEntfernen(int $index): void
    {
        $this->vorgabenSchreiben(function (array $v) use ($index) {
            unset($v[$index]);

            return array_values($v);
        });
    }

    /** Liest die gespeicherten Vorgaben, wendet $aendern an und speichert validiert. */
    private function vorgabenSchreiben(\Closure $aendern): bool
    {
        $this->vorgabenFehler = null;
        if ($this->planId === null) {
            return false;
        }
        $plan = FoodAlchemistSpeiseplan::visibleToTeam($this->team())->find($this->planId);
        if ($plan === null) {
            return false;
        }
        try {
            app(\Platform\FoodAlchemist\Services\SpeiseplanVorgabenService::class)
                ->setzeVorgaben($this->team(), $this->planId, $aendern(array_values((array) ($plan->vorgaben ?? []))));
        } catch (\RuntimeException $e) {
            $this->vorgabenFehler = $e->getMessage();

            return false;
        }

        return true;
    }
}
