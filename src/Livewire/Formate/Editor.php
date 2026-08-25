<?php

namespace Platform\FoodAlchemist\Livewire\Formate;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\FormatService;
use Platform\FoodAlchemist\Services\WordingResolver;

/**
 * Format-Modul (Phase B): Voll-Editor-Modal (Concepter-Stil, Fullscreen-Dark).
 * Tabs: Identität (Marketing-Kern) · Editionen (bestehende Concepts zuordnen) ·
 * Marketing-Bilder (Hero + Galerie) · Notizen. Struktur-Edits (attach/detach/Bild)
 * persistieren sofort über den FormatService; „Speichern" sichert die Identität.
 */
class Editor extends Component
{
    use WithFileUploads;

    public ?int $id = null;

    public string $tab = 'identitaet';

    public const TABS = ['identitaet', 'editionen', 'kalkulation', 'bilder', 'notizen'];

    /** @var array<string, mixed> */
    public array $form = [];

    public string $editionSuche = '';

    /** F6/#2: Paket-Reiter-Suche im rechten Aufbau-Picker (Concepts nutzt $editionSuche). */
    public string $paketSuche = '';

    /**
     * F6: rechter Aufbau-Picker im Conceptor-Stil — Reiter „Concepts" | „Pakete".
     * Beide booken über conceptEinfuegen() (ein Paket ist ein kind=paket-Concept).
     */
    public string $pickerTab = 'concept';   // 'concept' | 'paket'

    /** #2: Picker-Filter (geteilt je Reiter, spiegelt den Concepter-Browser: Klasse + Facetten). */
    public string $pickerKlasse = '';

    public string $pickerServierform = '';

    public string $pickerEventtyp = '';

    public string $pickerMoment = '';

    public string $pickerSaison = '';

    /** Phase D: Name der inline neu anzulegenden Edition (Concepter 2.0). */
    public string $neueEditionName = '';

    /**
     * F2 (Aufbau-Tab, „Conceptor eine Ebene höher"): Ziel-Position fürs gezielte Einfügen —
     * die nächste neue Position (Concept-Ref oder Struktur-Block) landet direkt HINTER diesem
     * Slot (null = ans Ende). Spiegelt Concepter::$einfuegenNachId.
     */
    public ?int $einfuegenNachId = null;

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $bildUpload = null;

    public ?string $fehler = null;

    #[On('formate-editor.oeffnen')]
    public function oeffnen(?int $id): void
    {
        $this->reset(['form', 'editionSuche', 'paketSuche', 'pickerTab', 'pickerKlasse', 'pickerServierform',
            'pickerEventtyp', 'pickerMoment', 'pickerSaison', 'neueEditionName', 'einfuegenNachId', 'bildUpload', 'fehler']);
        $this->id = $id;
        $this->tab = 'identitaet';
        if ($id === null) {
            return;
        }
        $f = app(FormatService::class)->detail($this->team(), $id);
        if ($f === null) {
            $this->id = null;

            return;
        }
        $this->form = [
            'name' => $f->name,
            'consumer_name' => $f->consumer_name ?? '',
            'claim' => $f->claim ?? '',
            'story' => $f->story ?? '',
            'origin' => $f->origin ?? '',
            'customer' => $f->customer ?? '',
            'status' => $f->status,
            'note' => $f->note ?? '',
            // F1: Concept-Dimensionen (Facetten) am Format
            'serving_form_id' => $f->serving_form_id ?? '',
            'event_type_id' => $f->event_type_id ?? '',
            'einsatzmoment_ids' => $f->serviceMoments->pluck('id')->all(),
            'saison_ids' => $f->seasons->pluck('id')->all(),
            'target_group_ids' => $f->targetGroups->pluck('id')->all(),
        ];
        $this->dispatch('modal.open', name: 'formate-editor');
    }

    public function setTab(string $tab): void
    {
        if (in_array($tab, self::TABS, true)) {
            $this->tab = $tab;
        }
    }

    /** F6: rechten Aufbau-Picker umschalten (Concepts ⇄ Pakete). */
    public function setPickerTab(string $tab): void
    {
        if (in_array($tab, ['concept', 'paket'], true)) {
            $this->pickerTab = $tab;
        }
    }

    /**
     * #2: Picker-Filter-Chip togglen (Klick auf aktiven Wert = abwählen). Ein Filtersatz
     * gilt für beide Reiter — Klasse + die Facetten Servierform/Eventtyp/Einsatzmoment/Saison.
     */
    public function pickerFilter(string $feld, $wert): void
    {
        $map = [
            'klasse' => 'pickerKlasse',
            'servierform' => 'pickerServierform',
            'eventtyp' => 'pickerEventtyp',
            'moment' => 'pickerMoment',
            'saison' => 'pickerSaison',
        ];
        if (! isset($map[$feld])) {
            return;
        }
        $prop = $map[$feld];
        $this->{$prop} = ((string) $this->{$prop}) === ((string) $wert) ? '' : (string) $wert;
    }

    /** F1: Mehrfach-Facette (Einsatzmoment/Saison/Zielgruppe) am Format togglen. */
    public function toggleFacette(string $feld, int $id): void
    {
        if (! in_array($feld, ['einsatzmoment_ids', 'saison_ids', 'target_group_ids'], true)) {
            return;
        }
        $liste = array_map('intval', (array) ($this->form[$feld] ?? []));
        $this->form[$feld] = in_array($id, $liste, true)
            ? array_values(array_diff($liste, [$id]))
            : array_values(array_merge($liste, [$id]));

        if ($this->id !== null) {
            app(FormatService::class)->update($this->team(), $this->id, [$feld => $this->form[$feld]]);
        }
    }

    #[On('modal.closed')]
    public function beimSchliessen(?string $name = null): void
    {
        if ($name === 'formate-editor') {
            $this->reset(['form', 'editionSuche', 'paketSuche', 'pickerTab', 'pickerKlasse', 'pickerServierform',
            'pickerEventtyp', 'pickerMoment', 'pickerSaison', 'neueEditionName', 'einfuegenNachId', 'bildUpload', 'fehler']);
            $this->id = null;
        }
    }

    public function speichern(FormatService $formats): void
    {
        if ($this->id === null) {
            return;
        }
        $this->fehler = null;
        try {
            $formats->update($this->team(), $this->id, $this->form);
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        $this->dispatch('formate-gespeichert');
    }

    // ── Aufbau / Slots (F2: „Conceptor eine Ebene höher") ─────────────────────
    // Ein Format-Slot referenziert ein ganzes Concept (type=concept) ODER ist ein
    // Struktur-Block (header/text/spacer). Alle Struktur-Edits persistieren sofort
    // über den FormatService (Slot-API). Spiegelt Concepter::positionEinfuegen/blockHinzu.

    /** Ziel-Position fürs Einfügen setzen/abwählen — die nächste neue Position landet darunter. */
    public function einfuegenZiel(?int $slotId): void
    {
        $this->einfuegenNachId = ($slotId !== null && $this->einfuegenNachId === $slotId) ? null : $slotId;
    }

    /** Bestehendes Concept als Aufbau-Position (Referenz) einfügen. */
    public function conceptEinfuegen(int $conceptId, FormatService $formats): void
    {
        if ($this->id === null) {
            return;
        }
        try {
            $slot = $formats->slotConceptEinfuegen($this->team(), $this->id, $conceptId, $this->einfuegenNachId);
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        // Folge-Einfügungen ans selbe Ziel stapeln in natürlicher Reihenfolge (Concepter-UX).
        if ($this->einfuegenNachId !== null) {
            $this->einfuegenNachId = $slot->id;
        }
        $this->fehler = null;
        $this->dispatch('formate-gespeichert');
    }

    /** Struktur-Block (header|text|spacer) einfügen. */
    public function blockHinzu(string $type, FormatService $formats): void
    {
        if ($this->id === null) {
            return;
        }
        try {
            $slot = $formats->slotBlockEinfuegen($this->team(), $this->id, $type, [], $this->einfuegenNachId);
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        if ($this->einfuegenNachId !== null) {
            $this->einfuegenNachId = $slot->id;
        }
        $this->fehler = null;
        $this->dispatch('formate-gespeichert');
    }

    /** Struktur-Block-Feld (title|text_content|height) inline speichern. */
    public function blockSpeichern(int $slotId, string $feld, $wert, FormatService $formats): void
    {
        if (! in_array($feld, ['title', 'text_content', 'height'], true)) {
            return;
        }
        try {
            $formats->slotBlockSpeichern($this->team(), $slotId, [$feld => $wert]);
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        $this->dispatch('formate-gespeichert');
    }

    /** Aufbau-Position (Concept-Ref oder Block) entfernen. */
    public function slotEntfernen(int $slotId, FormatService $formats): void
    {
        if ($this->einfuegenNachId === $slotId) {
            $this->einfuegenNachId = null;
        }
        try {
            $formats->slotEntfernen($this->team(), $slotId);
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        $this->dispatch('formate-gespeichert');
    }

    /** Slot direkt hinter einen anderen ziehen (null = an den Anfang). */
    public function slotVerschieben(int $slotId, ?int $afterSlotId, FormatService $formats): void
    {
        try {
            $formats->slotVerschieben($this->team(), $slotId, $afterSlotId);
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        $this->dispatch('formate-gespeichert');
    }

    /** Aufbau-Position eine Stelle nach oben/unten (dir = -1|1) — ↑/↓-Buttons. */
    public function slotHochRunter(int $slotId, int $dir, FormatService $formats): void
    {
        if ($this->id === null) {
            return;
        }
        $ids = $this->team() && ($f = $formats->detail($this->team(), $this->id)) !== null
            ? $f->slots->pluck('id')->map(fn ($v) => (int) $v)->all()
            : [];
        $pos = array_search($slotId, $ids, true);
        if ($pos === false) {
            return;
        }
        $neu = $pos + $dir;
        if ($neu < 0 || $neu >= count($ids)) {
            return;
        }
        [$ids[$pos], $ids[$neu]] = [$ids[$neu], $ids[$pos]];
        $formats->slotsNeuOrdnen($this->team(), $this->id, $ids);
        $this->dispatch('formate-gespeichert');
    }

    /**
     * Kunden-Wording eines referenzierten Concepts (Titel/Claim/Hinführung) pflegen. Unter dem
     * Referenz-Modell ist das Wording geteilt — der Concept-Slot editiert das Concept SELBST
     * (ConceptService::update), nicht eine format-lokale Kopie.
     */
    public function conceptWordingSpeichern(int $conceptId, string $feld, ?string $wert): void
    {
        if ($this->id === null || ! in_array($feld, ['consumer_name', 'claim', 'description'], true)) {
            return;
        }
        try {
            app(ConceptService::class)->update($this->team(), $conceptId, [$feld => $wert]);
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        $this->fehler = null;
        $this->dispatch('formate-gespeichert');
    }

    /** Format C1: inline-editierte Gericht-Zeile in der editionMenus-Vorschau — "formatSlotId:conceptSlotId". */
    public ?string $editSlotKey = null;

    public string $editSlotWording = '';

    /** Format C1: eine Gericht-Zeile der Vorschau inline bearbeiten (format-lokaler Anzeigename). */
    public function slotWordingBearbeiten(int $formatSlotId, int $conceptSlotId, ?string $aktuell = null): void
    {
        $this->editSlotKey = $formatSlotId . ':' . $conceptSlotId;
        $this->editSlotWording = (string) $aktuell;
    }

    /**
     * Format C1: den inline bearbeiteten Anzeigenamen format-LOKAL speichern
     * (format_slot.payload_json['wording_overrides'][concept-slot-id]). Leer = zurück auf die
     * Wording-Kette; das referenzierte Concept bleibt unangetastet.
     */
    public function slotWordingSpeichern(FormatService $formats): void
    {
        if ($this->editSlotKey === null || ! str_contains($this->editSlotKey, ':')) {
            return;
        }
        [$formatSlotId, $conceptSlotId] = array_map('intval', explode(':', $this->editSlotKey, 2));
        try {
            $formats->setSlotWording($this->team(), $formatSlotId, $conceptSlotId, $this->editSlotWording);
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        $this->editSlotKey = null;
        $this->editSlotWording = '';
        $this->fehler = null;
        $this->dispatch('formate-gespeichert');
    }

    public function slotWordingAbbrechen(): void
    {
        $this->editSlotKey = null;
        $this->editSlotWording = '';
    }

    /**
     * Eine NEUE Edition (Concept, aktiv) anlegen, ihr Standard-Sektions-Gerüst seeden und als
     * Aufbau-Position (Referenz) einfügen. Ersetzt den alten createEdition/attachEdition-Pfad
     * (kein `format_id`-Besitz mehr — reine Slot-Referenz).
     */
    public function neueEdition(FormatService $formats): void
    {
        if ($this->id === null) {
            return;
        }
        try {
            $concepts = app(ConceptService::class);
            $concept = $concepts->create($this->team(), [
                'name' => trim($this->neueEditionName) !== '' ? trim($this->neueEditionName) : 'Neue Edition',
                'status' => 'active',
            ]);
            // Auto-Sektions-Gerüst (Header-Blöcke am Concept selbst) — „automatisch"-Grundgerüst.
            foreach (FormatService::SEKTIONS_GERUEST as $sektion) {
                $concepts->addBlock($this->team(), $concept->id, 'header', ['title' => $sektion]);
            }
            $slot = $formats->slotConceptEinfuegen($this->team(), $this->id, $concept->id, $this->einfuegenNachId);
            if ($this->einfuegenNachId !== null) {
                $this->einfuegenNachId = $slot->id;
            }
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        $this->neueEditionName = '';
        $this->fehler = null;
        $this->dispatch('formate-gespeichert');
    }

    // ── Marketing-Bilder ──────────────────────────────────────────────────────

    public function updatedBildUpload(FormatService $formats): void
    {
        if ($this->id === null || $this->bildUpload === null) {
            return;
        }
        $this->fehler = null;
        try {
            $this->validate(['bildUpload' => 'image|max:8192']);
            $formats->storeImage($this->team(), $this->id, $this->bildUpload);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->reset('bildUpload');
            throw $e;
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
        $this->reset('bildUpload');
        $this->dispatch('formate-gespeichert');
    }

    public function heroSetzen(int $imageId, FormatService $formats): void
    {
        try {
            $formats->setHero($this->team(), $imageId);
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        $this->dispatch('formate-gespeichert');
    }

    public function bildLoeschen(int $imageId, FormatService $formats): void
    {
        try {
            $formats->clearImage($this->team(), $imageId);
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        $this->dispatch('formate-gespeichert');
    }

    public function bildCaption(int $imageId, string $caption, FormatService $formats): void
    {
        try {
            $formats->setImageCaption($this->team(), $imageId, $caption);
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        $this->dispatch('formate-gespeichert');
    }

    public function render(FormatService $formats)
    {
        $format = $this->id !== null ? $formats->detail($this->team(), $this->id) : null;

        // F2: Aufbau-Positionen (Slots) in Reihenfolge — Concept-Referenzen + Struktur-Blöcke.
        $slots = collect();
        // F6: rechter Picker-Rail — je Reiter eine Kandidatenliste (Concepts | Pakete),
        // beide über conceptEinfuegen() buchbar (ein Paket ist ein kind=paket-Concept).
        $kandidaten = collect();
        $paketKandidaten = collect();
        $pickerKlassen = [];
        $pickerPaketKlassen = [];
        $editionMenus = [];
        if ($this->id !== null && $this->tab === 'editionen' && $format !== null) {
            $slots = $format->slots()
                // #3/F6: kind → „Paket"-Badge am Slot.
                ->with(['concept:id,name,consumer_name,claim,description,status,kind,price_per_person_cache'])
                ->orderBy('position')->get();

            // #2: geteilter Filtersatz (Klasse + Facetten) für beide Picker-Reiter.
            $pickerFilters = [
                'class' => $this->pickerKlasse,
                'servierform' => $this->pickerServierform,
                'eventtyp' => $this->pickerEventtyp,
                'einsatzmoment' => $this->pickerMoment,
                'season' => $this->pickerSaison,
            ];
            if ($this->pickerTab === 'paket') {
                $paketKandidaten = $formats->paketKandidaten($this->team(), $this->paketSuche, $pickerFilters);
            } else {
                $kandidaten = $formats->conceptKandidaten($this->team(), $this->editionSuche, $pickerFilters);
            }
            $conceptSvc = app(ConceptService::class);
            $pickerKlassen = $conceptSvc->klassen($this->team());
            $pickerPaketKlassen = $conceptSvc->klassenPakete($this->team());

            // Live-Vorschau je Concept-Slot (Sektionen + Gerichte via WordingResolver) — dieselbe
            // Auflösung wie im Foodbook-Render, gekeyed nach SLOT-ID (nicht Concept-ID).
            $conceptSlots = $slots->where('type', 'concept');
            $conceptIds = $conceptSlots->pluck('concept_id')->filter()->unique()->all();
            if ($conceptIds !== []) {
                $wording = app(WordingResolver::class);
                $geladen = FoodAlchemistConcept::whereIn('id', $conceptIds)
                    ->with([
                        'slots.dish:id,name,sales_wording_standard',
                        'slots.package.dishes.dish:id,name,sales_wording_standard',
                        // eingebettetes Paket (kind=paket-Concept) + dessen Gerichte für die rekursive Vorschau
                        'slots.embeddedConcept:id,name,consumer_name,price_per_person_cache',
                        'slots.embeddedConcept.slots.dish:id,name,sales_wording_standard',
                        'slots.embeddedConcept.slots.package.dishes.dish:id,name,sales_wording_standard',
                    ])
                    ->get()->keyBy('id');
                foreach ($conceptSlots as $slot) {
                    $c = $geladen->get($slot->concept_id);
                    // Format C1: den Format-Slot als Override-Kontext durchreichen → format-lokale
                    // Wording-Overrides (payload_json) greifen in der Vorschau + im Druck.
                    $editionMenus[$slot->id] = $c !== null ? $wording->gerichtZeilen($c, $slot) : [];
                }
            }
        }

        // F4: Kalkulations-Tab — wie performen die Editionen (Concepts)? Read-only aus den
        // Concept-Caches (kein Recompute): €/Person, EK/Person, W% je Edition + Format-Rollup.
        $kalkZeilen = collect();
        $kalkSumme = ['n' => 0, 'min' => null, 'max' => null, 'avg' => null, 'avg_w' => null];
        if ($this->id !== null && $this->tab === 'kalkulation' && $format !== null) {
            $kalkZeilen = $format->slots()->where('type', 'concept')
                ->with(['concept:id,name,consumer_name,price_per_person_cache,ek_per_person_cache'])
                ->orderBy('position')->get()
                ->map(function ($s) {
                    $c = $s->concept;
                    $vk = $c?->price_per_person_cache !== null ? (float) $c->price_per_person_cache : null;
                    $ek = $c?->ek_per_person_cache !== null ? (float) $c->ek_per_person_cache : null;
                    return [
                        'name' => $c?->consumer_name ?: ($c?->name ?? '— (entfernt)'),
                        'vk' => $vk,
                        'ek' => $ek,
                        'w' => ($vk !== null && $vk > 0 && $ek !== null) ? round($ek / $vk * 100, 1) : null,
                    ];
                })->values();
            $vks = $kalkZeilen->pluck('vk')->filter(fn ($v) => $v !== null && $v > 0)->values();
            $ws = $kalkZeilen->pluck('w')->filter(fn ($v) => $v !== null)->values();
            $kalkSumme = [
                'n' => $kalkZeilen->count(),
                'min' => $vks->isEmpty() ? null : round((float) $vks->min(), 2),
                'max' => $vks->isEmpty() ? null : round((float) $vks->max(), 2),
                'avg' => $vks->isEmpty() ? null : round((float) $vks->avg(), 2),
                'avg_w' => $ws->isEmpty() ? null : round((float) $ws->avg(), 1),
            ];
        }

        // F1: Facetten-Vokabular (aus den Einstellungen gepflegt, geteilt mit den Concepts).
        $team = $this->team();
        $servierformen = \Platform\FoodAlchemist\Models\FoodAlchemistServierform::where('is_inactive', false)
            ->orderBy('sort_order')->get(['id', 'code', 'label']);
        $eventtypen = \Platform\FoodAlchemist\Models\FoodAlchemistEventtyp::visibleToTeam($team)
            ->where('is_inactive', false)->orderBy('sort_order')->get(['id', 'name']);
        $einsatzmomente = \Platform\FoodAlchemist\Models\FoodAlchemistEinsatzmoment::visibleToTeam($team)
            ->where('is_inactive', false)->orderBy('sort_order')->get(['id', 'name']);
        $saisons = \Platform\FoodAlchemist\Models\FoodAlchemistSaison::visibleToTeam($team)
            ->where('is_inactive', false)->orderBy('sort_order')->get(['id', 'name']);
        $zielgruppen = \Platform\FoodAlchemist\Models\FoodAlchemistTargetGroup::visibleToTeam($team)
            ->orderBy('name')->get(['id', 'name']);

        return view('foodalchemist::livewire.formate.editor', [
            'format' => $format,
            'aufbauSlots' => $slots,
            'kandidaten' => $kandidaten,
            'paketKandidaten' => $paketKandidaten,
            'pickerKlassen' => $pickerKlassen,
            'pickerPaketKlassen' => $pickerPaketKlassen,
            'editionMenus' => $editionMenus,
            'kalkZeilen' => $kalkZeilen,
            'kalkSumme' => $kalkSumme,
            'servierformen' => $servierformen,
            'eventtypen' => $eventtypen,
            'einsatzmomente' => $einsatzmomente,
            'saisons' => $saisons,
            'zielgruppen' => $zielgruppen,
        ]);
    }

    private function team()
    {
        return Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
    }
}
