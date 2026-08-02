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
    use \Platform\FoodAlchemist\Livewire\Concerns\HatRezeptCopilot;   // Spec 03 L6b

    private const LEER = [
        'name' => '', 'origin_source' => '', 'category_id' => null, 'hauptgruppe_id' => null,
        'taste_direction' => '', 'production_depth' => '', 'work_time_min' => null,
        'temperature' => '', 'function' => '', 'status' => 'draft',
        'yield_kg_manual' => null, 'yield_pieces' => null, 'description' => '', 'preparation' => '',
        'notes_manual' => '', 'equipment_ids' => [], 'is_sales_recipe' => false,
        // Stufe 3 — Auto-Produktionsplaner
        'default_station_id' => null, 'max_vorlauf_tage' => null, 'setup_time_min' => null,
        'batch_max_kg' => null, 'batch_max_pieces' => null,
    ];

    public ?int $recipeId = null;

    public array $form = self::LEER;

    public ?string $fehler = null;

    /** Navigations-Stack: aus Rezept A ein Sub-Rezept B öffnen → A wird gemerkt; ✕ springt zurück zu A. */
    public array $navStack = [];

    public bool $istOffen = false;

    /**
     * $copilot = Sprung aus dem Signal-Cockpit (Spec 21 · S5b): die abgelegten Befunde
     * werden direkt aufgeklappt. Kein Prüf-Call — s. `copilotAusAblage()`.
     */
    #[On('recipe-modal.oeffnen')]
    public function oeffnen(?int $id = null, bool $copilot = false): void
    {
        // Sub-Navigation: aus einem bereits OFFENEN Rezept ein anderes öffnen → Eltern auf den Stack.
        if ($this->istOffen && $this->recipeId !== null && $id !== null && $this->recipeId !== $id) {
            $this->navStack[] = $this->recipeId;
        }
        $this->ladeRezept($id);
        if ($copilot && $this->recipeId !== null) {
            $this->copilotAusAblage();
        }
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
        $this->copilotZuruecksetzen();                             // L6b: Befunde gehören zu GENAU diesem Rezept
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
                    'default_station_id' => $r->default_station_id,
                    'max_vorlauf_tage' => $r->max_vorlauf_tage,
                    'setup_time_min' => $r->setup_time_min,
                    'batch_max_kg' => $r->batch_max_kg,
                    'batch_max_pieces' => $r->batch_max_pieces,
                    'temperature' => $r->temperature ?? '',
                    'function' => $r->function ?? '',
                    'status' => $r->status->value,
                    'yield_kg_manual' => $r->yield_kg_manual,
                    'yield_pieces' => $r->yield_pieces,
                    'description' => $r->description ?? '',
                    // 'preparation' wird BEWUSST nicht geladen (Spec 27): der Inhalt lebt in
                    // den Schritten, das Feld ist nur ihr Spiegel. Ein geladener Wert würde
                    // beim Speichern den Spiegel überschreiben. Das Form-Feld dient nur der
                    // Anlage (Freitext → Parser), im Edit-Modus bleibt es leer.
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
            $ganz = fn ($v) => $v !== null && $v !== '' ? max(0, (int) $v) : null;
            $in = [...$this->form,
                'work_time_min' => $this->form['work_time_min'] !== null && $this->form['work_time_min'] !== '' ? (int) $this->form['work_time_min'] : null,
                'yield_kg_manual' => $rohYield !== '' ? (float) $rohYield : null,
                'yield_pieces' => $rohStk !== '' ? (float) $rohStk : null,
                // Stufe 3 — Planer-Felder sanitisieren
                'default_station_id' => $this->form['default_station_id'] ?: null,
                'max_vorlauf_tage' => $ganz($this->form['max_vorlauf_tage']),
                'setup_time_min' => $ganz($this->form['setup_time_min']),
                'batch_max_kg' => ($b = trim(str_replace(',', '.', (string) ($this->form['batch_max_kg'] ?? '')))) !== '' ? (float) $b : null,
                'batch_max_pieces' => ($bp = trim(str_replace(',', '.', (string) ($this->form['batch_max_pieces'] ?? '')))) !== '' ? (float) $bp : null,
            ];
            $warNeu = $this->recipeId === null;
            if (! $warNeu) {
                // Spec 27: `preparation` ist im Edit-Modus nur ein SPIEGEL der Schritte.
                // Der (nicht mehr gerenderte) Form-Wert darf ihn nicht überschreiben —
                // RecipeService::update schreibt per array_key_exists jeden mitgesandten Key.
                unset($in['preparation']);
            }
            $recipe = $warNeu
                ? $recipes->create($team, $in)
                : $recipes->update($team, $this->recipeId, $in);
            // Beim ANLEGEN getippter Freitext wird in Schritte geparst — das macht
            // RecipeService::create zentral (gilt so für jeden Schreibweg, Spec 27).

            // #509 Create-Parität (VkModal::anlegen-Muster): nach dem Anlegen NICHT
            // schließen, sondern nahtlos in den Edit-Modus springen — Zutaten/Deklaration/
            // Darreichungen (allesamt @if($recipeId !== null)) werden sofort befüllbar,
            // statt dass der Nutzer das frische Rezept erst wieder heraussuchen muss.
            if ($warNeu) {
                $this->ladeRezept($recipe->id);
            } else {
                $this->dispatch('modal.close', name: 'recipe-modal');
            }
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
        $this->fehler = null;
        $r = $this->rezept();
        try {
            $vorschlag = $ki->propose('recipe.description', [
                'name' => $r?->name ?? $this->form['name'],
                'description' => $this->form['description'] ?: null,
                'zutaten' => $r?->ingredients?->pluck('raw_text')->take(20)->all() ?? [],
            ]);
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();

            return;
        }
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

    // ── GL-07-Lebenszyklus preparation (D-5 §4.2.5) ─────────────────────
    //
    // Spec 27: der Zubereitungs-INHALT wird nicht mehr hier gepflegt, sondern im
    // eingebetteten Schritt-Editor (`StepEditor`) — inkl. KI (`recipe.steps`).
    // Hier bleibt nur die LINEAGE-Steuerung: „manuell" sperrt gegen KI-Überschreiben,
    // „Reset" hebt die Sperre auf. Der Text selbst ist ein Spiegel der Schritte und
    // wird deshalb hier NIE geleert (das täte man über das Löschen der Schritte).

    /** Sperrt die Zubereitung gegen KI-Überschreiben (GL-07 Override-First). */
    public function manual_zubereitung(): void
    {
        $this->rezept()?->update(['preparation_source' => 'manual', 'preparation_ai_confidence' => null]);
    }

    /** Hebt die Lineage-Markierung auf (ki/manual → offen). Der Text bleibt. */
    public function clear_zubereitung(): void
    {
        $this->rezept()?->update(['preparation_source' => null, 'preparation_ai_confidence' => null]);
    }

    // ── M4-11: GL-07-Lebenszyklus kategorie ─────────────────────────────

    public function ai_kategorie(AiGatewayService $ki, RecipeService $recipes): void
    {
        $this->fehler = null;
        $r = $this->rezept();
        $team = Auth::user()?->currentTeamRelation;
        try {
            $vorschlag = $ki->propose('recipe.category', [
                'name' => $r?->name ?? $this->form['name'],
                'category_id' => $this->form['category_id'],
                'kategorien' => $team !== null
                    ? FoodAlchemistRecipeCategory::visibleToTeam($team)->orderBy('id')->limit(200)->pluck('label', 'id')->all()
                    : [],
            ]);
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();

            return;
        }
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
        $this->ueberarbeitung = [
            'werte' => $vorschlag->werte,
            'confidence' => max(0.0, min(1.0, $vorschlag->confidence)),
            // E3 (#508): Vorschau, wie das Grounding beim Übernehmen greift.
            'match_vorschau' => $this->matchVorschau($team, $r, $vorschlag->werte['zutaten'] ?? []),
        ];
    }

    /**
     * E3 (#508): pro vorgeschlagener Zutat der künftige Grounding-Status.
     * Die Logik liegt seit Spec 03 L1a im geteilten `RecipeReviseService`
     * (das VkModal fährt dieselbe Strecke); hier bleibt nur der Durchgriff,
     * damit Blade + Bestands-Tests ihren Aufruf behalten.
     *
     * @param  array<int, mixed>  $zutaten
     * @return array<int, array{status:string, kind:?string, ziel:?string, primaer:?string, shortlist:int}>
     */
    public function matchVorschau($team, $r, array $zutaten): array
    {
        return app(\Platform\FoodAlchemist\Services\RecipeReviseService::class)->vorschau($team, $r, $zutaten);
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

        try {
            if (! empty($werte['zutaten']) && is_array($werte['zutaten'])) {
                $zeilen = app(\Platform\FoodAlchemist\Services\RecipeReviseService::class)->syncZeilen($r, $werte['zutaten']);
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
                $frisch->update(['preparation_source' => 'ki', 'preparation_ai_confidence' => $this->ueberarbeitung['confidence']]);
                // Spec 27: die KI liefert weiter Markdown — Master sind aber die Schritte.
                // Der Parser übernimmt, `preparation` wird daraus als Spiegel neu gerendert.
                app(\Platform\FoodAlchemist\Services\RecipeStepService::class)
                    ->ausMarkdown($frisch, $werte['preparation'], ueberschreiben: true);
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

    // Spec 27: Foto-Upload/-Löschen ist in den `StepEditor` gewandert (Media-Pool +
    // M:N-Verknüpfung am Schritt). Hier gibt es deshalb keine Datei-Uploads mehr.

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
        $this->fehler = null;
        $r = $this->rezept();
        try {
            $vorschlag = $ki->propose('recipe.production_depth', [
                'name' => $this->form['name'],
                'production_depth' => $this->form['production_depth'] ?: null,
                'zutaten' => $r?->ingredients?->pluck('raw_text')->take(30)->all() ?? [],
            ]);
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        $wert = $vorschlag->werte['production_depth'] ?? null;
        if (in_array($wert, ['from_scratch', 'teilfertig', 'convenience'], true)) {
            $this->form['production_depth'] = $wert;
        }
    }

    /** ✨ Eigenschaften: Arbeitszeit/Temperatur/Funktion + Geschmack in die Form (Ist-App-Pendant). */
    public function kiEigenschaften(AiGatewayService $ki): void
    {
        $this->fehler = null;
        $r = $this->rezept();
        $zutaten = $r?->ingredients?->pluck('raw_text')->take(30)->all() ?? [];
        try {
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
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        if (in_array($geschmack->werte['taste_direction'] ?? null, ['suess', 'herzhaft', 'neutral'], true)) {
            $this->form['taste_direction'] = $geschmack->werte['taste_direction'];
        }
    }

    /** ✨ Equipment: Slug-Vorschläge → Auswahl-Pills (nichts persistiert). */
    public function kiEquipment(AiGatewayService $ki): void
    {
        $this->fehler = null;
        $r = $this->rezept();
        try {
            $vorschlag = $ki->propose('recipe.equipment', [
                'name' => $this->form['name'],
                'equipment_slugs' => [],
                'vokabular' => \Platform\FoodAlchemist\Models\FoodAlchemistVocabKochequipment::pluck('slug')->all(),
                'zutaten' => $r?->ingredients?->pluck('raw_text')->take(30)->all() ?? [],
            ]);
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();

            return;
        }
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
        $this->fehler = null;
        if ($this->recipeId !== null) {
            try {
                app(\Platform\FoodAlchemist\Services\SensorikService::class)->bewerteRezept($this->recipeId, true);
            } catch (\RuntimeException $e) {
                $this->fehler = $e->getMessage();
            }
        }
    }

    // ── ✨ Alles anreichern (D-5 §4.4 auf EIN Rezept — Bulk-Mechanik M7-06) ──

    public ?int $bulkRunId = null;

    public function allesAnreichern(): void
    {
        $this->fehler = null;
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->recipeId === null) {
            return;
        }
        try {
            $this->bulkRunId = app(\Platform\FoodAlchemist\Services\BulkEnrichService::class)
                ->starte($team, [$this->recipeId]);
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();                          // sync-Queue (demo) ohne Provider → graceful
        }
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

    // Spec 27: die Markdown-Vorschau ist entfallen — der Schritt-Editor zeigt die
    // Anleitung selbst als Karten (Nummer + Text + Foto inline).

    /** „Name putzen": §1-Syntax via KI-Gateway (GL-07: Vorschlag direkt ins Feld, nichts persistiert). */
    public function namePutzen(AiGatewayService $ki): void
    {
        if (trim($this->form['name']) === '') {
            return;
        }
        $this->fehler = null;
        try {
            $vorschlag = $ki->propose('recipe.name_putzen', ['name' => trim($this->form['name'])]);
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();

            return;
        }
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
            // Stufe 3 — Posten-Liste fürs Default-Posten-Dropdown des Auto-Planers.
            'posten' => $team !== null
                ? \Platform\FoodAlchemist\Models\FoodAlchemistProductionStation::visibleToTeam($team)
                    ->where('is_inactive', false)->orderBy('sort_order')->orderBy('name')->get(['id', 'name'])
                : collect(),
            'sensorik' => $this->recipeId !== null ? app(\Platform\FoodAlchemist\Services\SensorikService::class)->fuerRezept($this->recipeId) : null,
            'pairing' => $r !== null ? app(\Platform\FoodAlchemist\Services\PairingService::class)->panelRecipe($r) : null,
        ]);
    }
}
