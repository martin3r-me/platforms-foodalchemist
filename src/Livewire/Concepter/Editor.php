<?php

namespace Platform\FoodAlchemist\Livewire\Concepter;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistDishClass;
use Platform\FoodAlchemist\Models\FoodAlchemistWritingStyle;
use Platform\FoodAlchemist\Livewire\Concerns\ManagesCanvas;
use Platform\FoodAlchemist\Livewire\Concerns\ManagesPlanningFrame;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\ConcepterAggregateService;
use Platform\FoodAlchemist\Services\ConcepterBewertungService;
use Platform\FoodAlchemist\Services\KalkulationService;
use Platform\FoodAlchemist\Services\PaketService;
use Platform\FoodAlchemist\Services\SalesRecipeService;

/**
 * M10R-3 / Doc 15 §10.4: Voll-Editor-Modal im VK-Stil (wie VkModal) — kontext-
 * adaptiv für Concept ODER Paket. Fixer Kopf (Bezeichnung · Konsumentenbez. ·
 * Klasse · Kategorie/Rolle · Niveau · Anlass) + Tabs: Aufbau · Nährwerte ·
 * Allergene · Kalkulation · Notizen. Strukturelle Edits (Slots/Gerichte/Tausch)
 * persistieren sofort über die Services; „Speichern" sichert den Kopf.
 *
 * P-2-State-Leak-Schutz: Reset beim ÖFFNEN (modal.closed ist ein Alpine-Event,
 * das Livewire nicht erreicht).
 */
class Editor extends Component
{
    use ManagesCanvas, ManagesPlanningFrame;

    public string $type = 'concepts';   // concepts | pakete

    public ?int $id = null;

    public string $tab = 'aufbau';       // aufbau | allergene (Label „Deklaration": Diät + Nährwerte) | kalkulation | geschirr | notes

    /** @var array<string, mixed> */
    public array $form = [];

    // #388 Geschirr-Tab: Picker je Gericht-Slot (Haupt/Alternative)
    public ?int $geschirrPickSlotId = null;

    public string $geschirrPickRolle = 'haupt';   // haupt | alt

    public string $geschirrSuche = '';

    // Aufbau (Concept): neuer Slot + festes-Gericht-Picker
    public string $neuerSlotRolle = '';

    public ?int $fillSlotId = null;

    /** Welche Position ihre Befüllungs-/Picker-Zeile aufgeklappt hat (Tabellen-Editor). */
    public ?int $fillOpenId = null;

    /** Gezieltes Einfügen: hinter dieser Position landet die nächste neue Position (null = ans Ende). */
    public ?int $einfuegenNachId = null;

    public string $gerichtSuche = '';

    public string $basisSuche = '';   // Phase 3: linke Seiten-Liste (Basisrezepte)

    // Kombi-Suche (wie Gerichte-Editor): filtert BEIDE Seiten-Listen gleichzeitig.
    public string $kombiSuche = '';

    // Linke Seiten-Liste: umschaltbar Basisrezept ⇄ Paket (Pakete bei 300+ über Such-/Filter-Liste einfügen)
    public string $linkeListe = 'basisrezept';   // basisrezept | paket

    public string $paketKlasse = '';

    // Basisrezepte-Liste: Filter wie im Rezept-Browser (Hauptgruppe→Kategorie + Niveau)
    public ?int $basisHg = null;

    public ?int $basisKat = null;

    public string $basisNiveau = '';

    // B2: Quelle des Position-Pickers — VK-Gericht ODER Basisrezept (keine Produkte/GPs).
    public string $pickTyp = 'gericht';   // gericht | basisrezept

    /** @var array<int, array{role:string, titel:string}> */
    public array $slotForm = [];

    /** B3: Inline-Inhalt der Struktur-Blöcke (Text/Header/Preis), keyed by slotId. */
    public array $blockForm = [];

    /** B4: markierte Positionen für „Paket bilden". @var array<int> */
    public array $auswahl = [];

    public string $paketName = '';

    // Aufbau (Paket): Gericht-Suche
    public string $paketGerichtSuche = '';

    // Aufbau (Paket): Quelle des Posten-Pickers — Gericht (VK) oder Basisrezept (z. B. Hausbrot im Brotkorb-Paket).
    public string $paketQuelle = 'gericht';

    // Aufbau · Gericht-Baum (Picker-Filter, geteilt von Concept-Slot + Paket-Schnüren):
    // gleiche VK-Hauptgruppe→Klasse-Kaskade wie der VK-Browser, damit man Gerichte
    // browsen statt nur tippen kann (Feedback D.B. 2026-06-13).
    public ?int $pickHg = null;

    public ?int $pickKlasse = null;

    public string $pickGeschmack = '';

    // Kalkulation (Concept): Zielpreis-Modus (M13)
    public bool $zielModus = false;

    public string $zielPreis = '';

    public ?array $zielVorschlag = null;

    public string $neuerSektor = '';

    /** Beim Öffnen eines Pakets aus „+ Paket" gemerkt → „zurück zum Concept" springt dorthin. */
    public ?int $rueckSprungConceptId = null;

    public ?string $fehler = null;

    #[On('concepter-editor.oeffnen')]
    public function oeffnen(string $type, ?int $id): void
    {
        $this->reset(['form', 'slotForm', 'blockForm', 'auswahl', 'paketName', 'neuerSlotRolle', 'fillSlotId', 'fillOpenId', 'einfuegenNachId', 'linkeListe', 'paketKlasse', 'basisSuche', 'kombiSuche', 'basisHg', 'basisKat', 'basisNiveau', 'gerichtSuche', 'pickTyp',
            'paketGerichtSuche', 'paketQuelle', 'pickHg', 'pickKlasse', 'pickGeschmack',
            'zielModus', 'zielPreis', 'zielVorschlag', 'rueckSprungConceptId', 'fehler']);
        $this->type = in_array($type, ['concepts', 'pakete'], true) ? $type : 'concepts';
        $this->id = $id;
        $this->tab = 'aufbau';
        if ($id === null) {
            return;
        }

        $team = $this->team();
        if ($this->type === 'pakete') {
            $p = app(PaketService::class)->detail($team, $id);
            if ($p === null) {
                return;
            }
            $this->form = [
                'name' => $p->name, 'consumer_name' => $p->consumer_name ?? '',
                'role' => $p->role ?? '', 'class' => $p->class ?? '', 'level' => $p->level ?? '',
                'price_mode' => $p->price_mode, 'price_per_person' => $p->price_per_person,
                'ek_per_person' => $p->ek_per_person, 'food_cost_percent' => $p->food_cost_percent,
                'description' => $p->description ?? '', 'note' => $p->note ?? '',
            ];
        } else {
            $c = app(ConceptService::class)->detail($team, $id);
            if ($c === null) {
                return;
            }
            $this->form = [
                'name' => $c->name, 'consumer_name' => $c->consumer_name ?? '',
                'class' => $c->class ?? '', 'level' => $c->level ?? '', 'occasion' => $c->occasion ?? '',
                'category_id' => $c->category_id, 'taste_direction' => $c->taste_direction ?? '',
                'writing_style_id' => $c->writing_style_id, 'status' => $c->status,
                'description' => $c->description ?? '', 'additional_text' => $c->additional_text ?? '',
                'brief' => $c->brief ?? '', 'diet_requirement' => $c->diet_requirement ?? '',
                'structure_requirement' => $c->structure_requirement ?? '', 'season' => $c->season ?? '',
                'target_group' => $c->target_group ?? '', 'target_price_per_person' => $c->target_price_per_person,
                'price_mode' => $c->price_mode ?? 'auto', 'price_per_person_manual' => $c->price_per_person_manual,
                'note' => $c->note ?? '',
                // Facetten (Umbau-Spec Phase 4b)
                'serving_form_id' => $c->serving_form_id, 'event_type_id' => $c->event_type_id,
                'einsatzmoment_ids' => $c->serviceMoments()->pluck('foodalchemist_service_moments.id')->all(),
                'saison_ids' => $c->seasons()->pluck('foodalchemist_seasons.id')->all(),
            ];
            $this->slotForm = $c->slots->mapWithKeys(fn ($s) => [$s->id => ['role' => $s->role ?? '', 'title' => $s->title ?? '', 'is_pflicht' => (bool) $s->is_pflicht, 'quantity' => $s->quantity, 'unit_vocab_id' => $s->unit_vocab_id, 'wording' => $s->wording ?? '']])->all();
            $this->blockForm = $c->slots->mapWithKeys(fn ($s) => [$s->id => [
                'title' => $s->title ?? '', 'text_content' => $s->text_content ?? '',
                'price_value' => $s->price_value, 'price_basis' => $s->price_basis ?? 'person', 'height' => $s->height ?? 'mittel',
            ]])->all();
            // #389/Canvas: Concept-Canvas (kreatives Foodkonzept) über die zentrale Mechanik laden.
            $this->canvasInit('concept', 'concept', $id);
            // R4.1: Planungs-Gerüst (Soll-Rahmen) des Konzepts.
            $this->frameInit('concept', $id);
        }

        $this->dispatch('modal.open', name: 'concepter-editor');
    }

    public function setTab(string $tab): void
    {
        if (in_array($tab, ['aufbau', 'konzept', 'allergene', 'kalkulation', 'geschirr', 'sensorik', 'notes'], true)) {
            $this->tab = $tab;
        }
    }

    // ── #388 Geschirr-Tab ─────────────────────────────────────────────────────

    /** Picker für slot+role auf/zu (Toggle). */
    public function geschirrPicker(int $slotId, string $role = 'haupt'): void
    {
        $role = $role === 'alt' ? 'alt' : 'haupt';
        $offen = $this->geschirrPickSlotId === $slotId && $this->geschirrPickRolle === $role;
        $this->geschirrPickSlotId = $offen ? null : $slotId;
        $this->geschirrPickRolle = $role;
        $this->geschirrSuche = '';
    }

    public function geschirrWaehle(int $slotId, string $role, int $itemId): void
    {
        app(ConceptService::class)->setSlotGeschirr($this->team(), $slotId, $role, $itemId);
        $this->geschirrPickSlotId = null;
        $this->geschirrSuche = '';
    }

    public function geschirrEntfernen(int $slotId, string $role): void
    {
        app(ConceptService::class)->setSlotGeschirr($this->team(), $slotId, $role, null);
    }

    public function speichern(): void
    {
        if ($this->id === null) {
            return;
        }
        $team = $this->team();
        try {
            if ($this->type === 'pakete') {
                app(PaketService::class)->update($team, $this->id, $this->normForm());
            } else {
                $svc = app(ConceptService::class);
                $svc->update($team, $this->id, $this->normForm());
                // Facetten-Mehrfachdimensionen (Umbau-Spec Phase 4b)
                $svc->syncEinsatzmomente($team, $this->id, $this->form['einsatzmoment_ids'] ?? []);
                $svc->syncSaisons($team, $this->id, $this->form['saison_ids'] ?? []);
            }
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        $this->fehler = null;
        $this->dispatch('concepter-gespeichert', id: $this->id);
    }

    /** A1: explizite Form je Position setzen ('' = auto → Konzept-Form/Standard). */
    public function slotDarreichungSetzen(int $slotId, ?string $wert): void
    {
        if ($this->type !== 'concepts' || $this->id === null) {
            return;
        }
        try {
            app(ConceptService::class)->setSlotDarreichung(
                $this->team(), $slotId, ctype_digit((string) $wert) ? (int) $wert : null,
            );
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        $this->fehler = null;
        $this->dispatch('concepter-gespeichert', id: $this->id);
    }

    /** A2: Servier-Vehikel der aufgelösten Darreichung des Picker-Slots (Geschirr-Ranking). */
    private function geschirrVehikelBevorzugt($concept): ?int
    {
        if ($concept === null || $this->geschirrPickSlotId === null) {
            return null;
        }
        $slot = $concept->slots->firstWhere('id', $this->geschirrPickSlotId);
        if ($slot === null || $slot->sales_recipe_id === null) {
            return null;
        }
        $slot->setRelation('concept', $concept);
        $dar = app(\Platform\FoodAlchemist\Services\DarreichungResolver::class)->fuerSlot($slot);

        return $dar?->serving_vehicle_vocab_id !== null ? (int) $dar->serving_vehicle_vocab_id : null;
    }

    /** „Variante fehlt" (Umbau-Spec Phase 5): Darreichung zur Konzept-Servierform per 1-Klick anlegen. */
    public function varianteAnlegen(int $slotId): void
    {
        if ($this->type !== 'concepts' || $this->id === null) {
            return;
        }
        $team = $this->team();
        $concept = app(ConceptService::class)->detail($team, $this->id);
        $slot = $concept?->slots->firstWhere('id', $slotId);
        if ($concept?->serving_form_id === null || $slot === null || $slot->sales_recipe_id === null) {
            return;
        }
        try {
            app(\Platform\FoodAlchemist\Services\DarreichungService::class)
                ->anlegen($team, $slot->sales_recipe_id, $concept->serving_form_id, [], 'fa_ui');
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        $this->fehler = null;
        $this->dispatch('concepter-gespeichert', id: $this->id);
    }

    /** Facetten-Pill togglen (einsatzmoment_ids | saison_ids) und sofort sichern. */
    public function toggleFacette(string $feld, int $wert): void
    {
        if (! in_array($feld, ['einsatzmoment_ids', 'saison_ids'], true)) {
            return;
        }
        $ids = collect($this->form[$feld] ?? []);
        $this->form[$feld] = $ids->contains($wert)
            ? $ids->reject(fn ($v) => (int) $v === $wert)->values()->all()
            : $ids->push($wert)->values()->all();
        $this->speichern();
    }

    /** Concept-VK auto ⇄ manuell umschalten (und sofort sichern, damit Cockpit/KPI folgen). */
    public function setPreisModus(string $modus): void
    {
        if ($this->type !== 'concepts') {
            return;
        }
        $this->form['price_mode'] = in_array($modus, ['auto', 'manuell'], true) ? $modus : 'auto';
        $this->speichern();
    }

    /** Inline-Pflege des Brand-Voice-Anzeigenamens einer Position. */
    public function wordingSpeichern(int $slotId): void
    {
        if ($this->type !== 'concepts' || $this->id === null) {
            return;
        }
        app(ConceptService::class)->setSlotWording($this->team(), $slotId, $this->slotForm[$slotId]['wording'] ?? null);
        $this->dispatch('concepter-gespeichert', id: $this->id);
    }

    /**
     * Concept-übergreifendes Wording (✨): erzeugt im gewählten Schreibstil über ALLE Positionen
     * stimmig je Position einen Brand-Voice-Namen + einen Konzept-Einleitungstext. KI über das
     * Gateway (FakeAiProvider in Sandbox/Tests; echter Text erst mit LLM-Key — Muster wie VkModal::ki).
     */
    public function wordingGenerieren(): void
    {
        if ($this->type !== 'concepts' || $this->id === null) {
            return;
        }
        $team = $this->team();
        $concepts = app(ConceptService::class);
        $concept = $concepts->detail($team, $this->id);
        if ($concept === null) {
            return;
        }
        $stil = $this->form['writing_style_id'] ?? null;
        $kontext = [
            'concept' => $concept->name,
            'occasion' => $concept->occasion,
            'class' => $concept->class,
            'schreibstil' => $stil ? optional(FoodAlchemistWritingStyle::find($stil))->name : null,
            'positionen' => $concept->slots
                ->filter(fn ($s) => $s->sales_recipe_id !== null && $s->dish)
                ->map(fn ($s) => ['slot_id' => $s->id, 'name' => $s->dish->name, 'sales_wording_standard' => $s->dish->sales_wording_standard ?? null])
                ->values()->all(),
        ];
        try {
            // #389: Concept-ID durchreichen → Food-DNA-Concept-Override gilt für dieses Wording.
            $vorschlag = app(\Platform\FoodAlchemist\Services\Ai\AiGatewayService::class)->propose('concept.wording', $kontext, ['food_dna_concept_id' => $concept->id]);
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        $intro = $vorschlag->werte['intro'] ?? null;
        if (is_string($intro) && trim($intro) !== '') {
            $this->form['description'] = trim($intro);
            $concepts->update($team, $this->id, ['description' => trim($intro)]);
        }
        foreach (($vorschlag->werte['slots'] ?? []) as $slotId => $text) {
            if (is_string($text) && trim($text) !== '') {
                $concepts->setSlotWording($team, (int) $slotId, trim($text));
                if (isset($this->slotForm[(int) $slotId])) {
                    $this->slotForm[(int) $slotId]['wording'] = trim($text);
                }
            }
        }
        $this->fehler = null;
        $this->dispatch('concepter-gespeichert', id: $this->id);
    }

    /** Leerstrings → null (FK/optional sauber). */
    private function normForm(): array
    {
        return array_map(fn ($v) => $v === '' ? null : $v, $this->form);
    }

    // ── Aufbau · Concept-Slots ───────────────────────────────────────────────

    public function slotHinzu(): void
    {
        if ($this->type !== 'concepts' || $this->id === null) {
            return;
        }
        app(ConceptService::class)->addSlot($this->team(), $this->id, ['role' => $this->neuerSlotRolle ?: null, 'title' => $this->neuerSlotRolle ?: null]);
        $this->neuerSlotRolle = '';
        $this->reloadSlotForm();
        $this->dispatch('concepter-gespeichert', id: $this->id);
    }

    public function slotSpeichern(int $slotId): void
    {
        app(ConceptService::class)->updateSlot($this->team(), $slotId, $this->slotForm[$slotId] ?? []);
        $this->dispatch('concepter-gespeichert', id: $this->id);
    }

    /** Zeilen-Editor: Menge + Einheit einer Gericht-/Basisrezept-Position speichern. */
    public function mengeSpeichern(int $slotId): void
    {
        $f = $this->slotForm[$slotId] ?? [];
        $quantity = ($f['quantity'] ?? '') !== '' && $f['quantity'] !== null ? (float) $f['quantity'] : null;
        $unit = ($f['unit_vocab_id'] ?? '') !== '' ? (int) $f['unit_vocab_id'] : null;
        app(ConceptService::class)->setSlotMengeEinheit($this->team(), $slotId, $quantity, $unit);
        $this->dispatch('concepter-gespeichert', id: $this->id);
    }

    public function slotRaus(int $slotId): void
    {
        app(ConceptService::class)->removeSlot($this->team(), $slotId);
        $this->reloadSlotForm();
        $this->dispatch('concepter-gespeichert', id: $this->id);
    }

    public function slotHoch(int $slotId): void
    {
        $this->verschiebeSlot($slotId, -1);
    }

    public function slotRunter(int $slotId): void
    {
        $this->verschiebeSlot($slotId, 1);
    }

    private function verschiebeSlot(int $slotId, int $richtung): void
    {
        if ($this->id === null) {
            return;
        }
        $svc = app(ConceptService::class);
        $ids = $svc->detail($this->team(), $this->id)->slots->pluck('id')->all();
        $pos = array_search($slotId, $ids, true);
        $ziel = $pos + $richtung;
        if ($pos === false || $ziel < 0 || $ziel >= count($ids)) {
            return;
        }
        [$ids[$pos], $ids[$ziel]] = [$ids[$ziel], $ids[$pos]];
        $svc->reorderSlots($this->team(), $this->id, $ids);
        $this->dispatch('concepter-gespeichert', id: $this->id);
    }

    public function fuellePaket(int $slotId, int $paketId): void
    {
        app(ConceptService::class)->fillSlot($this->team(), $slotId, ['package_id' => $paketId]);
        $this->dispatch('concepter-gespeichert', id: $this->id);
    }

    public function gerichtPicker(int $slotId): void
    {
        $this->fillSlotId = $this->fillSlotId === $slotId ? null : $slotId;
        $this->gerichtSuche = '';
        $this->pickTyp = 'gericht';
        $this->pickFilterReset();
    }

    /** Befüllungs-Zeile (Paket-Tausch/Picker) einer Position auf-/zuklappen (Tabellen-Editor). */
    public function fillToggle(int $slotId): void
    {
        $this->fillOpenId = $this->fillOpenId === $slotId ? null : $slotId;
        if ($this->fillOpenId === null) {
            $this->fillSlotId = null;
        }
    }

    /** Basisrezepte-Filter: bei Hauptgruppen-Wechsel die Kategorie zurücksetzen. */
    public function updatedBasisHg(): void
    {
        $this->basisKat = null;
    }

    /** Picker-Quelle wechseln: VK-Gericht ⇄ Basisrezept (B2). */
    public function pickTypWaehle(string $typ): void
    {
        $this->pickTyp = $typ === 'basisrezept' ? 'basisrezept' : 'gericht';
        $this->gerichtSuche = '';
        $this->pickFilterReset();
    }

    // ── Aufbau · Gericht-Baum (VK-Hauptgruppe → Klasse → Geschmack) ───────────

    public function pickHgWaehle(?int $id): void
    {
        $this->pickHg = $this->pickHg === $id ? null : $id;
        $this->pickKlasse = null;   // Kaskade zurücksetzen (wie VK-Browser §4.1)
    }

    public function pickKlasseWaehle(int $id): void
    {
        $this->pickKlasse = $this->pickKlasse === $id ? null : $id;
    }

    public function pickGeschmackWaehle(string $wert): void
    {
        $this->pickGeschmack = $this->pickGeschmack === $wert ? '' : $wert;
    }

    private function pickFilterReset(): void
    {
        $this->pickHg = null;
        $this->pickKlasse = null;
        $this->pickGeschmack = '';
    }

    public function fuelleGericht(int $slotId, int $vkRecipeId, string $typ = 'gericht'): void
    {
        app(ConceptService::class)->fillSlot($this->team(), $slotId, [
            'sales_recipe_id' => $vkRecipeId, 'quantity' => 1, 'unit_vocab_id' => $this->portionEinheitId(),
            'type' => $typ === 'basisrezept' ? 'basisrezept' : 'gericht',
        ]);
        $this->fillSlotId = null;
        $this->gerichtSuche = '';
        $this->dispatch('concepter-gespeichert', id: $this->id);
    }

    public function slotLeeren(int $slotId): void
    {
        app(ConceptService::class)->fillSlot($this->team(), $slotId, []);
        $this->dispatch('concepter-gespeichert', id: $this->id);
    }

    /** Phase 3: aus den Seiten-Listen (Basisrezepte links / VK-Gerichte rechts) direkt eine neue Position anlegen. */
    public function positionEinfuegen(string $typ, int $id): void
    {
        if ($this->type !== 'concepts' || $this->id === null) {
            return;
        }
        $neu = $this->neuePosition($typ, $id, $this->einfuegenNachId);
        // Folge-Einfügungen ans selbe Ziel stapeln in natürlicher Reihenfolge (hinter die zuletzt eingefügte).
        if ($this->einfuegenNachId !== null && $neu !== null) {
            $this->einfuegenNachId = $neu;
        }
    }

    /** Gezieltes Einfügen per Drag aus der Liste: neue Position direkt HINTER $afterSlotId. */
    public function positionDrop(string $typ, int $id, int $afterSlotId): void
    {
        if ($this->type !== 'concepts' || $this->id === null) {
            return;
        }
        $this->neuePosition($typ, $id, $afterSlotId);
    }

    /** Inline-Drag-Reorder: bestehende Position $slotId direkt HINTER $afterSlotId verschieben. */
    public function positionVerschieben(int $slotId, int $afterSlotId): void
    {
        if ($this->type !== 'concepts' || $this->id === null || $slotId === $afterSlotId) {
            return;
        }
        $this->positionNach(app(ConceptService::class), $slotId, $afterSlotId);
        $this->reloadSlotForm();
        $this->dispatch('concepter-gespeichert', id: $this->id);
    }

    /** „+ Paket": neue Position anlegen, frisches Paket erstellen, einfügen und direkt zum Bearbeiten öffnen. */
    public function neuesPaketAlsPosition(): void
    {
        if ($this->type !== 'concepts' || $this->id === null) {
            return;
        }
        $team = $this->team();
        $svc = app(ConceptService::class);
        $slot = $svc->addSlot($team, $this->id, []);
        if ($this->einfuegenNachId !== null) {
            $this->positionNach($svc, $slot->id, $this->einfuegenNachId);
            $this->einfuegenNachId = $slot->id;
        }
        $paket = app(PaketService::class)->create($team, ['name' => 'Neues Paket']);
        $svc->fillSlot($team, $slot->id, ['package_id' => $paket->id]);
        $conceptId = $this->id;
        $this->dispatch('concepter-gespeichert', id: $conceptId);
        // direkt das neue Paket im selben Modal öffnen (Gerichte schnüren) …
        $this->oeffnen('pakete', $paket->id);
        // … und den Rückweg merken (oeffnen hat zurückgesetzt → danach setzen).
        $this->rueckSprungConceptId = $conceptId;
    }

    /** In ein bestehendes Paket reinspringen (Paket-Editor öffnen) — Rückweg ins Concept merken. */
    public function paketOeffnen(int $paketId): void
    {
        if ($this->type !== 'concepts' || $this->id === null) {
            return;
        }
        $conceptId = $this->id;
        $this->oeffnen('pakete', $paketId);
        $this->rueckSprungConceptId = $conceptId;
    }

    /** Aus dem Paket-Editor zurück ins auslösende Concept (Kopf des Pakets vorher sichern). */
    public function zurueckZumConcept(): void
    {
        $ziel = $this->rueckSprungConceptId;
        if ($this->type === 'pakete' && $this->id !== null) {
            $this->speichern();
        }
        if ($ziel !== null) {
            $this->oeffnen('concepts', $ziel);
        }
    }

    /** Ziel-Position fürs Einfügen setzen/abwählen (die nächste neue Position landet darunter). */
    public function zielSetzen(int $slotId): void
    {
        $this->einfuegenNachId = $this->einfuegenNachId === $slotId ? null : $slotId;
    }

    /**
     * Default-Einheit „portion" (dimension=count) — wird beim Einfügen mitgegeben, damit
     * Menge/EK von Anfang an wohldefiniert sind (vorher „—" → kein Übernehmen der Einheit).
     */
    private function portionEinheitId(): ?int
    {
        return \Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit::visibleToTeam($this->team())
            ->where('slug', 'portion')->value('id');
    }

    /** Legt eine gefüllte Position (Paket/Gericht/Basisrezept) an und sortiert sie ggf. hinter $afterId ein. */
    private function neuePosition(string $typ, int $id, ?int $afterId): ?int
    {
        $svc = app(ConceptService::class);
        $slot = $svc->addSlot($this->team(), $this->id, []);
        if ($typ === 'paket') {
            $svc->fillSlot($this->team(), $slot->id, ['package_id' => $id]);
        } else {
            $svc->fillSlot($this->team(), $slot->id, [
                'sales_recipe_id' => $id, 'quantity' => 1, 'unit_vocab_id' => $this->portionEinheitId(),
                'type' => $typ === 'basisrezept' ? 'basisrezept' : 'gericht',
            ]);
        }
        if ($afterId !== null) {
            $this->positionNach($svc, $slot->id, $afterId);
        }
        $this->reloadSlotForm();
        $this->dispatch('concepter-gespeichert', id: $this->id);

        return $slot->id;
    }

    /** Sortiert $slotId direkt hinter $afterId ein (über reorderSlots mit neu gebauter Reihenfolge). */
    private function positionNach(ConceptService $svc, int $slotId, int $afterId): void
    {
        $ids = $svc->detail($this->team(), $this->id)->slots->pluck('id')->map(fn ($x) => (int) $x)->all();
        $ids = array_values(array_filter($ids, fn ($x) => $x !== $slotId));
        $pos = array_search($afterId, $ids, true);
        if ($pos === false) {
            $ids[] = $slotId;
        } else {
            array_splice($ids, $pos + 1, 0, [$slotId]);
        }
        $svc->reorderSlots($this->team(), $this->id, $ids);
    }

    /**
     * M10R-4 (§10.2): inline NEUES Paket schnüren — legt ein Paket mit der Rolle des
     * Slots an, füllt den Slot damit und öffnet das neue Paket direkt im selben Modal
     * (Gerichte hinzufügen), ohne den Screen zu wechseln. „Speichern & schließen"
     * bringt zurück zum Concept (erneut auswählen).
     */
    public function neuesPaketImSlot(int $slotId): void
    {
        if ($this->type !== 'concepts' || $this->id === null) {
            return;
        }
        $team = $this->team();
        $svc = app(ConceptService::class);
        $slot = collect($svc->detail($team, $this->id)->slots)->firstWhere('id', $slotId);
        if ($slot === null) {
            return;
        }
        $role = $slot->role ?: null;
        $paket = app(PaketService::class)->create($team, [
            'name' => trim(($role ? $role . '-' : '') . 'Paket'),
            'role' => $role,
        ]);
        $svc->fillSlot($team, $slotId, ['package_id' => $paket->id]);
        $this->dispatch('concepter-gespeichert', id: $this->id);
        // direkt das neue Paket im selben Modal öffnen (Gerichte schnüren)
        $this->oeffnen('pakete', $paket->id);
    }

    // ── Vorlage (M10R-4 · D-CON-7) ───────────────────────────────────────────

    public function alsVorlage(): void
    {
        if ($this->type !== 'concepts' || $this->id === null) {
            return;
        }
        app(ConceptService::class)->alsVorlageSpeichern($this->team(), $this->id);
        $this->dispatch('concepter-gespeichert', id: $this->id);
        $this->dispatch('concepter-vorlage-gespeichert');
    }

    private function reloadSlotForm(): void
    {
        $c = app(ConceptService::class)->detail($this->team(), $this->id);
        $this->slotForm = $c ? $c->slots->mapWithKeys(fn ($s) => [$s->id => ['role' => $s->role ?? '', 'title' => $s->title ?? '', 'is_pflicht' => (bool) $s->is_pflicht, 'quantity' => $s->quantity, 'unit_vocab_id' => $s->unit_vocab_id, 'wording' => $s->wording ?? '']])->all() : [];
        $this->blockForm = $c ? $c->slots->mapWithKeys(fn ($s) => [$s->id => [
            'title' => $s->title ?? '', 'text_content' => $s->text_content ?? '',
            'price_value' => $s->price_value, 'price_basis' => $s->price_basis ?? 'person', 'height' => $s->height ?? 'mittel',
        ]])->all() : [];
    }

    // ── Aufbau · „Paket bilden" aus markierten Positionen (B4) ───────────────

    public function toggleAuswahl(int $slotId): void
    {
        $this->auswahl = in_array($slotId, $this->auswahl, true)
            ? array_values(array_diff($this->auswahl, [$slotId]))
            : [...$this->auswahl, $slotId];
    }

    public function paketBilden(): void
    {
        if ($this->type !== 'concepts' || $this->id === null || $this->auswahl === []) {
            return;
        }
        try {
            app(ConceptService::class)->bildePaketAusPositionen($this->team(), $this->id, $this->auswahl, $this->paketName ?: 'Paket');
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        $this->reset('auswahl', 'paketName', 'fehler');
        $this->reloadSlotForm();
        $this->dispatch('concepter-gespeichert', id: $this->id);
    }

    // ── Aufbau · Struktur-Blöcke (B3) ────────────────────────────────────────

    public function blockHinzu(string $type): void
    {
        if ($this->type !== 'concepts' || $this->id === null) {
            return;
        }
        app(ConceptService::class)->addBlock($this->team(), $this->id, $type);
        $this->reloadSlotForm();
        $this->dispatch('concepter-gespeichert', id: $this->id);
    }

    public function blockSpeichern(int $slotId): void
    {
        app(ConceptService::class)->updateBlock($this->team(), $slotId, $this->blockForm[$slotId] ?? []);
        $this->dispatch('concepter-gespeichert', id: $this->id);
    }

    // ── Aufbau · Paket-Gerichte ──────────────────────────────────────────────

    /** Park-Flow: Gericht mit der eingegebenen Menge/Person einfügen (Politur). */
    public function gerichtHinzu(int $vkRecipeId, $quantity = null): void
    {
        if ($this->type !== 'pakete' || $this->id === null) {
            return;
        }
        $svc = app(PaketService::class);
        $paket = $svc->detail($this->team(), $this->id);
        $items = $paket->dishes->map(fn ($g) => ['sales_recipe_id' => $g->sales_recipe_id, 'quantity' => $g->quantity, 'unit_vocab_id' => $g->unit_vocab_id])->all();
        if (! collect($items)->pluck('sales_recipe_id')->contains($vkRecipeId)) {
            $items[] = ['sales_recipe_id' => $vkRecipeId, 'quantity' => ($quantity !== null && $quantity !== '') ? (float) $quantity : null];
        }
        $svc->syncGerichte($this->team(), $this->id, $items);
        $this->paketGerichtSuche = '';
        $this->dispatch('concepter-gespeichert', id: $this->id);
    }

    public function gerichtRaus(int $vkRecipeId): void
    {
        $svc = app(PaketService::class);
        $paket = $svc->detail($this->team(), $this->id);
        $items = $paket->dishes->reject(fn ($g) => (int) $g->sales_recipe_id === $vkRecipeId)
            ->map(fn ($g) => ['sales_recipe_id' => $g->sales_recipe_id, 'quantity' => $g->quantity, 'unit_vocab_id' => $g->unit_vocab_id])->values()->all();
        $svc->syncGerichte($this->team(), $this->id, $items);
        $this->dispatch('concepter-gespeichert', id: $this->id);
    }

    public function gerichtMengeSpeichern(int $rowId, $quantity): void
    {
        app(PaketService::class)->setGerichtMenge($this->team(), $this->id, $rowId, $quantity !== '' ? (float) $quantity : null);
        $this->dispatch('concepter-gespeichert', id: $this->id);
    }

    public function gerichtHoch(int $rowId): void
    {
        $this->verschiebeGericht($rowId, -1);
    }

    public function gerichtRunter(int $rowId): void
    {
        $this->verschiebeGericht($rowId, 1);
    }

    private function verschiebeGericht(int $rowId, int $richtung): void
    {
        $svc = app(PaketService::class);
        $ids = $svc->detail($this->team(), $this->id)->dishes->pluck('id')->all();
        $pos = array_search($rowId, $ids, true);
        $ziel = $pos + $richtung;
        if ($pos === false || $ziel < 0 || $ziel >= count($ids)) {
            return;
        }
        [$ids[$pos], $ids[$ziel]] = [$ids[$ziel], $ids[$pos]];
        $svc->reorderGerichte($this->team(), $this->id, $ids);
        $this->dispatch('concepter-gespeichert', id: $this->id);
    }

    public function neuBerechnen(): void
    {
        if ($this->type === 'pakete' && $this->id !== null) {
            $p = app(PaketService::class)->detail($this->team(), $this->id);
            app(PaketService::class)->recomputePrice($p);
            $this->form['price_per_person'] = $p->refresh()->price_per_person;
            $this->form['ek_per_person'] = $p->ek_per_person;
            $this->form['food_cost_percent'] = $p->food_cost_percent;
            $this->dispatch('concepter-gespeichert', id: $this->id);
        }
    }

    // ── Sektor-Eignung (Politur · Concept) ───────────────────────────────────

    public function sektorHinzu(): void
    {
        if ($this->type !== 'concepts' || $this->id === null || trim($this->neuerSektor) === '') {
            return;
        }
        app(ConceptService::class)->setzeSektorEignung($this->team(), $this->id, $this->neuerSektor);
        $this->neuerSektor = '';
        $this->dispatch('concepter-gespeichert', id: $this->id);
    }

    public function sektorRaus(string $slug): void
    {
        if ($this->type === 'concepts' && $this->id !== null) {
            app(ConceptService::class)->entferneSektorEignung($this->team(), $this->id, $slug);
            $this->dispatch('concepter-gespeichert', id: $this->id);
        }
    }

    // ── Kalkulation · Zielpreis-Konfigurator (Concept, M13) ──────────────────

    public function zielpreisToggle(): void
    {
        $this->zielModus = ! $this->zielModus;
        $this->zielVorschlag = null;
    }

    public function zielpreisBerechnen(): void
    {
        $ziel = (float) str_replace(',', '.', $this->zielPreis);
        if ($this->type !== 'concepts' || $this->id === null || $ziel <= 0) {
            return;
        }
        $this->zielVorschlag = app(ConceptService::class)->zielpreisVorschlag($this->team(), $this->id, $ziel);
    }

    public function zielpreisUebernehmen(): void
    {
        if ($this->type === 'concepts' && $this->id !== null && $this->zielVorschlag !== null) {
            app(ConceptService::class)->zielpreisAnwenden($this->team(), $this->id, $this->zielVorschlag['vorschlag']);
        }
        $this->zielVorschlag = null;
        $this->zielModus = false;
        $this->dispatch('concepter-gespeichert', id: $this->id);
    }

    public function render(ConceptService $concepts, PaketService $pakete, ConcepterAggregateService $agg, ConcepterBewertungService $bewertung, KalkulationService $kalk, SalesRecipeService $sales)
    {
        $team = $this->team();
        $concept = null;
        $paket = null;
        $cockpit = null;
        $aggregat = null;
        $bewertet = null;
        $kalkulation = null;
        $tauschbar = [];
        $varianteFehlt = [];
        $darreichungInfo = [];
        $darreichungOptionen = [];
        $geschirrVorschlag = [];
        $sektionSumme = [];
        $kandidaten = collect();
        $paketKandidaten = collect();

        // Gericht-Baum (geteilt von beiden Pickern): aktiv, sobald ein Filter ODER Suchtext gesetzt ist.
        $pickFilter = ['hauptgruppe' => $this->pickHg, 'class' => $this->pickKlasse, 'geschmack' => $this->pickGeschmack];
        $pickAktiv = fn (string $suche) => $suche !== '' || $this->pickHg !== null || $this->pickKlasse !== null || $this->pickGeschmack !== '';

        if ($this->id !== null && $this->type === 'concepts') {
            $concept = $concepts->detail($team, $this->id);
            if ($concept !== null) {
                $cockpit = $concepts->preisCockpit($concept);
                $sektionSumme = $this->sektionsSummen($concept, $cockpit['zeilen']);
                $aggregat = $agg->conceptAggregat($concept);
                $bewertet = $bewertung->bewerten($concept, $cockpit, $aggregat);
                $kalkulation = $kalk->conceptHk($team, $concept);
                foreach ($concept->slots as $slot) {
                    $tauschbar[$slot->id] = $concepts->tauschbarePakete($team, $slot);
                }
                // Umbau-Spec Phase 5: aufgelöste Darreichung je Position sichtbar machen +
                // „Variante fehlt", wenn die Konzept-Servierform keine passende Form findet
                $resolver = app(\Platform\FoodAlchemist\Services\DarreichungResolver::class);
                foreach ($concept->slots as $slot) {
                    if ($slot->sales_recipe_id === null) {
                        continue;
                    }
                    $slot->setRelation('concept', $concept);
                    $formen = \Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung::with('servingForm')
                        ->where('recipe_id', $slot->sales_recipe_id)->orderByDesc('is_standard')->get();
                    if ($formen->count() > 1) {
                        // A1: Form-Picker je Position (nur sinnvoll bei mehreren Formen)
                        $darreichungOptionen[$slot->id] = $formen
                            ->map(fn ($f) => ['id' => $f->id, 'label' => $f->servingForm?->label ?? '—'])->all();
                    }
                    $dar = $resolver->fuerSlot($slot);
                    if ($dar !== null) {
                        $passtZurKonzeptForm = $concept->serving_form_id !== null
                            && (int) $dar->serving_form_id === (int) $concept->serving_form_id;
                        $darreichungInfo[$slot->id] = ($passtZurKonzeptForm || $concept->serving_form_id === null)
                            ? ($dar->servingForm?->label ?? '—')
                            : 'Standard: ' . ($dar->servingForm?->label ?? '—');
                        // Default-Geschirr der Form → Vorschlag am Slot (nur wenn dort noch keins gesetzt)
                        if ($dar->tableware_item_id !== null && $slot->tableware_item_id === null && $dar->dishwareItem !== null) {
                            $geschirrVorschlag[$slot->id] = [
                                'id' => $dar->tableware_item_id,
                                'label' => $dar->dishwareItem->label,
                                'form' => $dar->servingForm?->label,
                            ];
                        }
                    }
                    if ($concept->serving_form_id !== null) {
                        $hatForm = \Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung::where('recipe_id', $slot->sales_recipe_id)
                            ->where('serving_form_id', $concept->serving_form_id)->exists();
                        if (! $hatForm) {
                            $varianteFehlt[$slot->id] = true;
                        }
                    }
                }
                // Phase 3: persistente Seiten-Listen — links Basisrezepte ODER Pakete (Umschalter), rechts VK-Gerichte.
                // Kombi-Suche (wie Gerichte-Editor) filtert beide Seiten; sonst die jeweilige Listen-Suche.
                $linkeSuche = $this->kombiSuche !== '' ? $this->kombiSuche : $this->basisSuche;
                $rechteSuche = $this->kombiSuche !== '' ? $this->kombiSuche : $this->gerichtSuche;
                $basisListe = $this->linkeListe === 'basisrezept'
                    ? $pakete->basisKandidaten($team, $linkeSuche, [
                        'hauptgruppe' => $this->basisHg, 'category' => $this->basisKat, 'level' => $this->basisNiveau,
                    ])
                    : collect();
                $paketListe = $this->linkeListe === 'paket'
                    ? $pakete->paketKandidaten($team, $linkeSuche, ['class' => $this->paketKlasse])
                    : collect();
                $gerichtListe = $pakete->gerichtKandidaten($team, $rechteSuche, $pickFilter);
                if ($this->fillSlotId !== null) {
                    if ($this->pickTyp === 'basisrezept') {
                        $kandidaten = $this->gerichtSuche !== '' ? $pakete->basisKandidaten($team, $this->gerichtSuche) : collect();
                    } elseif ($pickAktiv($this->gerichtSuche)) {
                        $kandidaten = $pakete->gerichtKandidaten($team, $this->gerichtSuche, $pickFilter);
                    }
                }
            }
        } elseif ($this->id !== null && $this->type === 'pakete') {
            $paket = $pakete->detail($team, $this->id);
            if ($paket !== null) {
                $aggregat = $agg->paketAggregat($paket);
                $kalkulation = $kalk->paketHk($team, $paket);
                if ($this->paketQuelle === 'basisrezept') {
                    $paketKandidaten = $this->paketGerichtSuche !== ''
                        ? $pakete->basisKandidaten($team, $this->paketGerichtSuche)
                        : collect();
                } elseif ($pickAktiv($this->paketGerichtSuche)) {
                    $paketKandidaten = $pakete->gerichtKandidaten($team, $this->paketGerichtSuche, $pickFilter);
                }
            }
        }

        return view('foodalchemist::livewire.concepter.editor', [
            'pickHauptgruppen' => $sales->dishMainGroups($team),
            'pickHgCounts' => $sales->hauptgruppenCounts($team),
            'pickKlassen' => $this->pickHg !== null
                ? FoodAlchemistDishClass::where('dish_main_group_id', $this->pickHg)->orderBy('label')->get(['id', 'label'])
                : collect(),
            'pickKlassenCounts' => $this->pickHg !== null ? $sales->klassenCounts($team, $this->pickHg) : [],
            'concept' => $concept,
            'paket' => $paket,
            'cockpit' => $cockpit,
            'cockpitZeilen' => $cockpit ? collect($cockpit['zeilen'])->keyBy('slot_id') : collect(),
            'sektionSumme' => $sektionSumme,
            // B (UX-Umbau): aufgelöstes Wording je Gericht-Slot für die Menü-Ansicht
            // (Kette Konzept-Wording → VK-Wording-Standard → Name; Quelle für die Herkunft-Badge)
            'slotWording' => $concept
                ? collect($concept->slots)->filter(fn ($s) => $s->sales_recipe_id !== null)
                    ->mapWithKeys(fn ($s) => [$s->id => app(\Platform\FoodAlchemist\Services\WordingResolver::class)->fuerSlot($s)])->all()
                : [],
            'einheiten' => app(\Platform\FoodAlchemist\Services\VocabularyService::class)->listEinheiten($team),
            'aggregat' => $aggregat,
            'bewertung' => $bewertet,
            'kalkulation' => $kalkulation,
            'tauschbar' => $tauschbar,
            'varianteFehlt' => $varianteFehlt,
            'darreichungInfo' => $darreichungInfo,
            'darreichungOptionen' => $darreichungOptionen,
            'geschirrVorschlag' => $geschirrVorschlag,
            'kandidaten' => $kandidaten,
            'basisListe' => $basisListe ?? collect(),
            'paketListe' => $paketListe ?? collect(),
            'paketKlassenListe' => $this->type === 'concepts' ? $pakete->klassen($team) : [],
            'gerichtListe' => $gerichtListe ?? collect(),
            'basisHauptgruppen' => $this->type === 'concepts' ? app(\Platform\FoodAlchemist\Services\RecipeService::class)->mainGroups($team) : collect(),
            'basisKategorien' => ($this->type === 'concepts' && $this->basisHg !== null)
                ? \Platform\FoodAlchemist\Models\FoodAlchemistRecipeCategory::where('main_group_id', $this->basisHg)->orderBy('label')->get(['id', 'label'])
                : collect(),
            'basisNiveaus' => [['slug' => 'haute_cuisine', 'label' => 'Haute'], ['slug' => 'gehoben', 'label' => 'Gehoben'], ['slug' => 'klassisch', 'label' => 'Klassisch']],
            'typFarben' => app(\Platform\FoodAlchemist\Services\TeamSettingsService::class)->typFarben($team),
            'paketKandidaten' => $paketKandidaten,
            'sektorSlugs' => $concept !== null ? $concepts->sektorEignungSlugs($concept) : [],
            // 4c: Kategorie-Feld abgelöst — kategorienFlat nicht mehr benötigt
            // Facetten-Vokabulare (Umbau-Spec Phase 4b)
            'servierformen' => \Platform\FoodAlchemist\Models\FoodAlchemistServierform::where('is_inactive', false)
                ->orderBy('sort_order')->get(['id', 'code', 'label']),
            'eventtypen' => \Platform\FoodAlchemist\Models\FoodAlchemistEventtyp::visibleToTeam($team)
                ->where('is_inactive', false)->orderBy('sort_order')->get(['id', 'name']),
            'einsatzmomente' => \Platform\FoodAlchemist\Models\FoodAlchemistEinsatzmoment::visibleToTeam($team)
                ->where('is_inactive', false)->orderBy('sort_order')->get(['id', 'name']),
            'saisons' => \Platform\FoodAlchemist\Models\FoodAlchemistSaison::visibleToTeam($team)
                ->where('is_inactive', false)->orderBy('sort_order')->get(['id', 'name']),
            'klassen' => $this->type === 'pakete' ? $pakete->klassen($team) : $concepts->klassen($team),
            'rollen' => $pakete->rollen($team),
            'schreibstile' => FoodAlchemistWritingStyle::visibleToTeam($team)->where('is_inactive', false)
                ->orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
            // #388 Geschirr-Tab: Kandidaten nur wenn ein Picker offen ist (sonst leer = günstig).
            'geschirrKandidaten' => ($this->tab === 'geschirr' && $this->geschirrPickSlotId !== null)
                ? app(\Platform\FoodAlchemist\Services\GeschirrService::class)->searchItems(
                    $team, $this->geschirrSuche, 12, $this->geschirrVehikelBevorzugt($concept))
                : collect(),
            // Sensorik-Tab: Geschmacks-Balance + Textur über die Concept-Gerichte (nur wenn Tab aktiv).
            'sensorik' => ($this->tab === 'sensorik' && $concept !== null)
                ? app(\Platform\FoodAlchemist\Services\SensorikService::class)->fuerConcept($concept)
                : null,
        ]);
    }

    /**
     * Zwischensummen je Header-Sektion: summiert EK + €/P der Positionen, die einem Header
     * folgen, bis zum nächsten Header. Key = 'h'.<header-slot-id>. Positionen vor dem ersten
     * Header gehören zu keiner Sektion (globaler Streifen oben zeigt das Gesamt).
     *
     * @param  array  $zeilen  preisCockpit-Zeilen (mit slot_id, preis, ek)
     * @return array<string, array{ek: float, vk: float, n: int}>
     */
    private function sektionsSummen(FoodAlchemistConcept $concept, array $zeilen): array
    {
        $z = collect($zeilen)->keyBy('slot_id');
        $summen = [];
        $key = null;
        foreach ($concept->slots as $slot) {
            if (in_array($slot->type, ['header', 'header_preis'], true)) {
                $key = 'h'.$slot->id;
                $summen[$key] ??= ['ek' => 0.0, 'vk' => 0.0, 'n' => 0];

                continue;
            }
            if ($key === null || in_array($slot->type, ['text', 'spacer'], true)) {
                continue;
            }
            $row = $z->get($slot->id);
            if ($row === null) {
                continue;
            }
            $summen[$key]['ek'] += (float) ($row['ek'] ?? 0);
            $summen[$key]['vk'] += (float) ($row['price'] ?? 0);
            $summen[$key]['n']++;
        }

        return $summen;
    }

    private function team()
    {
        return Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
    }
}
