<?php

namespace Platform\FoodAlchemist\Livewire\Speiseplan;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistPaket;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Enums\AusgabeStatus;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplan;
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
    use \Platform\FoodAlchemist\Livewire\Concerns\InteractsWithSavedToast;

    public ?int $planId = null;

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

        return view('foodalchemist::livewire.speiseplan.editor', [
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
            'monatsRaster' => $sp !== null ? $svc->monatsRaster($sp, (int) $monatStart->year, (int) $monatStart->month, $this->mahlzeit) : [],
            'kosten' => $sp !== null ? $svc->wochenKosten($sp, $this->mahlzeit, $montag) : null,
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
