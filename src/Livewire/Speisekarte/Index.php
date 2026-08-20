<?php

namespace Platform\FoodAlchemist\Livewire\Speisekarte;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Platform\FoodAlchemist\Enums\AusgabeStatus;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarte;
use Platform\FoodAlchemist\Services\SpeisekarteService;

/**
 * Speisekarte-Editor (Stufe A) — Browser links + Karten-Editor rechts (Rubrik-Baum,
 * Gericht-Picker, Live-Preis). Dritte Ausgabeform neben Foodbook + Speiseplan.
 * Der Branding-Tab (Stufe C), Fix-Menü/Getränk-Picker (Stufe D) und das
 * Leitstelle-Cockpit (Stufe E) docken hier später an.
 */
class Index extends Component
{
    use WithFileUploads, WithPagination;
    use \Platform\FoodAlchemist\Livewire\Concerns\InteractsWithSavedToast;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'sk')]
    public ?int $karteId = null;

    /** Meldung des Voll-Kaskade-Go (P4). */
    public ?string $kaskadeMeldung = null;

    // Karten-Meta (Editor)
    public string $name = '';
    public string $kartenTyp = 'alacarte';
    public string $status = 'entwurf';
    public ?string $gueltigVon = null;
    public ?string $gueltigBis = null;
    // Spec 33 P2/P5: Betriebsachse optional; Kunde ist CRM-only.
    public ?int $outletId = null;

    // Werkstrang M Phase A (Spec 40 §6): Kontext-Leitplanken der Karte — wirken als Defaults nach unten
    // (kiWordingVorschlag/kiKartenText lesen default_niveau/kundentyp bereits als Leitplanken).
    public ?string $kundentyp = null;
    public ?string $niveau = null;          // → default_niveau (buergerlich|gehoben|fine_dining)
    public ?string $convenience = null;     // → default_convenience (from_scratch|teil_convenience|voll_convenience)
    public ?int $writingStyleId = null;     // → writing_style_id

    public string $firmaSuche = '';
    public string $kontaktSuche = '';

    // Rubrik-Anlage
    public string $neueRubrik = '';

    // Gericht-/Menü-Picker
    public string $pickerSuche = '';
    public ?int $pickerRubrikId = null;
    public string $pickerModus = 'gericht'; // gericht | menue

    // Positions-Bearbeitung (inline)
    public ?int $editPosId = null;
    public ?string $editWording = null;
    public ?string $editConsumerText = null;
    public string $editPriceMode = 'auto';
    public ?string $editPriceValue = null;

    // Branding (Stufe C)
    public string $brandColor = '#6d28d9';
    public ?string $bandColor = null;
    public ?string $footerText = null;
    public $logoUpload = null;
    public $coverUpload = null;
    public ?string $logoPath = null;
    public ?string $coverPath = null;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function neu(SpeisekarteService $svc): void
    {
        $karte = $svc->create($this->team(), ['name' => 'Neue Speisekarte']);
        $this->waehle($karte->id);
    }

    public function waehle(int $id): void
    {
        $karte = FoodAlchemistSpeisekarte::visibleToTeam($this->team())->find($id);
        if (! $karte) {
            return;
        }
        $this->karteId = $id;
        $this->name = $karte->name;
        $this->kartenTyp = $karte->karten_typ;
        // Spec 33 P0: `status` ist gecastet — die Livewire-Property ist ein String.
        $this->status = $karte->statusWert()->value;
        $this->gueltigVon = $karte->gueltig_von?->format('Y-m-d');
        $this->gueltigBis = $karte->gueltig_bis?->format('Y-m-d');
        $this->outletId = $karte->outlet_id;
        // Werkstrang M Phase A: Kontext-Leitplanken hydrieren.
        $this->kundentyp = $karte->kundentyp;
        $this->niveau = $karte->default_niveau;
        $this->convenience = $karte->default_convenience;
        $this->writingStyleId = $karte->writing_style_id !== null ? (int) $karte->writing_style_id : null;
        $this->editPosId = null;
        $this->brandColor = $karte->brand_color ?: '#6d28d9';
        $this->bandColor = $karte->band_color;
        $this->footerText = $karte->footer_text;
        $this->logoPath = $karte->logo_path;
        $this->coverPath = $karte->cover_image_path;
        $this->logoUpload = null;
        $this->coverUpload = null;
        $this->pickerRubrikId = null;
        $this->pickerSuche = '';
    }

    public function schliessen(): void
    {
        $this->karteId = null;
    }

    public function speichern(SpeisekarteService $svc): void
    {
        if (! $this->karteId) {
            return;
        }
        $svc->update($this->team(), $this->karteId, [
            'name' => $this->name,
            'karten_typ' => $this->kartenTyp,
            'status' => $this->status,
            'gueltig_von' => $this->gueltigVon ?: null,
            'gueltig_bis' => $this->gueltigBis ?: null,
            'outlet_id' => $this->outletId ?: null,
            // Werkstrang M Phase A: Kontext-Leitplanken mitschreiben.
            'kundentyp' => $this->kundentyp ?: null,
            'default_niveau' => $this->niveau ?: null,
            'default_convenience' => $this->convenience ?: null,
            'writing_style_id' => $this->writingStyleId ?: null,
        ]);
        $this->dispatch('gespeichert');
        $this->savedToast('Speisekarte gespeichert');
    }

    public function verknuepfeFirma(int $companyId, SpeisekarteService $svc): void
    {
        if ($this->karteId === null) {
            return;
        }
        $karte = $svc->detail($this->team(), $this->karteId);
        $svc->verknuepfeKunde($this->team(), $this->karteId, $companyId, $karte?->crm_contact_id);
        $this->firmaSuche = '';
    }

    public function verknuepfeKontakt(int $contactId, SpeisekarteService $svc): void
    {
        if ($this->karteId === null) {
            return;
        }
        $karte = $svc->detail($this->team(), $this->karteId);
        $svc->verknuepfeKunde($this->team(), $this->karteId, $karte?->crm_company_id, $contactId);
        $this->kontaktSuche = '';
    }

    public function loeseKunde(SpeisekarteService $svc): void
    {
        if ($this->karteId === null) {
            return;
        }
        $svc->verknuepfeKunde($this->team(), $this->karteId, null, null);
    }

    /** Spec 33 P5 — Schnellschalter aktiv ⇄ inaktiv (ohne Umweg über das Dropdown, ohne Archiv). */
    public function aktivUmschalten(SpeisekarteService $svc): void
    {
        $karte = $this->karteId !== null
            ? FoodAlchemistSpeisekarte::visibleToTeam($this->team())->find($this->karteId) : null;
        if ($karte === null) {
            return;
        }

        $neu = $karte->statusWert() === AusgabeStatus::Aktiv ? AusgabeStatus::Inaktiv : AusgabeStatus::Aktiv;
        $svc->update($this->team(), $this->karteId, ['status' => $neu->value]);
        $this->status = $neu->value;
    }

    public function duplizieren(SpeisekarteService $svc): void
    {
        if (! $this->karteId) {
            return;
        }
        $neu = $svc->dupliziere($this->team(), $this->karteId);
        $this->waehle($neu->id);
    }

    public function loeschen(SpeisekarteService $svc): void
    {
        if (! $this->karteId) {
            return;
        }
        $svc->delete($this->team(), $this->karteId);
        $this->karteId = null;
    }

    // ── Branding (Stufe C) ────────────────────────────────────────────────────

    public function brandingSpeichern(SpeisekarteService $svc): void
    {
        if (! $this->karteId) {
            return;
        }
        try {
            $svc->setBranding($this->team(), $this->karteId, [
                'brand_color' => $this->brandColor,
                'band_color' => $this->bandColor ?? '',
                'footer_text' => $this->footerText ?? '',
            ]);
            $this->dispatch('gespeichert');
        } catch (\RuntimeException $e) {
            $this->addError('brandColor', $e->getMessage());
        }
    }

    public function updatedLogoUpload(SpeisekarteService $svc): void
    {
        if (! $this->karteId || ! $this->logoUpload) {
            return;
        }
        $this->validate(['logoUpload' => 'image|max:4096']);
        $this->logoPath = $svc->storeLogo($this->team(), $this->karteId, $this->logoUpload);
        $this->logoUpload = null;
    }

    public function updatedCoverUpload(SpeisekarteService $svc): void
    {
        if (! $this->karteId || ! $this->coverUpload) {
            return;
        }
        $this->validate(['coverUpload' => 'image|max:8192']);
        $this->coverPath = $svc->storeCover($this->team(), $this->karteId, $this->coverUpload);
        $this->coverUpload = null;
    }

    public function brandingLogoEntfernen(SpeisekarteService $svc): void
    {
        if ($this->karteId) {
            $svc->clearLogo($this->team(), $this->karteId);
            $this->logoPath = null;
        }
    }

    public function brandingCoverEntfernen(SpeisekarteService $svc): void
    {
        if ($this->karteId) {
            $svc->clearCover($this->team(), $this->karteId);
            $this->coverPath = null;
        }
    }

    public function rubrikNeu(SpeisekarteService $svc, ?int $parentId = null): void
    {
        if (! $this->karteId || trim($this->neueRubrik) === '') {
            return;
        }
        $svc->addRubrik($this->team(), $this->karteId, ['title' => trim($this->neueRubrik)], $parentId);
        $this->neueRubrik = '';
    }

    public function rubrikLoeschen(SpeisekarteService $svc, int $rubrikId): void
    {
        $svc->deleteRubrik($this->team(), $rubrikId);
    }

    /** Gericht-/Menü-Picker für eine Rubrik öffnen/schließen. */
    public function pickerOeffnen(int $rubrikId, string $modus = 'gericht'): void
    {
        if ($this->pickerRubrikId === $rubrikId && $this->pickerModus === $modus) {
            $this->pickerRubrikId = null;
        } else {
            $this->pickerRubrikId = $rubrikId;
            $this->pickerModus = in_array($modus, ['gericht', 'menue'], true) ? $modus : 'gericht';
        }
        $this->pickerSuche = '';
    }

    public function positionAusGericht(SpeisekarteService $svc, int $rubrikId, int $recipeId): void
    {
        $svc->addPosition($this->team(), $rubrikId, [
            'type' => 'gericht_ref', 'sales_recipe_id' => $recipeId,
        ]);
        $this->pickerSuche = '';
    }

    public function positionAusMenue(SpeisekarteService $svc, int $rubrikId, int $conceptId): void
    {
        $svc->addPosition($this->team(), $rubrikId, [
            'type' => 'menue_ref', 'concept_id' => $conceptId,
        ]);
        $this->pickerSuche = '';
    }

    public function positionLoeschen(SpeisekarteService $svc, int $positionId): void
    {
        $svc->deletePosition($this->team(), $positionId);
        if ($this->editPosId === $positionId) {
            $this->editPosId = null;
        }
    }

    // ── Positions-Bearbeitung (Wording-Override + manueller Preis) ─────────────

    public function positionBearbeiten(int $positionId): void
    {
        $pos = \Platform\FoodAlchemist\Models\FoodAlchemistSpeisekartePosition::visibleToTeam($this->team())->find($positionId);
        if (! $pos) {
            return;
        }
        $this->editPosId = $positionId;
        $this->editWording = $pos->wording;
        $this->editConsumerText = $pos->consumer_text;
        $this->editPriceMode = $pos->price_mode ?: 'auto';
        $this->editPriceValue = $pos->price_value !== null ? (string) $pos->price_value : null;
    }

    public function positionSpeichern(SpeisekarteService $svc): void
    {
        if (! $this->editPosId) {
            return;
        }
        $svc->updatePosition($this->team(), $this->editPosId, [
            'wording' => $this->editWording ?: null,
            'consumer_text' => $this->editConsumerText ?: null,
            'price_mode' => $this->editPriceMode,
            'price_value' => $this->editPriceMode === 'manuell' ? ($this->editPriceValue !== null && $this->editPriceValue !== '' ? (float) str_replace(',', '.', $this->editPriceValue) : null) : null,
        ]);
        $this->editPosId = null;
    }

    public function positionAbbrechen(): void
    {
        $this->editPosId = null;
    }

    // ── KI-Wording (Stufe E) ──────────────────────────────────────────────────

    public ?string $kiKartenVorschau = null;

    /** KI-Wording-Vorschlag für die gerade bearbeitete Position → Vorschau ins Feld (nicht gespeichert). */
    public function kiWording(SpeisekarteService $svc): void
    {
        if (! $this->editPosId) {
            return;
        }
        try {
            $r = $svc->kiWordingVorschlag($this->team(), $this->editPosId);
            $this->editWording = $r['text'];
        } catch (\Platform\FoodAlchemist\Exceptions\KiNichtVerfuegbarException $e) {
            $this->addError('editWording', 'KI derzeit nicht verfügbar.');
        } catch (\RuntimeException $e) {
            $this->addError('editWording', $e->getMessage());
        }
    }

    /** KI-Einleitungstext für die Karte → Vorschau (Übernehmen speichert in description). */
    public function kiKartenText(SpeisekarteService $svc): void
    {
        if (! $this->karteId) {
            return;
        }
        try {
            $this->kiKartenVorschau = $svc->kiKartenText($this->team(), $this->karteId)['text'];
        } catch (\Platform\FoodAlchemist\Exceptions\KiNichtVerfuegbarException $e) {
            $this->addError('kiKartenVorschau', 'KI derzeit nicht verfügbar.');
        } catch (\RuntimeException $e) {
            $this->addError('kiKartenVorschau', $e->getMessage());
        }
    }

    public function kiKartenUebernehmen(SpeisekarteService $svc): void
    {
        if (! $this->karteId || $this->kiKartenVorschau === null) {
            return;
        }
        $svc->update($this->team(), $this->karteId, ['description' => $this->kiKartenVorschau]);
        $this->kiKartenVorschau = null;
    }

    public function kiKartenVerwerfen(): void
    {
        $this->kiKartenVorschau = null;
    }

    public function render(SpeisekarteService $svc)
    {
        $team = $this->team();
        $karte = $this->karteId ? $svc->detail($team, $this->karteId) : null;

        // Preis-Map je Position (netto) für die Editor-Live-Anzeige + Kunden-Vorschau (Wording aufgelöst).
        $preise = [];
        $baum = [];
        $vorschau = null;
        if ($karte) {
            $baum = $svc->rubrikTree($team, $karte->id);
            foreach ($karte->sections as $rubrik) {
                foreach ($rubrik->items as $pos) {
                    $preise[$pos->id] = $svc->positionPreis($pos);
                }
            }
            $vorschau = $svc->dokumentDaten($team, $karte);
        }

        $pickerErgebnisse = collect();
        if ($this->pickerRubrikId !== null) {
            $pickerErgebnisse = $this->pickerModus === 'menue'
                ? $svc->conceptKandidaten($team, $this->pickerSuche, 15)
                : $svc->gerichtKandidaten($team, $this->pickerSuche, 15);
        }

        return view('foodalchemist::livewire.speisekarte.index', [
            'karten' => $svc->paginateBrowser(['search' => $this->search], $team),
            'karte' => $karte,
            'baum' => $baum,
            'preise' => $preise,
            'vorschau' => $vorschau,
            'pickerErgebnisse' => $pickerErgebnisse,
            // Spec 33 P5: Auswahl fürs Status-/Zuordnungs-Bauteil (nur aktive Betriebe).
            'betriebe' => \Platform\FoodAlchemist\Models\FoodAlchemistOutlet::where('team_id', $team->id)
                ->where('is_inactive', false)->orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
            // Spec 33 P3: Hinweis, kein Verbot.
            'portfolioKonflikt' => $karte === null ? null
                : app(\Platform\FoodAlchemist\Services\PortfolioService::class)
                    ->konfliktHinweis($team, 'speisekarte', (int) $karte->id),
            'crmVerfuegbar' => $svc->crmVerfuegbar(),
            'firmen' => $svc->sucheFirmen($this->firmaSuche),
            'kontakte' => $svc->sucheKontakte($this->kontaktSuche),
            // Werkstrang M Phase A: Schreibstil-Auswahl fürs Kontext-Panel (nur aktive, team-sichtbar).
            'schreibstile' => \Platform\FoodAlchemist\Models\FoodAlchemistWritingStyle::visibleToTeam($team)
                ->where('is_inactive', false)->orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
        ])->layout('platform::layouts.app');
    }

    /**
     * Voll-Kaskade (P4): leitet den Rahmen aus den Rubriken der Karte ab (falls keiner existiert — Rubriken =
     * Struktur), erzeugt je Rubrik ein Konzept (an die Rubrik gehängt) + Gericht-Fan-out. Legt eine Planungs-
     * Session als Review-Wurzel an und leitet in den Planung-Editor (Fortschritt + Freigabe).
     */
    public function vollKaskadeStarten(
        \Platform\FoodAlchemist\Services\PlanningCascadeService $cascade,
        \Platform\FoodAlchemist\Services\PlanningSessionService $sessions,
        \Platform\FoodAlchemist\Services\PlanningFrameService $frames
    ) {
        $this->kaskadeMeldung = null;
        $team = $this->team();
        if ($team === null || $this->karteId === null) {
            return null;
        }
        $karte = FoodAlchemistSpeisekarte::visibleToTeam($team)->with('sections')->find($this->karteId);
        if ($karte === null) {
            return null;
        }
        try {
            $frame = $frames->frameFor($team, 'speisekarte', (int) $this->karteId, 'speisekarte_vollkaskade');
            if ($frame->slots()->count() === 0) {
                foreach ($karte->sections as $rubrik) {
                    $frames->addSlot($team, $frame, ['label' => (string) $rubrik->title, 'slot_type' => 'station', 'target_count' => 3]);
                }
            }
            if ($frame->slots()->count() === 0) {
                $this->kaskadeMeldung = 'Erst Rubriken anlegen — daraus entsteht der Kaskaden-Rahmen.';

                return null;
            }
            $session = $sessions->create($team, [
                'title' => 'Voll-Kaskade: ' . ($karte->name ?: ('Speisekarte #' . $this->karteId)),
                'created_via' => 'speisekarte_vollkaskade',
            ]);
            $cascade->starteKaskade($team, 'vollkaskade', $session, 'voll_kreativ', [
                'owner_type' => 'speisekarte', 'owner_id' => (int) $this->karteId, 'created_via' => 'speisekarte_vollkaskade',
            ]);

            return redirect()->route('foodalchemist.planung.index', ['session' => $session->id, 'open' => 1]);
        } catch (\Throwable $e) {
            $this->kaskadeMeldung = $e->getMessage();

            return null;
        }
    }

    private function team()
    {
        return Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
    }
}
