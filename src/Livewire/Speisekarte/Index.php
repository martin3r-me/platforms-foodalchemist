<?php

namespace Platform\FoodAlchemist\Livewire\Speisekarte;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Platform\FoodAlchemist\Enums\AusgabeStatus;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarte;
use Platform\FoodAlchemist\Services\PresentationDesignService;
use Platform\FoodAlchemist\Services\PresentationService;
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

    // ── Spec 43: Präsentation (digitales Kundenbuch) ──
    public string $presentationDesign = 'menu';

    public ?string $presentationGueltigBis = null;

    public bool $presentationPreisAnzeige = true;

    public bool $presentationDeklaration = true;

    public ?string $presentationCtaText = null;

    public ?string $presentationCtaLink = null;

    public ?int $presentationLoadedId = null;

    public ?string $presentationFehler = null;

    public ?string $presentationHinweis = null;

    // Slice F: publish-per-Betrieb — zusätzlicher Link je Betrieb mit DESSEN Preisen + eigener Freigabe.
    public ?int $outletPublishId = null;

    public ?string $outletPublishGueltigBis = null;

    public function veroeffentlichen(): void
    {
        $this->presentationFehler = null;
        $this->presentationHinweis = null;
        if ($this->karteId === null) {
            return;
        }
        try {
            app(PresentationService::class)->publish($this->team(), 'speisekarte', $this->karteId, [
                'design' => $this->presentationDesign,
                'expires_at' => $this->presentationGueltigBis,
                'price_display' => $this->presentationPreisAnzeige,
                'declaration' => $this->presentationDeklaration,
                'cta' => ['text' => $this->presentationCtaText, 'link' => $this->presentationCtaLink],
            ]);
            $this->presentationLoadedId = null;
            $this->presentationHinweis = 'Veröffentlicht — der Kundenlink ist aktiv.';
        } catch (\Throwable $e) {
            $this->presentationFehler = $e->getMessage();
        }
    }

    public function zuruckziehen(): void
    {
        $this->presentationFehler = null;
        $this->presentationHinweis = null;
        if ($this->karteId === null) {
            return;
        }
        try {
            app(PresentationService::class)->withdraw($this->team(), 'speisekarte', $this->karteId);
            $this->presentationLoadedId = null;
            $this->presentationHinweis = 'Veröffentlichung zurückgezogen — der Link ist jetzt inaktiv (404).';
        } catch (\Throwable $e) {
            $this->presentationFehler = $e->getMessage();
        }
    }

    /** Slice F: einen zweiten Link FÜR einen Betrieb anlegen — dessen Preise + Vorlage, eigene Freigabe. */
    public function betriebVeroeffentlichen(): void
    {
        $this->presentationFehler = null;
        $this->presentationHinweis = null;
        if ($this->karteId === null) {
            return;
        }
        if ($this->outletPublishId === null) {
            $this->presentationFehler = 'Bitte zuerst einen Betrieb wählen.';

            return;
        }
        try {
            app(PresentationService::class)->publishForOutlet($this->team(), 'speisekarte', $this->karteId, $this->outletPublishId, [
                'expires_at' => $this->outletPublishGueltigBis ?: $this->presentationGueltigBis,
                'price_display' => $this->presentationPreisAnzeige,
                'declaration' => $this->presentationDeklaration,
                'cta' => ['text' => $this->presentationCtaText, 'link' => $this->presentationCtaLink],
            ]);
            $this->outletPublishId = null;
            $this->outletPublishGueltigBis = null;
            $this->presentationHinweis = 'Betriebs-Link veröffentlicht — eigener Link mit den Preisen dieses Betriebs.';
        } catch (\Throwable $e) {
            $this->presentationFehler = $e->getMessage();
        }
    }

    /** Slice F: einen Betriebs-Link vom Netz nehmen (Standard-Link bleibt unberührt). */
    public function betriebZuruckziehen(int $outletId): void
    {
        $this->presentationFehler = null;
        $this->presentationHinweis = null;
        if ($this->karteId === null) {
            return;
        }
        try {
            app(PresentationService::class)->withdrawForOutlet($this->team(), 'speisekarte', $this->karteId, $outletId);
            $this->presentationHinweis = 'Betriebs-Link zurückgezogen.';
        } catch (\Throwable $e) {
            $this->presentationFehler = $e->getMessage();
        }
    }

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

    // #7 (2026-08-27): Preisanzeige — netto/brutto + Brutto-Rundung (nur Ausgabe, nie die Netto-Werte).
    public bool $preisAnzeigeBrutto = true;

    public string $preisRundung = 'keine';

    public string $firmaSuche = '';
    public string $kontaktSuche = '';

    // Rubrik-Anlage
    public string $neueRubrik = '';

    // Gericht-/Menü-Picker
    public string $pickerSuche = '';
    public ?int $pickerRubrikId = null;
    public string $pickerModus = 'gericht'; // gericht | menue | format

    // Format-Umbau F5: Suche im „Format einfügen"-Katalog-Modus (bucht ein Format als eigene Rubrik).
    public string $formatSuche = '';

    // Werkstrang M Phase B: Facetten-Filter im Gericht-Picker (Hauptgruppe → Unterklasse).
    public ?int $pickerHauptgruppe = null;
    public ?int $pickerDishClass = null;

    // Positions-Bearbeitung (inline)
    public ?int $editPosId = null;
    public ?string $editWording = null;
    public ?string $editConsumerText = null;
    public string $editPriceMode = 'auto';
    public ?string $editPriceValue = null;
    // Werkstrang M Phase D: Layout-Blöcke + Wahl-Gruppen.
    public ?string $editLabel = null;        // Überschrift-Text (type=header)
    public ?int $editVariantGroupId = null;  // Wahl-Gruppe „A|B|C" (gericht_ref/menue_ref)

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
        $this->preisAnzeigeBrutto = (bool) $karte->preis_anzeige_brutto;
        $this->preisRundung = $karte->preis_rundung ?: 'keine';
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
        $this->pickerHauptgruppe = null;
        $this->pickerDishClass = null;
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
            'preis_anzeige_brutto' => $this->preisAnzeigeBrutto,
            'preis_rundung' => $this->preisRundung,
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
            $this->pickerModus = in_array($modus, ['gericht', 'konzept', 'paket'], true) ? $modus : 'gericht';
        }
        $this->pickerSuche = '';
        // Werkstrang M Phase B: Facetten beim (Neu-)Öffnen zurücksetzen.
        $this->pickerHauptgruppe = null;
        $this->pickerDishClass = null;
    }

    /** Persistenter Katalog (Picker-Umbau): Modus umschalten (gericht|konzept|paket|format); Ziel-Rubrik bleibt. */
    public function katalogModus(string $modus): void
    {
        $this->pickerModus = in_array($modus, ['gericht', 'konzept', 'paket', 'format'], true) ? $modus : 'gericht';
        $this->pickerSuche = '';
        $this->pickerHauptgruppe = null;
        $this->pickerDishClass = null;
        $this->formatSuche = '';
    }

    /** Werkstrang M Phase B: Hauptgruppen-Facette setzen/löschen — Unterklasse fällt dabei weg. */
    public function pickerWaehleHg(?int $hauptgruppe): void
    {
        $this->pickerHauptgruppe = ($hauptgruppe !== null && $this->pickerHauptgruppe === $hauptgruppe) ? null : $hauptgruppe;
        $this->pickerDishClass = null;
    }

    /** Werkstrang M Phase B: Unterklassen-Facette setzen/löschen (Toggle). */
    public function pickerWaehleKlasse(?int $dishClassId): void
    {
        $this->pickerDishClass = ($dishClassId !== null && $this->pickerDishClass === $dishClassId) ? null : $dishClassId;
    }

    /** Dropdown-Bindung (Filter als <select> statt Pill-Wand): HG-Wechsel setzt die Unterklasse zurück. */
    public function updatedPickerHauptgruppe(): void
    {
        $this->pickerDishClass = null;
    }

    public function positionAusGericht(SpeisekarteService $svc, int $rubrikId, int $recipeId): void
    {
        $svc->addPosition($this->team(), $rubrikId, [
            'type' => 'gericht_ref', 'sales_recipe_id' => $recipeId,
        ]);
        // Werkstrang M Phase B: Picker + Suche + Facetten bleiben offen → mehrere Gerichte hintereinander.
    }

    public function positionAusMenue(SpeisekarteService $svc, int $rubrikId, int $conceptId): void
    {
        $svc->addPosition($this->team(), $rubrikId, [
            'type' => 'menue_ref', 'concept_id' => $conceptId,
        ]);
    }

    /**
     * Format-Umbau F5: ein Format in die Karte buchen — WIE EIN CONCEPT. Legt eine eigene Rubrik
     * an, deren Positionen die Editionen als live menue_ref + die Struktur-Blöcke des Formats sind
     * (kein Live-Format-Sonderweg). Fail-soft: Status-Guard meldet sich als Fehler, kippt die Karte nicht.
     */
    public function formatEinfuegen(SpeisekarteService $svc, int $formatId): void
    {
        if (! $this->karteId) {
            return;
        }
        try {
            $svc->insertFormatAlsRubrik($this->team(), $this->karteId, $formatId);
        } catch (\Throwable $e) {
            $this->addError('formatRubrik', $e->getMessage());

            return;
        }
        $this->formatSuche = '';
    }

    public function positionLoeschen(SpeisekarteService $svc, int $positionId): void
    {
        $svc->deletePosition($this->team(), $positionId);
        if ($this->editPosId === $positionId) {
            $this->editPosId = null;
        }
    }

    // ── Werkstrang M Phase C (Spec 40 §6): Umsortieren + Verschieben ──────────

    /** Position innerhalb ihrer Rubrik hoch/runter (dir ∈ hoch|runter). Reihenfolge-Swap mit dem Nachbarn. */
    public function positionHochRunter(int $positionId, string $dir, SpeisekarteService $svc): void
    {
        $team = $this->team();
        $pos = \Platform\FoodAlchemist\Models\FoodAlchemistSpeisekartePosition::visibleToTeam($team)->find($positionId);
        if ($pos === null) {
            return;
        }
        $ids = \Platform\FoodAlchemist\Models\FoodAlchemistSpeisekartePosition::where('section_id', $pos->section_id)
            ->orderBy('position')->orderBy('id')->pluck('id')->map(fn ($v) => (int) $v)->all();
        $svc->reorderPositionen($team, (int) $pos->section_id, $this->swapNachbar($ids, $positionId, $dir));
    }

    /** Rubrik innerhalb ihrer Ebene (gleiche Karte + gleicher parent) hoch/runter. */
    public function rubrikHochRunter(int $rubrikId, string $dir, SpeisekarteService $svc): void
    {
        $team = $this->team();
        $rubrik = \Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarteRubrik::visibleToTeam($team)->find($rubrikId);
        if ($rubrik === null) {
            return;
        }
        $ids = \Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarteRubrik::where('menu_card_id', $rubrik->menu_card_id)
            ->where('parent_id', $rubrik->parent_id)
            ->orderBy('position')->orderBy('id')->pluck('id')->map(fn ($v) => (int) $v)->all();
        $svc->reorderRubriken($team, (int) $rubrik->menu_card_id, $rubrik->parent_id !== null ? (int) $rubrik->parent_id : null, $this->swapNachbar($ids, $rubrikId, $dir));
    }

    /** Eine Position in eine andere Rubrik derselben Karte verschieben (Phase-C-„echter Neubau"). */
    public function positionInRubrik(int $positionId, int $newSectionId, SpeisekarteService $svc): void
    {
        try {
            $svc->movePosition($this->team(), $positionId, $newSectionId);
        } catch (\Throwable $e) {
            $this->errorToast($e->getMessage());
        }
    }

    /** Tauscht $id mit seinem oberen/unteren Nachbarn in der ID-Liste (dir ∈ hoch|runter). */
    private function swapNachbar(array $ids, int $id, string $dir): array
    {
        $i = array_search($id, $ids, true);
        if ($i === false) {
            return $ids;
        }
        $j = $dir === 'hoch' ? $i - 1 : $i + 1;
        if ($j < 0 || $j >= count($ids)) {
            return $ids;   // schon oben/unten
        }
        [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];

        return $ids;
    }

    // ── Drag & Drop (additiv zu hoch/runter) ──────────────────────────────────

    /**
     * Werkstrang M (UX-Ausbau): Position per D&D ablegen — auf eine Ziel-Position. Gleiche Rubrik →
     * reorder (dragged VOR target); andere Rubrik derselben Karte → erst movePosition, dann reorder
     * (dragged VOR target). Team-scoped über die Service-Methoden.
     */
    public function positionAblegen(int $draggedId, int $targetId, SpeisekarteService $svc): void
    {
        if ($draggedId === $targetId) {
            return;
        }
        $team = $this->team();
        $dragged = \Platform\FoodAlchemist\Models\FoodAlchemistSpeisekartePosition::visibleToTeam($team)->find($draggedId);
        $target = \Platform\FoodAlchemist\Models\FoodAlchemistSpeisekartePosition::visibleToTeam($team)->find($targetId);
        if ($dragged === null || $target === null) {
            return;
        }
        try {
            if ((int) $dragged->section_id !== (int) $target->section_id) {
                $svc->movePosition($team, $draggedId, (int) $target->section_id);
            }
            $ids = \Platform\FoodAlchemist\Models\FoodAlchemistSpeisekartePosition::where('section_id', $target->section_id)
                ->orderBy('position')->orderBy('id')->pluck('id')->map(fn ($v) => (int) $v)->all();
            $svc->reorderPositionen($team, (int) $target->section_id, $this->einfuegenVor($ids, $draggedId, $targetId));
        } catch (\Throwable $e) {
            $this->errorToast($e->getMessage());
        }
    }

    /** Werkstrang M (UX-Ausbau): Rubrik per D&D ablegen — nur innerhalb derselben Ebene (Karte + parent). */
    public function rubrikAblegen(int $draggedId, int $targetId, SpeisekarteService $svc): void
    {
        if ($draggedId === $targetId) {
            return;
        }
        $team = $this->team();
        $d = \Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarteRubrik::visibleToTeam($team)->find($draggedId);
        $t = \Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarteRubrik::visibleToTeam($team)->find($targetId);
        if ($d === null || $t === null) {
            return;
        }
        // Nur gleiche Ebene sortieren (Verschachtelung ändern bleibt bewusst außen vor).
        if ((int) $d->menu_card_id !== (int) $t->menu_card_id || $d->parent_id !== $t->parent_id) {
            return;
        }
        try {
            $ids = \Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarteRubrik::where('menu_card_id', $t->menu_card_id)
                ->where('parent_id', $t->parent_id)
                ->orderBy('position')->orderBy('id')->pluck('id')->map(fn ($v) => (int) $v)->all();
            $svc->reorderRubriken($team, (int) $t->menu_card_id, $t->parent_id !== null ? (int) $t->parent_id : null, $this->einfuegenVor($ids, $draggedId, $targetId));
        } catch (\Throwable $e) {
            $this->errorToast($e->getMessage());
        }
    }

    /** Entfernt $moveId aus der Liste und fügt es VOR $beforeId wieder ein (D&D-Ablage). */
    private function einfuegenVor(array $ids, int $moveId, int $beforeId): array
    {
        $ids = array_values(array_filter($ids, fn ($x) => (int) $x !== $moveId));
        $pos = array_search($beforeId, $ids, true);
        if ($pos === false) {
            $ids[] = $moveId;

            return $ids;
        }
        array_splice($ids, $pos, 0, [$moveId]);

        return $ids;
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
        // Werkstrang M Phase D
        $this->editLabel = $pos->label;
        $this->editVariantGroupId = $pos->variant_group_id !== null ? (int) $pos->variant_group_id : null;
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
            // Werkstrang M Phase D: Überschrift-Text + Wahl-Gruppe.
            'label' => $this->editLabel ?: null,
            'variant_group_id' => $this->editVariantGroupId ?: null,
        ]);
        $this->editPosId = null;
    }

    /**
     * Werkstrang M Phase D (Spec 40 §6): Layout-Block (Überschrift/Text/Abstand) in eine Rubrik einfügen.
     * type ∈ header|text|spacer. Reused addPosition; sinnvolle Defaults, danach per ✎ editierbar.
     */
    public function layoutBlockNeu(int $rubrikId, string $type, SpeisekarteService $svc): void
    {
        if (! in_array($type, ['header', 'text', 'spacer'], true)) {
            return;
        }
        $daten = ['type' => $type];
        if ($type === 'header') {
            $daten['label'] = 'Überschrift';
        } elseif ($type === 'text') {
            $daten['consumer_text'] = 'Text …';
        }
        $svc->addPosition($this->team(), $rubrikId, $daten);
    }

    /** Werkstrang M Phase D: nächste freie Wahl-Gruppen-ID der Rubrik als Vorschlag ins Edit-Feld. */
    public function variantGruppeVorschlag(int $rubrikId, SpeisekarteService $svc): void
    {
        $this->editVariantGroupId = $svc->nextVariantGroupId($this->team(), $rubrikId);
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

    /**
     * A (2026-08-25): das Wording der GANZEN Speisekarte im gewählten Schreibstil neu erzeugen.
     * Speichert zuerst den Stil (+ Kontext-Leitplanken), betextet dann alle Positionen. LLM-Kosten
     * → nur auf Knopfdruck. Kein Stil gewählt = Hinweis, kein Call.
     */
    public function speisekarteWordingGenerieren(SpeisekarteService $svc): void
    {
        if (! $this->karteId) {
            return;
        }
        $this->resetErrorBag('speisekarteWording');
        $this->speichern($svc);   // Stil-Override + Leitplanken persistieren
        if (! $this->writingStyleId) {
            $this->addError('speisekarteWording', 'Kein Schreibstil gewählt — nichts zu betexten (Wording bleibt Standard-Kette).');

            return;
        }
        try {
            $n = $svc->speisekarteWordingRegenerieren($this->team(), $this->karteId);
        } catch (\Throwable $e) {
            $this->addError('speisekarteWording', $e->getMessage());

            return;
        }
        $this->dispatch('gespeichert');
        $this->dispatch('toast', text: $n > 0 ? "{$n} Position(en) im Schreibstil neu betextet." : 'Keine Gericht-/Menü-Positionen zum Betexten.');
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

        // Spec 43: Präsentations-Publish-Felder nur bei Selektions-WECHSEL laden (kein Edit-Verlust).
        if ($karte !== null && $this->presentationLoadedId !== $karte->id) {
            $s = $karte->presentationSettings();
            $this->presentationDesign = $karte->presentation_design ?: 'menu';
            $this->presentationGueltigBis = $karte->presentation_expires_at?->format('Y-m-d');
            $this->presentationPreisAnzeige = (bool) ($s['price_display'] ?? true);
            $this->presentationDeklaration = (bool) ($s['declaration'] ?? true);
            $this->presentationCtaText = $s['cta']['text'] ?? null;
            $this->presentationCtaLink = $s['cta']['link'] ?? null;
            $this->presentationLoadedId = $karte->id;
        }

        // Board-Sicht (intern): je Position VK/EK/WE + je Rubrik der Σ-Rollup für die Kosten-/Margen-Spalten
        // im Aufbau-Baum (Dominique 2026-08-27). Ein Aufruf statt N Einzelpreise. $alleRubrikIds treibt
        // „Alle auf/zu".
        $preise = [];
        $rubrikAgg = [];
        $alleRubrikIds = [];
        $baum = [];
        $vorschau = null;
        if ($karte) {
            $baum = $svc->rubrikTree($team, $karte->id);
            $board = $svc->boardDaten($team, $karte);
            $preise = $board['positionen'];
            $rubrikAgg = $board['rubriken'];
            $alleRubrikIds = $karte->sections->pluck('id')->map(fn ($v) => (int) $v)->all();
            $vorschau = $svc->dokumentDaten($team, $karte);
        }

        // Picker-Umbau: persistenter Katalog — Kandidaten je Modus browsebar (unabhängig von der Ziel-Rubrik;
        // die „+"-Aktion braucht die Ziel-Rubrik, das Blättern nicht).
        $pickerErgebnisse = collect();
        $pickerHauptgruppen = collect();
        $pickerUntergruppen = collect();
        if ($karte !== null && $this->pickerModus !== 'format') {
            $pickerErgebnisse = match ($this->pickerModus) {
                // Menü ist ein Konzept; Paket = kind=paket-Concept (eigener Katalog-Reiter).
                'konzept' => $svc->conceptKandidaten($team, $this->pickerSuche, 50, 'concept'),
                'paket' => $svc->conceptKandidaten($team, $this->pickerSuche, 50, 'paket'),
                default => $svc->gerichtKandidaten($team, $this->pickerSuche, 50, $this->pickerHauptgruppe, $this->pickerDishClass),
            };
            if ($this->pickerModus === 'gericht') {
                $pickerHauptgruppen = app(\Platform\FoodAlchemist\Services\SalesRecipeService::class)->dishMainGroups($team);
                if ($this->pickerHauptgruppe !== null) {
                    $pickerUntergruppen = \Platform\FoodAlchemist\Models\FoodAlchemistDishClass::visibleToTeam($team)
                        ->where('dish_main_group_id', $this->pickerHauptgruppe)
                        ->orderBy('label')->get(['id', 'label']);
                }
            }
        }
        // Ziel-Rubrik-Titel für den Katalog-Kopf.
        $pickerRubrikTitel = ($karte !== null && $this->pickerRubrikId !== null)
            ? optional(\Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarteRubrik::where('menu_card_id', $karte->id)->find($this->pickerRubrikId))->title
            : null;

        // Spec 43: Präsentations-Status + Link + Design-Auswahl fürs Branding-&-Präsentation-Tab.
        $presentationInfo = null;
        $presentationLink = null;
        if ($karte !== null) {
            $presentationInfo = [
                'enabled' => (bool) $karte->presentation_enabled,
                'live' => $karte->isPresentationLive(),
                'published_at' => $karte->presentation_published_at?->format('d.m.Y H:i'),
                'expires_at' => $karte->presentation_expires_at?->format('d.m.Y'),
            ];
            if ($karte->presentation_enabled && $karte->presentation_token) {
                $presentationLink = url('/p/speisekarte/' . $karte->presentation_token);
            }
        }
        $presentationDesignOptionen = app(PresentationDesignService::class)->pickerOptions($team, 'speisekarte');

        // Slice F: bestehende Betriebs-Links + wählbare Betriebe (aktiv, team-scoped).
        $betriebsLinks = [];
        $betriebsOptionen = [];
        if ($karte !== null) {
            $betriebsLinks = app(PresentationService::class)->outletPresentations($team, 'speisekarte', $karte->id);
            $betriebsOptionen = \Platform\FoodAlchemist\Models\FoodAlchemistOutlet::where('team_id', $team->id)
                ->where('is_inactive', false)->orderBy('sort_order')->orderBy('name')->get(['id', 'name'])
                ->map(fn ($o) => ['id' => (int) $o->id, 'name' => (string) $o->name])->all();
        }

        return view('foodalchemist::livewire.speisekarte.index', [
            'presentationInfo' => $presentationInfo,
            'presentationLink' => $presentationLink,
            'presentationDesignOptionen' => $presentationDesignOptionen,
            'betriebsLinks' => $betriebsLinks,
            'betriebsOptionen' => $betriebsOptionen,
            'karten' => $svc->paginateBrowser(['search' => $this->search], $team),
            'karte' => $karte,
            'baum' => $baum,
            'preise' => $preise,
            'rubrikAgg' => $rubrikAgg,
            'alleRubrikIds' => $alleRubrikIds,
            'vorschau' => $vorschau,
            'pickerErgebnisse' => $pickerErgebnisse,
            'pickerHauptgruppen' => $pickerHauptgruppen,
            'pickerUntergruppen' => $pickerUntergruppen,
            // Format-Umbau F5: Format-Kandidaten im Katalog-Format-Modus (Format wird zur eigenen Rubrik).
            'formatKandidaten' => ($karte !== null && $this->pickerModus === 'format') ? $svc->formatKandidaten($team, $this->formatSuche) : collect(),
            'pickerRubrikTitel' => $pickerRubrikTitel,
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
     * Spec 42 (Speisekarte-Parität zu Foodbook-F2) — Handoff in die Leitstelle. Die Planung (Brief →
     * Gerüst → Kaskade) lebt in der Leitstelle; die Speisekarte ist reine Ausgabe. Dieser Knopf baut
     * KEIN Gerüst mehr im Modul, sondern öffnet die Leitstelle im Owner-Kontext dieser Karte
     * (`sk_owner`) — dort entstehen Struktur + Inhalte und docken via attachToOutput automatisch zurück.
     */
    public function vollKaskadeStarten()
    {
        $this->kaskadeMeldung = null;
        if ($this->karteId === null) {
            return null;
        }

        return redirect()->route('foodalchemist.planung.index', ['sk_owner' => (int) $this->karteId]);
    }

    private function team()
    {
        return Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
    }
}
