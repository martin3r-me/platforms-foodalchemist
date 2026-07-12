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
        'name' => '', 'origin_source' => '', 'category_id' => null, 'hauptgruppe_id' => null,
        'taste_direction' => '', 'production_depth' => '', 'work_time_min' => null,
        'temperature' => '', 'function' => '', 'status' => 'draft',
        'yield_kg_manual' => null, 'yield_pieces' => null, 'description' => '', 'preparation' => '',
        'notes_manual' => '', 'equipment_ids' => [], 'is_sales_recipe' => false,
    ];

    public ?int $recipeId = null;

    public array $form = self::LEER;

    public ?string $fehler = null;

    /** Navigations-Stack: aus Rezept A ein Sub-Rezept B öffnen → A wird gemerkt; ✕ springt zurück zu A. */
    public array $navStack = [];

    public bool $istOffen = false;

    #[On('recipe-modal.oeffnen')]
    public function oeffnen(?int $id = null): void
    {
        // Sub-Navigation: aus einem bereits OFFENEN Rezept ein anderes öffnen → Eltern auf den Stack.
        if ($this->istOffen && $this->recipeId !== null && $id !== null && $this->recipeId !== $id) {
            $this->navStack[] = $this->recipeId;
        }
        $this->ladeRezept($id);
    }

    /** ✕ am Rezept-Modal: bei Sub-Navigation zurück zum Eltern-Rezept, sonst hart schließen. */
    public function schliessenOderZurueck(): void
    {
        if (! empty($this->navStack)) {
            $this->ladeRezept((int) array_pop($this->navStack));   // zurück — KEIN erneuter Push
            return;
        }
        $this->dispatch('modal.close', name: 'recipe-modal');
    }

    #[On('modal.closed')]
    public function beiModalClosed(?string $name = null): void
    {
        if ($name === 'recipe-modal') {                            // hartes Schließen (Backdrop/Escape/✕ ohne Stack) → Stack leeren
            $this->istOffen = false;
            $this->navStack = [];
        }
    }

    private function ladeRezept(?int $id): void
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
                    'origin_source' => $r->origin_source ?? '',
                    'category_id' => $r->category_id,
                    'hauptgruppe_id' => $r->category?->main_group_id,
                    'taste_direction' => $r->taste_direction ?? '',
                    'production_depth' => $r->production_depth ?? '',
                    'work_time_min' => $r->work_time_min,
                    'temperature' => $r->temperature ?? '',
                    'function' => $r->function ?? '',
                    'status' => $r->status->value,
                    'yield_kg_manual' => $r->yield_kg_manual,
                    'yield_pieces' => $r->yield_pieces,
                    'description' => $r->description ?? '',
                    'preparation' => $r->preparation ?? '',
                    'notes_manual' => $r->notes_manual ?? '',
                    'equipment_ids' => $r->equipment()->pluck('foodalchemist_vocab_kitchen_equipment.id')->map(fn ($i) => (string) $i)->all(),
                    'is_sales_recipe' => (bool) $r->is_sales_recipe,
                ];
            }
        }

        $this->istOffen = true;
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
            // Numerik-Guard (wie Preis-Anlage): leer = automatische Yield-Berechnung (A-3 COALESCE),
            // aber ein Tippfehler darf nicht still als 0 landen — yield_kg_manual=0 macht ek_per_kg_eur
            // null und vergiftet die Kalkulation (GL-02). 0/negativ ist als Yield/Ertrag nie gültig.
            $rohYield = trim(str_replace(',', '.', (string) ($this->form['yield_kg_manual'] ?? '')));
            $rohStk = trim(str_replace(',', '.', (string) ($this->form['yield_pieces'] ?? '')));
            if ($rohYield !== '' && (! is_numeric($rohYield) || (float) $rohYield <= 0)) {
                $this->fehler = 'Manuelles Yield braucht eine Zahl > 0 (oder leer lassen für die automatische Berechnung).';

                return;
            }
            if ($rohStk !== '' && (! is_numeric($rohStk) || (float) $rohStk <= 0)) {
                $this->fehler = 'Ertrag (Stück) braucht eine Zahl > 0 (oder leer lassen).';

                return;
            }
            $in = [...$this->form,
                'work_time_min' => $this->form['work_time_min'] !== null && $this->form['work_time_min'] !== '' ? (int) $this->form['work_time_min'] : null,
                'yield_kg_manual' => $rohYield !== '' ? (float) $rohYield : null,
                'yield_pieces' => $rohStk !== '' ? (float) $rohStk : null,
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

    /** @var array<string, array{werte: array, confidence: float, reasoning: ?string}> transiente GL-07-Vorschläge */
    public array $kiVorschlag = [];

    // ── M4-11: GL-07-Lebenszyklus description ──────────────────────────

    public function ai_beschreibung(AiGatewayService $ki): void
    {
        $r = $this->rezept();
        $vorschlag = $ki->propose('recipe.description', [
            'name' => $r?->name ?? $this->form['name'],
            'description' => $this->form['description'] ?: null,
            'zutaten' => $r?->ingredients?->pluck('raw_text')->take(20)->all() ?? [],
        ]);
        $this->kiVorschlag['description'] = [
            'werte' => $vorschlag->werte,
            'confidence' => max(0.0, min(1.0, $vorschlag->confidence)),
            'reasoning' => $vorschlag->reasoning,
        ];
    }

    public function accept_beschreibung(): void
    {
        $r = $this->rezept();
        $vorschlag = $this->kiVorschlag['description'] ?? null;
        if ($r === null || $vorschlag === null) {
            return;
        }
        if ($r->description_source === 'manual') {                          // GL-07 Override-First
            $this->fehler = 'Beschreibung ist manuell gepflegt — erst Reset, dann KI übernehmen.';

            return;
        }
        $wert = $vorschlag['werte']['description'] ?? null;
        if (! is_string($wert) || trim($wert) === '') {
            $this->fehler = 'KI-Vorschlag enthält keine Beschreibung.';

            return;
        }
        $r->update(['description' => $wert, 'description_source' => 'ki', 'description_ai_confidence' => $vorschlag['confidence']]);
        $this->form['description'] = $wert;
        unset($this->kiVorschlag['description']);
    }

    public function clear_beschreibung(): void
    {
        $this->rezept()?->update(['description' => null, 'description_source' => null, 'description_ai_confidence' => null]);
        $this->form['description'] = '';
        unset($this->kiVorschlag['description']);
    }

    public function manual_beschreibung(): void
    {
        if (trim($this->form['description']) !== '') {
            $this->rezept()?->update(['description' => $this->form['description'], 'description_source' => 'manual', 'description_ai_confidence' => null]);
        }
    }

    // ── UI-Audit: GL-07-Lebenszyklus preparation (D-5 §4.2.5, V-02-Klasse) ──

    public function ai_zubereitung(AiGatewayService $ki): void
    {
        $r = $this->rezept();
        $vorschlag = $ki->propose('recipe.preparation', [
            'name' => $r?->name ?? $this->form['name'],
            'preparation' => $this->form['preparation'] ?: null,
            'zutaten' => $r?->ingredients?->pluck('raw_text')->take(30)->all() ?? [],
        ]);
        $this->kiVorschlag['preparation'] = [
            'werte' => $vorschlag->werte,
            'confidence' => max(0.0, min(1.0, $vorschlag->confidence)),
            'reasoning' => $vorschlag->reasoning,
        ];
    }

    public function accept_zubereitung(): void
    {
        $r = $this->rezept();
        $vorschlag = $this->kiVorschlag['preparation'] ?? null;
        if ($r === null || $vorschlag === null) {
            return;
        }
        if ($r->preparation_source === 'manual') {                            // GL-07 Override-First
            $this->fehler = 'Zubereitung ist manuell gepflegt — erst Reset, dann KI übernehmen.';

            return;
        }
        $wert = $vorschlag['werte']['preparation'] ?? null;
        if (! is_string($wert) || trim($wert) === '') {
            $this->fehler = 'KI-Vorschlag enthält keine Zubereitung.';

            return;
        }
        $r->update(['preparation' => $wert, 'preparation_source' => 'ki', 'preparation_ai_confidence' => $vorschlag['confidence']]);
        $this->form['preparation'] = $wert;
        unset($this->kiVorschlag['preparation']);
    }

    public function clear_zubereitung(): void
    {
        $this->rezept()?->update(['preparation' => null, 'preparation_source' => null, 'preparation_ai_confidence' => null]);
        $this->form['preparation'] = '';
        unset($this->kiVorschlag['preparation']);
    }

    public function manual_zubereitung(): void
    {
        if (trim($this->form['preparation']) !== '') {
            $this->rezept()?->update(['preparation' => $this->form['preparation'], 'preparation_source' => 'manual', 'preparation_ai_confidence' => null]);
        }
    }

    // ── M4-11: GL-07-Lebenszyklus kategorie ─────────────────────────────

    public function ai_kategorie(AiGatewayService $ki, RecipeService $recipes): void
    {
        $r = $this->rezept();
        $team = Auth::user()?->currentTeamRelation;
        $vorschlag = $ki->propose('recipe.category', [
            'name' => $r?->name ?? $this->form['name'],
            'category_id' => $this->form['category_id'],
            'kategorien' => $team !== null
                ? FoodAlchemistRecipeCategory::visibleToTeam($team)->orderBy('id')->limit(200)->pluck('label', 'id')->all()
                : [],
        ]);
        $this->kiVorschlag['category'] = [
            'werte' => $vorschlag->werte,
            'confidence' => max(0.0, min(1.0, $vorschlag->confidence)),
            'reasoning' => $vorschlag->reasoning,
        ];
    }

    public function accept_kategorie(): void
    {
        $r = $this->rezept();
        $vorschlag = $this->kiVorschlag['category'] ?? null;
        if ($r === null || $vorschlag === null) {
            return;
        }
        if ($r->category_source === 'manual') {
            $this->fehler = 'Kategorie ist manuell gepflegt — erst Reset, dann KI übernehmen.';

            return;
        }
        $katId = $vorschlag['werte']['category_id'] ?? null;
        $kategorie = $katId !== null ? FoodAlchemistRecipeCategory::find((int) $katId) : null;
        if ($kategorie === null) {
            $this->fehler = 'KI-Vorschlag enthält keine gültige Kategorie.';

            return;
        }
        $r->update([
            'category_id' => $kategorie->id, 'category_source' => 'ki',
            'category_ai_confidence' => $vorschlag['confidence'],
            'category_ai_reasoning' => $vorschlag['reasoning'],
        ]);
        $this->form['category_id'] = $kategorie->id;
        $this->form['hauptgruppe_id'] = $kategorie->main_group_id;
        unset($this->kiVorschlag['category']);
    }

    public function clear_kategorie(): void
    {
        $this->rezept()?->update(['category_id' => null, 'category_source' => null, 'category_ai_confidence' => null, 'category_ai_reasoning' => null]);
        $this->form['category_id'] = null;
        unset($this->kiVorschlag['category']);
    }

    public function manual_kategorie(): void
    {
        if ($this->form['category_id'] !== null) {
            $this->rezept()?->update(['category_id' => $this->form['category_id'], 'category_source' => 'manual', 'category_ai_confidence' => null, 'category_ai_reasoning' => null]);
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
                'description' => $r->description,
                'preparation' => $r->preparation,
                'zutaten' => $r->ingredients->map(fn ($z) => [
                    'id' => $z->id,
                    'text' => $z->gp?->name ?? $z->referencedRecipe?->name ?? $z->display_name ?? $z->raw_text,
                    'quantity' => (float) $z->quantity,
                    'einheit_slug' => $z->unit?->slug,
                ])->values()->all(),
            ]);
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();

            return;
        }

        if (empty($vorschlag->werte['zutaten']) && empty($vorschlag->werte['preparation']) && empty($vorschlag->werte['description'])) {
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
        $einheiten = FoodAlchemistRecipe::query()->getConnection()->table('foodalchemist_vocab_units')
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
                    $quantity = is_numeric(str_replace(',', '.', (string) ($z['quantity'] ?? ''))) ? (float) str_replace(',', '.', (string) $z['quantity']) : null;
                    $zeilen[] = [
                        'id' => $orig?->id,
                        'gp_id' => $orig?->gp_id,                     // Verknüpfung des Originals bleibt
                        'referenced_recipe_id' => $orig?->referenced_recipe_id,
                        'raw_text' => (string) ($z['text'] ?? $orig?->raw_text ?? ''),
                        'display_name' => (string) ($z['text'] ?? $orig?->display_name ?? ''),
                        'quantity' => $quantity ?? (float) ($orig?->quantity ?? 1),
                        'unit_vocab_id' => $einheiten[$z['einheit_slug'] ?? ''] ?? $orig?->unit_vocab_id ?? $einheiten['g'] ?? null,
                        'cooking_loss_pct' => $orig?->cooking_loss_pct,
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
            if (is_string($werte['description'] ?? null) && trim($werte['description']) !== '' && $frisch->description_source !== 'manual') {
                $frisch->update(['description' => $werte['description'], 'description_source' => 'ki', 'description_ai_confidence' => $this->ueberarbeitung['confidence']]);
                $this->form['description'] = $werte['description'];
            }
            if (is_string($werte['preparation'] ?? null) && trim($werte['preparation']) !== '' && $frisch->preparation_source !== 'manual') {
                $frisch->update(['preparation' => $werte['preparation'], 'preparation_source' => 'ki', 'preparation_ai_confidence' => $this->ueberarbeitung['confidence']]);
                $this->form['preparation'] = $werte['preparation'];
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
        $vorschlag = $ki->propose('recipe.production_depth', [
            'name' => $this->form['name'],
            'production_depth' => $this->form['production_depth'] ?: null,
            'zutaten' => $r?->ingredients?->pluck('raw_text')->take(30)->all() ?? [],
        ]);
        $wert = $vorschlag->werte['production_depth'] ?? null;
        if (in_array($wert, ['from_scratch', 'teilfertig', 'convenience'], true)) {
            $this->form['production_depth'] = $wert;
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
            'work_time_min' => $this->form['work_time_min'], 'temperature' => $this->form['temperature'] ?: null,
            'function' => $this->form['function'] ?: null, 'zutaten' => $zutaten,
        ]);
        foreach (['work_time_min', 'temperature', 'function'] as $feld) {
            if (! empty($eigenschaften->werte[$feld])) {
                $this->form[$feld] = $eigenschaften->werte[$feld];
            }
        }
        $geschmack = $ki->propose('recipe.geschmack', [
            'name' => $this->form['name'], 'taste_direction' => $this->form['taste_direction'] ?: null, 'zutaten' => $zutaten,
        ]);
        if (in_array($geschmack->werte['taste_direction'] ?? null, ['suess', 'herzhaft', 'neutral'], true)) {
            $this->form['taste_direction'] = $geschmack->werte['taste_direction'];
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

    /** ✨ Sensorik: KI bewertet das GEGARTE Rezept (Zutaten + Zubereitung) → Recipe-Sensorik-Tabellen. */
    public function sensorikBewerten(): void
    {
        if ($this->recipeId !== null) {
            app(\Platform\FoodAlchemist\Services\SensorikService::class)->bewerteRezept($this->recipeId, true);
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
        $this->zubereitungVorschau = trim($this->form['preparation']) !== ''
            ? \Illuminate\Support\Str::markdown($this->form['preparation'])
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
        $this->form['category_id'] = null;                        // Kategorie hängt an der HG
    }

    public function render(RecipeService $recipes)
    {
        $team = Auth::user()?->currentTeamRelation;

        // UI-Audit: ehrliche Feld-Zustände für die KI-Felder-Sektion (vorher
        // zeigte »unbefüllt« trotz Inhalt — Quelle NULL bei Import-Beständen)
        $r = $this->rezept();
        $feldZustand = function (?string $inhalt, ?string $source): string {
            if ($inhalt === null || trim($inhalt) === '') {
                return 'unbefüllt';
            }

            return $source ?? 'import';
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
                'description' => $feldZustand($r?->description, $r?->description_source),
                'preparation' => $feldZustand($r?->preparation, $r?->preparation_source),
                'category' => $r?->category_id !== null ? ($r?->category_source ?? 'import') : 'unbefüllt',
            ],
            'equipmentListe' => \Platform\FoodAlchemist\Models\FoodAlchemistVocabKochequipment::orderBy('group_name')->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'group_name']),
            'hauptgruppen' => $team !== null ? $recipes->mainGroups($team) : collect(),
            'kategorien' => $this->form['hauptgruppe_id'] !== null && $team !== null
                ? FoodAlchemistRecipeCategory::visibleToTeam($team)->where('main_group_id', $this->form['hauptgruppe_id'])->orderBy('sort_order')->get()
                : collect(),
            'keyVorschau' => trim($this->form['name']) !== '' ? $recipes->rezeptKey($this->form['name']) : '',
            'sensorik' => $this->recipeId !== null ? app(\Platform\FoodAlchemist\Services\SensorikService::class)->fuerRezept($this->recipeId) : null,
            'pairing' => $r !== null ? app(\Platform\FoodAlchemist\Services\PairingService::class)->panelRecipe($r) : null,
        ]);
    }
}
