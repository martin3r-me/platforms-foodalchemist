<?php

namespace Platform\FoodAlchemist\Livewire\Recipes;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeCategory;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\RecipeService;

/**
 * M4-06 / P-2: Rezept-Stammdaten-Modal — Name (§1-Syntax-Hint, „Name putzen"-KI),
 * Herkunft, Hauptgruppe→Kategorie, Geschmack/Fertigung, yield_kg_manual (A-3),
 * VK-Flag. Edit triggert Recompute bei kalkulations-relevanten Feldern.
 */
class RecipeModal extends Component
{
    use \Livewire\WithFileUploads;

    private const LEER = [
        'name' => '', 'herkunft' => '', 'kategorie_id' => null, 'hauptgruppe_id' => null,
        'geschmacksrichtung' => '', 'fertigungstiefe' => '', 'arbeitszeit_min' => null,
        'temperatur' => '', 'funktion' => '', 'status' => 'draft',
        'yield_kg_manual' => null, 'ertrag_stueck' => null, 'beschreibung' => '', 'zubereitung' => '',
        'notizen_manual' => '', 'equipment_ids' => [], 'ist_verkaufsrezept' => false,
    ];

    public ?int $recipeId = null;

    public array $form = self::LEER;

    public ?string $fehler = null;

    #[On('recipe-modal.oeffnen')]
    public function oeffnen(?int $id = null): void
    {
        $this->reset('fehler');
        $this->recipeId = $id;
        $this->form = self::LEER;

        if ($id !== null) {
            $team = Auth::user()?->currentTeamRelation;
            $r = FoodAlchemistRecipe::visibleToTeam($team)->with(['kategorie:id,main_group_id', 'equipment:id'])->find($id);
            if ($r !== null) {
                $this->form = [
                    'name' => $r->name,
                    'herkunft' => $r->herkunft ?? '',
                    'kategorie_id' => $r->kategorie_id,
                    'hauptgruppe_id' => $r->kategorie?->main_group_id,
                    'geschmacksrichtung' => $r->geschmacksrichtung ?? '',
                    'fertigungstiefe' => $r->fertigungstiefe ?? '',
                    'arbeitszeit_min' => $r->arbeitszeit_min,
                    'temperatur' => $r->temperatur ?? '',
                    'funktion' => $r->funktion ?? '',
                    'status' => $r->status->value,
                    'yield_kg_manual' => $r->yield_kg_manual,
                    'ertrag_stueck' => $r->ertrag_stueck,
                    'beschreibung' => $r->beschreibung ?? '',
                    'zubereitung' => $r->zubereitung ?? '',
                    'notizen_manual' => $r->notizen_manual ?? '',
                    'equipment_ids' => $r->equipment()->pluck('foodalchemist_vocab_kochequipment.id')->map(fn ($i) => (string) $i)->all(),
                    'ist_verkaufsrezept' => (bool) $r->ist_verkaufsrezept,
                ];
            }
        }

        $this->dispatch('modal.open', name: 'recipe-modal');
    }

    public function speichern(RecipeService $recipes): void
    {
        $this->fehler = null;
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null) {
            return;
        }

        try {
            $in = [...$this->form,
                'arbeitszeit_min' => $this->form['arbeitszeit_min'] !== null && $this->form['arbeitszeit_min'] !== '' ? (int) $this->form['arbeitszeit_min'] : null,
                'yield_kg_manual' => $this->form['yield_kg_manual'] !== null && $this->form['yield_kg_manual'] !== '' ? (float) str_replace(',', '.', (string) $this->form['yield_kg_manual']) : null,
                'ertrag_stueck' => $this->form['ertrag_stueck'] !== null && $this->form['ertrag_stueck'] !== '' ? (float) str_replace(',', '.', (string) $this->form['ertrag_stueck']) : null,
            ];
            $recipe = $this->recipeId === null
                ? $recipes->create($team, $in)
                : $recipes->update($team, $this->recipeId, $in);

            $this->dispatch('modal.close', name: 'recipe-modal');
            $this->dispatch('recipe-gespeichert');
            $this->dispatch('recipe-selected', id: $recipe->id);
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    /** @var array<string, array{werte: array, confidence: float, begruendung: ?string}> transiente GL-07-Vorschläge */
    public array $kiVorschlag = [];

    // ── M4-11: GL-07-Lebenszyklus beschreibung ──────────────────────────

    public function ai_beschreibung(AiGatewayService $ki): void
    {
        $r = $this->rezept();
        $vorschlag = $ki->propose('recipe.beschreibung', [
            'name' => $r?->name ?? $this->form['name'],
            'beschreibung' => $this->form['beschreibung'] ?: null,
            'zutaten' => $r?->ingredients?->pluck('raw_text')->take(20)->all() ?? [],
        ]);
        $this->kiVorschlag['beschreibung'] = [
            'werte' => $vorschlag->werte,
            'confidence' => max(0.0, min(1.0, $vorschlag->confidence)),
            'begruendung' => $vorschlag->begruendung,
        ];
    }

    public function accept_beschreibung(): void
    {
        $r = $this->rezept();
        $vorschlag = $this->kiVorschlag['beschreibung'] ?? null;
        if ($r === null || $vorschlag === null) {
            return;
        }
        if ($r->beschreibung_quelle === 'manual') {                          // GL-07 Override-First
            $this->fehler = 'Beschreibung ist manuell gepflegt — erst Reset, dann KI übernehmen.';

            return;
        }
        $wert = $vorschlag['werte']['beschreibung'] ?? null;
        if (! is_string($wert) || trim($wert) === '') {
            $this->fehler = 'KI-Vorschlag enthält keine Beschreibung.';

            return;
        }
        $r->update(['beschreibung' => $wert, 'beschreibung_quelle' => 'ki', 'beschreibung_ai_confidence' => $vorschlag['confidence']]);
        $this->form['beschreibung'] = $wert;
        unset($this->kiVorschlag['beschreibung']);
    }

    public function clear_beschreibung(): void
    {
        $this->rezept()?->update(['beschreibung' => null, 'beschreibung_quelle' => null, 'beschreibung_ai_confidence' => null]);
        $this->form['beschreibung'] = '';
        unset($this->kiVorschlag['beschreibung']);
    }

    public function manual_beschreibung(): void
    {
        if (trim($this->form['beschreibung']) !== '') {
            $this->rezept()?->update(['beschreibung' => $this->form['beschreibung'], 'beschreibung_quelle' => 'manual', 'beschreibung_ai_confidence' => null]);
        }
    }

    // ── UI-Audit: GL-07-Lebenszyklus zubereitung (D-5 §4.2.5, V-02-Klasse) ──

    public function ai_zubereitung(AiGatewayService $ki): void
    {
        $r = $this->rezept();
        $vorschlag = $ki->propose('recipe.zubereitung', [
            'name' => $r?->name ?? $this->form['name'],
            'zubereitung' => $this->form['zubereitung'] ?: null,
            'zutaten' => $r?->ingredients?->pluck('raw_text')->take(30)->all() ?? [],
        ]);
        $this->kiVorschlag['zubereitung'] = [
            'werte' => $vorschlag->werte,
            'confidence' => max(0.0, min(1.0, $vorschlag->confidence)),
            'begruendung' => $vorschlag->begruendung,
        ];
    }

    public function accept_zubereitung(): void
    {
        $r = $this->rezept();
        $vorschlag = $this->kiVorschlag['zubereitung'] ?? null;
        if ($r === null || $vorschlag === null) {
            return;
        }
        if ($r->zubereitung_quelle === 'manual') {                            // GL-07 Override-First
            $this->fehler = 'Zubereitung ist manuell gepflegt — erst Reset, dann KI übernehmen.';

            return;
        }
        $wert = $vorschlag['werte']['zubereitung'] ?? null;
        if (! is_string($wert) || trim($wert) === '') {
            $this->fehler = 'KI-Vorschlag enthält keine Zubereitung.';

            return;
        }
        $r->update(['zubereitung' => $wert, 'zubereitung_quelle' => 'ki', 'zubereitung_ai_confidence' => $vorschlag['confidence']]);
        $this->form['zubereitung'] = $wert;
        unset($this->kiVorschlag['zubereitung']);
    }

    public function clear_zubereitung(): void
    {
        $this->rezept()?->update(['zubereitung' => null, 'zubereitung_quelle' => null, 'zubereitung_ai_confidence' => null]);
        $this->form['zubereitung'] = '';
        unset($this->kiVorschlag['zubereitung']);
    }

    public function manual_zubereitung(): void
    {
        if (trim($this->form['zubereitung']) !== '') {
            $this->rezept()?->update(['zubereitung' => $this->form['zubereitung'], 'zubereitung_quelle' => 'manual', 'zubereitung_ai_confidence' => null]);
        }
    }

    // ── M4-11: GL-07-Lebenszyklus kategorie ─────────────────────────────

    public function ai_kategorie(AiGatewayService $ki, RecipeService $recipes): void
    {
        $r = $this->rezept();
        $team = Auth::user()?->currentTeamRelation;
        $vorschlag = $ki->propose('recipe.kategorie', [
            'name' => $r?->name ?? $this->form['name'],
            'kategorie_id' => $this->form['kategorie_id'],
            'kategorien' => $team !== null
                ? FoodAlchemistRecipeCategory::orderBy('id')->limit(200)->pluck('bezeichnung', 'id')->all()
                : [],
        ]);
        $this->kiVorschlag['kategorie'] = [
            'werte' => $vorschlag->werte,
            'confidence' => max(0.0, min(1.0, $vorschlag->confidence)),
            'begruendung' => $vorschlag->begruendung,
        ];
    }

    public function accept_kategorie(): void
    {
        $r = $this->rezept();
        $vorschlag = $this->kiVorschlag['kategorie'] ?? null;
        if ($r === null || $vorschlag === null) {
            return;
        }
        if ($r->kategorie_quelle === 'manual') {
            $this->fehler = 'Kategorie ist manuell gepflegt — erst Reset, dann KI übernehmen.';

            return;
        }
        $katId = $vorschlag['werte']['kategorie_id'] ?? null;
        $kategorie = $katId !== null ? FoodAlchemistRecipeCategory::find((int) $katId) : null;
        if ($kategorie === null) {
            $this->fehler = 'KI-Vorschlag enthält keine gültige Kategorie.';

            return;
        }
        $r->update([
            'kategorie_id' => $kategorie->id, 'kategorie_quelle' => 'ki',
            'kategorie_ai_confidence' => $vorschlag['confidence'],
            'kategorie_ai_begruendung' => $vorschlag['begruendung'],
        ]);
        $this->form['kategorie_id'] = $kategorie->id;
        $this->form['hauptgruppe_id'] = $kategorie->main_group_id;
        unset($this->kiVorschlag['kategorie']);
    }

    public function clear_kategorie(): void
    {
        $this->rezept()?->update(['kategorie_id' => null, 'kategorie_quelle' => null, 'kategorie_ai_confidence' => null, 'kategorie_ai_begruendung' => null]);
        $this->form['kategorie_id'] = null;
        unset($this->kiVorschlag['kategorie']);
    }

    public function manual_kategorie(): void
    {
        if ($this->form['kategorie_id'] !== null) {
            $this->rezept()?->update(['kategorie_id' => $this->form['kategorie_id'], 'kategorie_quelle' => 'manual', 'kategorie_ai_confidence' => null, 'kategorie_ai_begruendung' => null]);
        }
    }

    private function rezept(): ?FoodAlchemistRecipe
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($this->recipeId === null || $team === null) {
            return null;
        }

        return FoodAlchemistRecipe::visibleToTeam($team)->with('ingredients:id,recipe_id,raw_text')->find($this->recipeId);
    }

    // ── Editor-Parität (Ist-App-Vorbild): Löschen · ✨-Header-Aktionen · Anreichern ──

    // ── R6e: ✨ KI-Überarbeiten — freie Anweisung, Vorschau, Übernehmen (GL-07) ──

    public bool $ueberarbeitenOffen = false;

    public string $anweisung = '';

    /** @var ?array{werte: array, confidence: float} Vorschau — NICHTS persistiert */
    public ?array $ueberarbeitung = null;

    /** Re-Mount-Zähler für den eingebetteten Zutaten-Editor (rows leben im Client). */
    public int $zutatenVersion = 0;

    public function kiUeberarbeiten(): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->recipeId === null || trim($this->anweisung) === '') {
            $this->fehler = 'Anweisung ist Pflicht (z. B. «mach das Rezept vegan und halbiere den Zucker»).';

            return;
        }
        $this->fehler = null;
        $r = app(RecipeService::class)->detailAnySicht($team, $this->recipeId);
        if ($r === null) {
            return;
        }

        try {
            $vorschlag = app(AiGatewayService::class)->propose('recipe.ueberarbeiten', [
                'anweisung' => trim($this->anweisung),
                'name' => $r->name,
                'beschreibung' => $r->beschreibung,
                'zubereitung' => $r->zubereitung,
                'zutaten' => $r->ingredients->map(fn ($z) => [
                    'id' => $z->id,
                    'text' => $z->gp?->name ?? $z->referencedRecipe?->name ?? $z->display_name ?? $z->raw_text,
                    'menge' => (float) $z->menge,
                    'einheit_slug' => $z->einheit?->slug,
                ])->values()->all(),
            ]);
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();

            return;
        }

        if (empty($vorschlag->werte['zutaten']) && empty($vorschlag->werte['zubereitung']) && empty($vorschlag->werte['beschreibung'])) {
            $this->fehler = 'KI lieferte keine verwertbare Überarbeitung — echter Provider nötig (FakeProvider-Grenze).';

            return;
        }
        $this->ueberarbeitung = ['werte' => $vorschlag->werte, 'confidence' => max(0.0, min(1.0, $vorschlag->confidence))];
    }

    /** Übernehmen = der EINE Schreib-Moment: Zutaten-Sync + Text-Felder mit Lineage ki. */
    public function ueberarbeitungUebernehmen(): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->recipeId === null || $this->ueberarbeitung === null) {
            return;
        }
        $werte = $this->ueberarbeitung['werte'];
        $r = app(RecipeService::class)->detailAnySicht($team, $this->recipeId);
        $einheiten = FoodAlchemistRecipe::query()->getConnection()->table('foodalchemist_vocab_einheiten')
            ->whereNull('deleted_at')->pluck('id', 'slug');

        try {
            if (! empty($werte['zutaten']) && is_array($werte['zutaten'])) {
                $original = $r->ingredients->keyBy('id');
                $zeilen = [];
                foreach ($werte['zutaten'] as $z) {
                    if (! is_array($z)) {
                        continue;
                    }
                    $orig = isset($z['id']) ? $original->get((int) $z['id']) : null;
                    $menge = is_numeric(str_replace(',', '.', (string) ($z['menge'] ?? ''))) ? (float) str_replace(',', '.', (string) $z['menge']) : null;
                    $zeilen[] = [
                        'id' => $orig?->id,
                        'gp_id' => $orig?->gp_id,                     // Verknüpfung des Originals bleibt
                        'referenced_recipe_id' => $orig?->referenced_recipe_id,
                        'raw_text' => (string) ($z['text'] ?? $orig?->raw_text ?? ''),
                        'display_name' => (string) ($z['text'] ?? $orig?->display_name ?? ''),
                        'menge' => $menge ?? (float) ($orig?->menge ?? 1),
                        'einheit_vocab_id' => $einheiten[$z['einheit_slug'] ?? ''] ?? $orig?->einheit_vocab_id ?? $einheiten['g'] ?? null,
                        'garverlust_pct' => $orig?->garverlust_pct,
                        'is_optional' => (bool) ($orig?->is_optional ?? false),
                        'note' => $orig?->note,
                    ];
                }
                if ($zeilen !== []) {
                    app(RecipeService::class)->syncIngredients($team, $this->recipeId, $zeilen);
                }
            }
            // Texte im Bestands-Muster (accept_zubereitung): direkter Write MIT Lineage,
            // Override-First — manuell gepflegte Felder bleiben unangetastet (GL-07 §4.2)
            $frisch = $r->fresh();
            if (is_string($werte['beschreibung'] ?? null) && trim($werte['beschreibung']) !== '' && $frisch->beschreibung_quelle !== 'manual') {
                $frisch->update(['beschreibung' => $werte['beschreibung'], 'beschreibung_quelle' => 'ki', 'beschreibung_ai_confidence' => $this->ueberarbeitung['confidence']]);
                $this->form['beschreibung'] = $werte['beschreibung'];
            }
            if (is_string($werte['zubereitung'] ?? null) && trim($werte['zubereitung']) !== '' && $frisch->zubereitung_quelle !== 'manual') {
                $frisch->update(['zubereitung' => $werte['zubereitung'], 'zubereitung_quelle' => 'ki', 'zubereitung_ai_confidence' => $this->ueberarbeitung['confidence']]);
                $this->form['zubereitung'] = $werte['zubereitung'];
            }
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();

            return;
        }

        $this->ueberarbeitung = null;
        $this->anweisung = '';
        $this->ueberarbeitenOffen = false;
        $this->zutatenVersion++;                                      // eingebetteten Editor neu mounten (Client-rows!)
        $this->dispatch('recipe-gespeichert');
    }

    public function ueberarbeitungVerwerfen(): void
    {
        $this->ueberarbeitung = null;                                 // reject lässt Fachdaten unberührt (GL-07)
    }

    // ── R6: Step-by-Step-Fotos (an die Zubereitung gekoppelt über schritt_nr) ──

    public $fotoUpload = null;

    public ?int $fotoSchritt = null;

    public string $fotoCaption = '';

    public function fotoHochladen(): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->recipeId === null || $this->fotoUpload === null) {
            return;
        }
        $r = FoodAlchemistRecipe::visibleToTeam($team)->findOrFail($this->recipeId);
        if ((int) $r->team_id !== (int) $team->id) {
            $this->fehler = 'Geerbtes Rezept — Fotos nur durchs Besitzer-Team (D1).';

            return;
        }
        $this->validate(['fotoUpload' => 'image|max:8192'], [], ['fotoUpload' => 'Foto']);
        $pfad = $this->fotoUpload->store("foodalchemist/rezepte/{$this->recipeId}", 'public');
        \Platform\FoodAlchemist\Models\FoodAlchemistRecipeStepPhoto::create([
            'team_id' => $team->id,
            'recipe_id' => $this->recipeId,
            'schritt_nr' => max(0, (int) $this->fotoSchritt),
            'pfad' => $pfad,
            'caption' => trim($this->fotoCaption) ?: null,
        ]);
        $this->reset('fotoUpload', 'fotoSchritt', 'fotoCaption');
    }

    public function fotoLoeschen(int $fotoId): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->recipeId === null) {
            return;
        }
        $foto = \Platform\FoodAlchemist\Models\FoodAlchemistRecipeStepPhoto::where('recipe_id', $this->recipeId)
            ->where('team_id', $team->id)->find($fotoId);
        if ($foto !== null) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($foto->pfad);
            $foto->delete();
        }
    }

    /** R6: Template-Markierung an/aus (Service-Guard: nur Besitzer-Team, D1). */
    public function templateToggle(): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->recipeId === null) {
            return;
        }
        try {
            app(RecipeService::class)->setTemplate($team, $this->recipeId);
            $this->dispatch('recipe-gespeichert');
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function loeschen(RecipeService $recipes): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->recipeId === null) {
            return;
        }
        try {
            $recipes->delete($team, $this->recipeId);
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        $this->dispatch('modal.close', name: 'recipe-modal');
        $this->dispatch('recipe-gespeichert');
    }

    /** ✨ Fertigung: Vorschlag direkt ins Feld (wie namePutzen — nichts persistiert). */
    public function kiFertigung(AiGatewayService $ki): void
    {
        $r = $this->rezept();
        $vorschlag = $ki->propose('recipe.fertigungstiefe', [
            'name' => $this->form['name'],
            'fertigungstiefe' => $this->form['fertigungstiefe'] ?: null,
            'zutaten' => $r?->ingredients?->pluck('raw_text')->take(30)->all() ?? [],
        ]);
        $wert = $vorschlag->werte['fertigungstiefe'] ?? null;
        if (in_array($wert, ['from_scratch', 'teilfertig', 'convenience'], true)) {
            $this->form['fertigungstiefe'] = $wert;
        }
    }

    /** ✨ Eigenschaften: Arbeitszeit/Temperatur/Funktion + Geschmack in die Form (Ist-App-Pendant). */
    public function kiEigenschaften(AiGatewayService $ki): void
    {
        $r = $this->rezept();
        $zutaten = $r?->ingredients?->pluck('raw_text')->take(30)->all() ?? [];
        $eigenschaften = $ki->propose('recipe.eigenschaften', [
            'name' => $this->form['name'],
            'haltbarkeit_tage' => null, 'regenerierbarkeit' => null, 'transportstabilitaet' => null,
            'arbeitszeit_min' => $this->form['arbeitszeit_min'], 'temperatur' => $this->form['temperatur'] ?: null,
            'funktion' => $this->form['funktion'] ?: null, 'zutaten' => $zutaten,
        ]);
        foreach (['arbeitszeit_min', 'temperatur', 'funktion'] as $feld) {
            if (! empty($eigenschaften->werte[$feld])) {
                $this->form[$feld] = $eigenschaften->werte[$feld];
            }
        }
        $geschmack = $ki->propose('recipe.geschmack', [
            'name' => $this->form['name'], 'geschmacksrichtung' => $this->form['geschmacksrichtung'] ?: null, 'zutaten' => $zutaten,
        ]);
        if (in_array($geschmack->werte['geschmacksrichtung'] ?? null, ['suess', 'herzhaft', 'neutral'], true)) {
            $this->form['geschmacksrichtung'] = $geschmack->werte['geschmacksrichtung'];
        }
    }

    /** ✨ Equipment: Slug-Vorschläge → Auswahl-Pills (nichts persistiert). */
    public function kiEquipment(AiGatewayService $ki): void
    {
        $r = $this->rezept();
        $vorschlag = $ki->propose('recipe.equipment', [
            'name' => $this->form['name'],
            'equipment_slugs' => [],
            'vokabular' => \Platform\FoodAlchemist\Models\FoodAlchemistVocabKochequipment::pluck('slug')->all(),
            'zutaten' => $r?->ingredients?->pluck('raw_text')->take(30)->all() ?? [],
        ]);
        $slugs = array_filter((array) ($vorschlag->werte['equipment_slugs'] ?? []), 'is_string');
        if ($slugs !== []) {
            $ids = \Platform\FoodAlchemist\Models\FoodAlchemistVocabKochequipment::whereIn('slug', $slugs)
                ->pluck('id')->map(fn ($i) => (string) $i)->all();
            $this->form['equipment_ids'] = array_values(array_unique([...$this->form['equipment_ids'], ...$ids]));
        }
    }

    // ── ✨ Alles anreichern (D-5 §4.4 auf EIN Rezept — Bulk-Mechanik M7-06) ──

    public ?int $bulkRunId = null;

    public function allesAnreichern(): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->recipeId === null) {
            return;
        }
        $this->bulkRunId = app(\Platform\FoodAlchemist\Services\BulkEnrichService::class)
            ->starte($team, [$this->recipeId]);
    }

    public function bulkAlleUebernehmen(): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team !== null && $this->bulkRunId !== null) {
            app(\Platform\FoodAlchemist\Services\BulkEnrichService::class)->alleUebernehmen($team, $this->bulkRunId);
            $this->bulkRunId = null;
            $this->oeffnen($this->recipeId);                          // Form mit den übernommenen Werten neu laden
            $this->dispatch('recipe-gespeichert');
        }
    }

    // ── Zubereitung: Markdown-Vorschau (Schreiben/Vorschau-Tabs, Ist-App) ──

    public ?string $zubereitungVorschau = null;

    public function vorschauZubereitung(): void
    {
        $this->zubereitungVorschau = trim($this->form['zubereitung']) !== ''
            ? \Illuminate\Support\Str::markdown($this->form['zubereitung'])
            : '<p class="text-gray-400">— leer —</p>';
    }

    /** „Name putzen": §1-Syntax via KI-Gateway (GL-07: Vorschlag direkt ins Feld, nichts persistiert). */
    public function namePutzen(AiGatewayService $ki): void
    {
        if (trim($this->form['name']) === '') {
            return;
        }
        $vorschlag = $ki->propose('recipe.name_putzen', ['name' => trim($this->form['name'])]);
        if (! empty($vorschlag->werte['name']) && is_string($vorschlag->werte['name'])) {
            $this->form['name'] = $vorschlag->werte['name'];
        }
    }

    public function updatedFormHauptgruppeId(): void
    {
        $this->form['kategorie_id'] = null;                        // Kategorie hängt an der HG
    }

    public function render(RecipeService $recipes)
    {
        $team = Auth::user()?->currentTeamRelation;

        // UI-Audit: ehrliche Feld-Zustände für die KI-Felder-Sektion (vorher
        // zeigte »unbefüllt« trotz Inhalt — Quelle NULL bei Import-Beständen)
        $r = $this->rezept();
        $feldZustand = function (?string $inhalt, ?string $quelle): string {
            if ($inhalt === null || trim($inhalt) === '') {
                return 'unbefüllt';
            }

            return $quelle ?? 'import';
        };

        $voll = $r !== null && $team !== null ? app(RecipeService::class)->detailAnySicht($team, $r->id) : null;
        $bulkRun = $this->bulkRunId !== null && $team !== null
            ? app(\Platform\FoodAlchemist\Services\BulkEnrichService::class)->status($team, $this->bulkRunId) : null;

        return view('foodalchemist::livewire.recipes.recipe-modal', [
            'neu' => $this->recipeId === null,
            'istTemplate' => (bool) ($r?->is_template ?? false),
            'schrittFotos' => $this->recipeId !== null
                ? \Platform\FoodAlchemist\Models\FoodAlchemistRecipeStepPhoto::where('recipe_id', $this->recipeId)
                    ->orderBy('schritt_nr')->orderBy('sort_order')->orderBy('id')->get()->groupBy('schritt_nr')
                : collect(),
            'voll' => $voll,
            'bulkRun' => $bulkRun,
            'bulkOffen' => $bulkRun !== null
                ? app(\Platform\FoodAlchemist\Services\BulkEnrichService::class)->offeneVorschlaege($team, $this->bulkRunId) : 0,
            'zustaende' => [
                'beschreibung' => $feldZustand($r?->beschreibung, $r?->beschreibung_quelle),
                'zubereitung' => $feldZustand($r?->zubereitung, $r?->zubereitung_quelle),
                'kategorie' => $r?->kategorie_id !== null ? ($r?->kategorie_quelle ?? 'import') : 'unbefüllt',
            ],
            'equipmentListe' => \Platform\FoodAlchemist\Models\FoodAlchemistVocabKochequipment::orderBy('gruppe')->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'gruppe']),
            'hauptgruppen' => $team !== null ? $recipes->mainGroups($team) : collect(),
            'kategorien' => $this->form['hauptgruppe_id'] !== null
                ? FoodAlchemistRecipeCategory::where('main_group_id', $this->form['hauptgruppe_id'])->orderBy('sort_order')->get()
                : collect(),
            'keyVorschau' => trim($this->form['name']) !== '' ? $recipes->rezeptKey($this->form['name']) : '',
        ]);
    }
}
