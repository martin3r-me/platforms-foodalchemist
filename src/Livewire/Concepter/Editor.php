<?php

namespace Platform\FoodAlchemist\Livewire\Concepter;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;
use Platform\FoodAlchemist\Models\FoodAlchemistWritingStyle;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\ConcepterAggregateService;
use Platform\FoodAlchemist\Services\ConcepterBewertungService;
use Platform\FoodAlchemist\Services\PaketService;

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
    public string $type = 'concepts';   // concepts | pakete

    public ?int $id = null;

    public string $tab = 'aufbau';       // aufbau | naehrwerte | allergene | kalkulation | notizen

    /** @var array<string, mixed> */
    public array $form = [];

    // Aufbau (Concept): neuer Slot + festes-Gericht-Picker
    public string $neuerSlotRolle = '';

    public ?int $fillSlotId = null;

    public string $gerichtSuche = '';

    /** @var array<int, array{rolle:string, titel:string}> */
    public array $slotForm = [];

    // Aufbau (Paket): Gericht-Suche
    public string $paketGerichtSuche = '';

    // Kalkulation (Concept): Zielpreis-Modus (M13)
    public bool $zielModus = false;

    public string $zielPreis = '';

    public ?array $zielVorschlag = null;

    public string $neuerSektor = '';

    public ?string $fehler = null;

    #[On('concepter-editor.oeffnen')]
    public function oeffnen(string $type, ?int $id): void
    {
        $this->reset(['form', 'slotForm', 'neuerSlotRolle', 'fillSlotId', 'gerichtSuche',
            'paketGerichtSuche', 'zielModus', 'zielPreis', 'zielVorschlag', 'fehler']);
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
                'name' => $p->name, 'konsumenten_name' => $p->konsumenten_name ?? '',
                'rolle' => $p->rolle ?? '', 'klasse' => $p->klasse ?? '', 'niveau' => $p->niveau ?? '',
                'preis_modus' => $p->preis_modus, 'preis_pro_person' => $p->preis_pro_person,
                'ek_pro_person' => $p->ek_pro_person, 'wareneinsatz_prozent' => $p->wareneinsatz_prozent,
                'beschreibung' => $p->beschreibung ?? '', 'note' => $p->note ?? '',
            ];
        } else {
            $c = app(ConceptService::class)->detail($team, $id);
            if ($c === null) {
                return;
            }
            $this->form = [
                'name' => $c->name, 'konsumenten_name' => $c->konsumenten_name ?? '',
                'klasse' => $c->klasse ?? '', 'niveau' => $c->niveau ?? '', 'anlass' => $c->anlass ?? '',
                'category_id' => $c->category_id, 'geschmacksrichtung' => $c->geschmacksrichtung ?? '',
                'schreibstil_id' => $c->schreibstil_id, 'status' => $c->status,
                'beschreibung' => $c->beschreibung ?? '', 'zusatztext' => $c->zusatztext ?? '',
                'brief' => $c->brief ?? '', 'diaet_vorgabe' => $c->diaet_vorgabe ?? '',
                'struktur_vorgabe' => $c->struktur_vorgabe ?? '', 'saison' => $c->saison ?? '',
                'zielgruppe' => $c->zielgruppe ?? '', 'zielpreis_pro_person' => $c->zielpreis_pro_person,
                'note' => $c->note ?? '',
            ];
            $this->slotForm = $c->slots->mapWithKeys(fn ($s) => [$s->id => ['rolle' => $s->rolle ?? '', 'titel' => $s->titel ?? '']])->all();
        }

        $this->dispatch('modal.open', name: 'concepter-editor');
    }

    public function setTab(string $tab): void
    {
        if (in_array($tab, ['aufbau', 'naehrwerte', 'allergene', 'kalkulation', 'notizen'], true)) {
            $this->tab = $tab;
        }
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
                app(ConceptService::class)->update($team, $this->id, $this->normForm());
            }
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();

            return;
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
        app(ConceptService::class)->addSlot($this->team(), $this->id, ['rolle' => $this->neuerSlotRolle ?: null, 'titel' => $this->neuerSlotRolle ?: null]);
        $this->neuerSlotRolle = '';
        $this->reloadSlotForm();
        $this->dispatch('concepter-gespeichert', id: $this->id);
    }

    public function slotSpeichern(int $slotId): void
    {
        app(ConceptService::class)->updateSlot($this->team(), $slotId, $this->slotForm[$slotId] ?? []);
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
        app(ConceptService::class)->fillSlot($this->team(), $slotId, ['paket_id' => $paketId]);
        $this->dispatch('concepter-gespeichert', id: $this->id);
    }

    public function gerichtPicker(int $slotId): void
    {
        $this->fillSlotId = $this->fillSlotId === $slotId ? null : $slotId;
        $this->gerichtSuche = '';
    }

    public function fuelleGericht(int $slotId, int $vkRecipeId): void
    {
        app(ConceptService::class)->fillSlot($this->team(), $slotId, ['vk_recipe_id' => $vkRecipeId, 'menge' => 1]);
        $this->fillSlotId = null;
        $this->gerichtSuche = '';
        $this->dispatch('concepter-gespeichert', id: $this->id);
    }

    public function slotLeeren(int $slotId): void
    {
        app(ConceptService::class)->fillSlot($this->team(), $slotId, []);
        $this->dispatch('concepter-gespeichert', id: $this->id);
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
        $rolle = $slot->rolle ?: null;
        $paket = app(PaketService::class)->create($team, [
            'name' => trim(($rolle ? $rolle . '-' : '') . 'Paket'),
            'rolle' => $rolle,
        ]);
        $svc->fillSlot($team, $slotId, ['paket_id' => $paket->id]);
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
        $this->slotForm = $c ? $c->slots->mapWithKeys(fn ($s) => [$s->id => ['rolle' => $s->rolle ?? '', 'titel' => $s->titel ?? '']])->all() : [];
    }

    // ── Aufbau · Paket-Gerichte ──────────────────────────────────────────────

    /** Park-Flow: Gericht mit der eingegebenen Menge/Person einfügen (Politur). */
    public function gerichtHinzu(int $vkRecipeId, $menge = null): void
    {
        if ($this->type !== 'pakete' || $this->id === null) {
            return;
        }
        $svc = app(PaketService::class);
        $paket = $svc->detail($this->team(), $this->id);
        $items = $paket->gerichte->map(fn ($g) => ['vk_recipe_id' => $g->vk_recipe_id, 'menge' => $g->menge, 'einheit_vocab_id' => $g->einheit_vocab_id])->all();
        if (! collect($items)->pluck('vk_recipe_id')->contains($vkRecipeId)) {
            $items[] = ['vk_recipe_id' => $vkRecipeId, 'menge' => ($menge !== null && $menge !== '') ? (float) $menge : null];
        }
        $svc->syncGerichte($this->team(), $this->id, $items);
        $this->paketGerichtSuche = '';
        $this->dispatch('concepter-gespeichert', id: $this->id);
    }

    public function gerichtRaus(int $vkRecipeId): void
    {
        $svc = app(PaketService::class);
        $paket = $svc->detail($this->team(), $this->id);
        $items = $paket->gerichte->reject(fn ($g) => (int) $g->vk_recipe_id === $vkRecipeId)
            ->map(fn ($g) => ['vk_recipe_id' => $g->vk_recipe_id, 'menge' => $g->menge, 'einheit_vocab_id' => $g->einheit_vocab_id])->values()->all();
        $svc->syncGerichte($this->team(), $this->id, $items);
        $this->dispatch('concepter-gespeichert', id: $this->id);
    }

    public function gerichtMengeSpeichern(int $rowId, $menge): void
    {
        app(PaketService::class)->setGerichtMenge($this->team(), $this->id, $rowId, $menge !== '' ? (float) $menge : null);
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
        $ids = $svc->detail($this->team(), $this->id)->gerichte->pluck('id')->all();
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
            $this->form['preis_pro_person'] = $p->refresh()->preis_pro_person;
            $this->form['ek_pro_person'] = $p->ek_pro_person;
            $this->form['wareneinsatz_prozent'] = $p->wareneinsatz_prozent;
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

    public function render(ConceptService $concepts, PaketService $pakete, ConcepterAggregateService $agg, ConcepterBewertungService $bewertung)
    {
        $team = $this->team();
        $concept = null;
        $paket = null;
        $cockpit = null;
        $aggregat = null;
        $bewertet = null;
        $tauschbar = [];
        $kandidaten = collect();
        $paketKandidaten = collect();

        if ($this->id !== null && $this->type === 'concepts') {
            $concept = $concepts->detail($team, $this->id);
            if ($concept !== null) {
                $cockpit = $concepts->preisCockpit($concept);
                $aggregat = $agg->conceptAggregat($concept);
                $bewertet = $bewertung->bewerten($concept, $cockpit, $aggregat);
                foreach ($concept->slots as $slot) {
                    $tauschbar[$slot->id] = $concepts->tauschbarePakete($team, $slot);
                }
                if ($this->fillSlotId !== null && $this->gerichtSuche !== '') {
                    $kandidaten = $pakete->gerichtKandidaten($team, $this->gerichtSuche);
                }
            }
        } elseif ($this->id !== null && $this->type === 'pakete') {
            $paket = $pakete->detail($team, $this->id);
            if ($paket !== null) {
                $aggregat = $agg->paketAggregat($paket);
                if ($this->paketGerichtSuche !== '') {
                    $paketKandidaten = $pakete->gerichtKandidaten($team, $this->paketGerichtSuche);
                }
            }
        }

        return view('foodalchemist::livewire.concepter.editor', [
            'concept' => $concept,
            'paket' => $paket,
            'cockpit' => $cockpit,
            'aggregat' => $aggregat,
            'bewertung' => $bewertet,
            'tauschbar' => $tauschbar,
            'kandidaten' => $kandidaten,
            'paketKandidaten' => $paketKandidaten,
            'sektorSlugs' => $concept !== null ? $concepts->sektorEignungSlugs($concept) : [],
            'kategorienFlat' => $this->type === 'concepts' ? $concepts->categoriesFlat($team) : [],
            'klassen' => $this->type === 'pakete' ? $pakete->klassen($team) : $concepts->klassen($team),
            'rollen' => $pakete->rollen($team),
            'schreibstile' => FoodAlchemistWritingStyle::visibleToTeam($team)->where('is_inactive', false)
                ->orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    private function team()
    {
        return Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
    }
}
