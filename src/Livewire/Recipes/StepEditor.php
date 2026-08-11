<?php

namespace Platform\FoodAlchemist\Livewire\Recipes;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\Core\Models\ContextFile;
use Platform\Core\Models\Team;
use Platform\Core\Services\ImageGenerationService;
use Platform\FoodAlchemist\Livewire\Settings\Concerns\ReordersLists;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeStep;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeStepPhoto;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\Ai\KnowledgeContextService;
use Platform\FoodAlchemist\Services\FoodAlchemistMediaService;
use Platform\FoodAlchemist\Services\RecipeStepService;

/**
 * Spec 27 Phase 2/3 — Schritt-für-Schritt-Editor: Nummer + Text + Foto(s) als EIN Objekt.
 *
 * Bewusst SERVER-seitig (nicht Alpine-Array wie der Zutaten-Editor): das Verknüpfen
 * eines Fotos braucht eine echte Schritt-ID. Mit unpersistierten Client-Zeilen gäbe es
 * ein „bitte erst speichern", genau die Reibung, die diese Spec beseitigen soll.
 * Schritte sind 5–15 Zeilen — die Round-Trips sind unkritisch. Reorder nutzt dieselben
 * Array-Helfer wie die Settings-Listen (ReordersLists), Persistenz macht der
 * RecipeStepService (er zieht auch den `preparation`-Spiegel nach).
 *
 * D1: sichtbar = Team-Kette aufwärts, schreibbar = nur Besitzer-Team.
 */
class StepEditor extends Component
{
    use \Livewire\WithFileUploads;
    use ReordersLists;

    public ?int $recipeId = null;

    public ?string $fehler = null;

    /** Inline-Puffer: step_id → Wert (per wire:model.blur, persistiert im updated-Hook). */
    public array $texte = [];

    public array $phasen = [];

    /** Schritt, in den der Foto-Pool gerade verlinkt (null = Pool zu). */
    public ?int $aktiverSchritt = null;

    public $fotoUpload = null;

    public string $fotoCaption = '';

    /** „Markdown einfügen" — Schnellschreiben/Paste aus Word, geparst wie der Backfill. */
    public string $markdownImport = '';

    public bool $importOffen = false;

    /** @var array{steps: list<array{phase: ?string, text: string}>, confidence: float, reasoning: ?string}|null */
    public ?array $kiVorschlag = null;

    public function mount(?int $recipeId = null): void
    {
        $this->recipeId = $recipeId;
        $this->hydrierePuffer();
    }

    // ── Schritte ─────────────────────────────────────────────────────────

    public function schrittAnlegen(): void
    {
        $r = $this->schreibbaresRezept();
        if ($r === null) {
            return;
        }

        $letzte = FoodAlchemistRecipeStep::where('recipe_id', $r->id)->orderByDesc('position')->first();
        FoodAlchemistRecipeStep::create([
            'team_id' => $r->team_id,
            'recipe_id' => $r->id,
            'position' => (int) ($letzte?->position ?? 0) + 1,
            'phase' => $letzte?->phase,                 // neue Zeile bleibt im laufenden Abschnitt
            'text' => '',
        ]);

        $this->hydrierePuffer();
    }

    public function schrittLoeschen(int $stepId): void
    {
        $r = $this->schreibbaresRezept();
        if ($r === null) {
            return;
        }

        $step = FoodAlchemistRecipeStep::where('recipe_id', $r->id)->whereKey($stepId)->first();
        if ($step === null) {
            return;
        }
        $step->photos()->detach();                      // Pivot hart lösen — das Foto bleibt im Pool
        $step->delete();

        app(RecipeStepService::class)->renumber($r);
        $this->hydrierePuffer();
    }

    public function updatedTexte(mixed $value, ?string $key = null): void
    {
        $this->feldSchreiben((int) $key, 'text', (string) $value);
    }

    public function updatedPhasen(mixed $value, ?string $key = null): void
    {
        $this->feldSchreiben((int) $key, 'phase', (string) $value);
    }

    private function feldSchreiben(int $stepId, string $feld, string $wert): void
    {
        $r = $this->schreibbaresRezept();
        if ($r === null || $stepId <= 0) {
            return;
        }
        $step = FoodAlchemistRecipeStep::where('recipe_id', $r->id)->whereKey($stepId)->first();
        if ($step === null) {
            return;
        }

        $wert = trim($wert);
        $step->update([$feld => $feld === 'phase' ? ($wert !== '' ? mb_substr($wert, 0, 120) : null) : $wert]);

        app(RecipeStepService::class)->spiegele($r);
    }

    // ── Reihenfolge (gleiche Helfer wie die Settings-Listen) ──────────────

    public function hoch(int $stepId): void
    {
        $this->reihenfolgeAendern(fn (array $ids) => $this->reorderNachbar($ids, $stepId, -1));
    }

    public function runter(int $stepId): void
    {
        $this->reihenfolgeAendern(fn (array $ids) => $this->reorderNachbar($ids, $stepId, 1));
    }

    /** Drag-and-Drop: $stepId hinter $afterId einsortieren (Signatur wie die Settings-Listen). */
    public function verschieben(int $stepId, int $afterId): void
    {
        $this->reihenfolgeAendern(fn (array $ids) => $this->reorderHinter($ids, $stepId, $afterId));
    }

    private function reihenfolgeAendern(callable $umsortieren): void
    {
        $r = $this->schreibbaresRezept();
        if ($r === null) {
            return;
        }

        $ids = FoodAlchemistRecipeStep::where('recipe_id', $r->id)
            ->orderBy('position')->orderBy('id')->pluck('id')->map(fn ($i) => (int) $i)->all();

        $neu = $umsortieren($ids);
        if ($neu === null) {
            return;
        }

        foreach ($neu as $i => $id) {
            FoodAlchemistRecipeStep::where('recipe_id', $r->id)->whereKey($id)->update(['position' => $i + 1]);
        }

        app(RecipeStepService::class)->spiegele($r);
        $this->hydrierePuffer();
    }

    // ── Fotos (M:N — ein Foto darf an mehreren Schritten hängen) ──────────

    public function poolOeffnen(int $stepId): void
    {
        $this->fehler = null;
        $this->aktiverSchritt = $this->aktiverSchritt === $stepId ? null : $stepId;
    }

    public function fotoUmschalten(int $stepId, int $photoId): void
    {
        $r = $this->schreibbaresRezept();
        if ($r === null) {
            return;
        }

        $step = FoodAlchemistRecipeStep::where('recipe_id', $r->id)->whereKey($stepId)->first();
        $foto = FoodAlchemistRecipeStepPhoto::where('recipe_id', $r->id)->whereKey($photoId)->first();
        if ($step === null || $foto === null) {
            return;
        }

        if ($step->photos()->whereKey($foto->id)->exists()) {
            $step->photos()->detach($foto->id);

            return;
        }

        $naechste = (int) ($step->photos()->max('foodalchemist_recipe_step_photo_links.position') ?? 0) + 1;
        $step->photos()->attach($foto->id, ['position' => $naechste]);
    }

    public function fotoEntkoppeln(int $stepId, int $photoId): void
    {
        $r = $this->schreibbaresRezept();
        if ($r === null) {
            return;
        }
        FoodAlchemistRecipeStep::where('recipe_id', $r->id)->whereKey($stepId)->first()
            ?->photos()->detach($photoId);
    }

    /** Upload in den Pool; ist ein Schritt aktiv, wird das Foto direkt dort verlinkt. */
    public function fotoHochladen(): void
    {
        $this->fehler = null;
        $r = $this->schreibbaresRezept();
        if ($r === null || $this->fotoUpload === null) {
            return;
        }

        $this->validate(['fotoUpload' => 'image|max:8192'], [], ['fotoUpload' => 'Foto']);

        $media = app(FoodAlchemistMediaService::class)->storeImage(
            $this->fotoUpload,
            $this->team(),
            'foodalchemist.recipe',
            $r->id,
            "foodalchemist/rezepte/{$r->id}",
        );

        $foto = FoodAlchemistRecipeStepPhoto::create([
            'team_id' => $r->team_id,
            'recipe_id' => $r->id,
            'pfad' => $media['path'],
            'context_file_id' => $media['context_file_id'],
            'caption' => trim($this->fotoCaption) ?: null,
        ]);

        // aktiverSchritt = 0 ist der schritt-freie Pool („allgemeine" Rezept-Fotos).
        if ($this->aktiverSchritt !== null && $this->aktiverSchritt > 0) {
            $this->fotoUmschalten($this->aktiverSchritt, $foto->id);
        }

        $this->reset('fotoUpload', 'fotoCaption');
    }

    /** Endprodukt-Bild markieren/aufheben („so soll es fertig aussehen", max. 1 je Rezept). */
    public function endproduktUmschalten(int $photoId): void
    {
        $r = $this->schreibbaresRezept();
        if ($r === null) {
            return;
        }

        app(RecipeStepService::class)->endproduktSetzen($r, $photoId);
    }

    /** Foto endgültig aus dem Pool entfernen (inkl. Datei + aller Verknüpfungen). */
    public function fotoLoeschen(int $photoId): void
    {
        $r = $this->schreibbaresRezept();
        if ($r === null) {
            return;
        }

        $foto = FoodAlchemistRecipeStepPhoto::where('recipe_id', $r->id)->whereKey($photoId)->first();
        if ($foto === null) {
            return;
        }
        $foto->steps()->detach();
        app(FoodAlchemistMediaService::class)->delete($foto->context_file_id, $foto->pfad, $this->team());
        $foto->delete();
    }

    public function kiFotos(): void
    {
        $this->fehler = null;
        $r = $this->schreibbaresRezept();
        if ($r === null) {
            return;
        }

        $steps = FoodAlchemistRecipeStep::where('recipe_id', $r->id)
            ->withCount('photos')
            ->orderBy('position')->orderBy('id')->get()
            ->filter(fn (FoodAlchemistRecipeStep $step) => (int) $step->photos_count === 0)
            ->values();

        if ($steps->isEmpty()) {
            $this->fehler = 'Alle Schritte haben bereits ein Foto.';

            return;
        }

        try {
            foreach ($steps as $step) {
                $this->kiFotoFuerSchritt($r, $step);
            }
        } catch (\Throwable $e) {
            $this->fehler = 'KI-Foto konnte nicht erzeugt werden: ' . mb_strimwidth($e->getMessage(), 0, 180);
        }
    }

    // ── Markdown-Import (Schnellschreiben / Paste aus Word) ──────────────

    public function markdownUebernehmen(RecipeStepService $svc): void
    {
        $this->fehler = null;
        $r = $this->schreibbaresRezept();
        if ($r === null) {
            return;
        }

        $anzahl = $svc->ausMarkdown($r, $this->markdownImport, ueberschreiben: true);
        if ($anzahl === 0) {
            $this->fehler = 'Im eingefügten Text war kein Schritt erkennbar.';

            return;
        }

        $this->reset('markdownImport', 'importOffen');
        $this->hydrierePuffer();
    }

    // ── KI (GL-07: Vorschlag, nichts auto-persistieren) ──────────────────

    public function kiSchritte(AiGatewayService $ki): void
    {
        $this->fehler = null;
        $r = $this->rezept();
        if ($r === null) {
            return;
        }

        try {
            $wissen = $this->stepWissen($r);
            $vorschlag = $ki->propose('recipe.steps', [
                'name' => $r->name,
                'zutaten' => $r->ingredients->pluck('raw_text')->take(30)->all(),
                'schritte_bestand' => FoodAlchemistRecipeStep::where('recipe_id', $r->id)
                    ->orderBy('position')->pluck('text')->all(),
            ], [
                'knowledge' => $wissen['block'],
                'knowledge_used' => $wissen['files_used'],
                'target_table' => 'foodalchemist_recipe_steps',
                'target_id' => $r->id,
                // Ein valides JSON ohne Schritte ist strukturell unbrauchbar → Gateway re-rollt.
                'structural_retry' => fn (array $p) => is_array($p['werte']['steps'] ?? null) && $p['werte']['steps'] !== [],
            ]);
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();

            return;
        }

        $steps = [];
        foreach ($vorschlag->werte['steps'] ?? [] as $s) {
            $text = trim((string) ($s['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $phase = trim((string) ($s['phase'] ?? ''));
            $steps[] = ['phase' => $phase !== '' ? $phase : null, 'text' => $text];
        }

        if ($steps === []) {
            $this->fehler = 'KI-Vorschlag enthält keine Schritte.';

            return;
        }

        $this->kiVorschlag = [
            'steps' => $steps,
            'confidence' => max(0.0, min(1.0, $vorschlag->confidence)),
            'reasoning' => $vorschlag->reasoning,
        ];
    }

    public function kiUebernehmen(RecipeStepService $svc): void
    {
        $this->fehler = null;
        $r = $this->schreibbaresRezept();
        if ($r === null || $this->kiVorschlag === null) {
            return;
        }

        if ($r->preparation_source === 'manual') {                    // GL-07 Override-First
            $this->fehler = 'Zubereitung ist manuell gepflegt — erst Reset, dann KI übernehmen.';

            return;
        }

        $svc->sync($r, $this->kiVorschlag['steps']);
        $r->update(['preparation_source' => 'ki', 'preparation_ai_confidence' => $this->kiVorschlag['confidence']]);

        $this->kiVorschlag = null;
        $this->hydrierePuffer();
    }

    public function kiVerwerfen(): void
    {
        $this->kiVorschlag = null;
    }

    // ── intern ───────────────────────────────────────────────────────────

    /** @return array{block: string, files_used: list<string>} */
    private function stepWissen(FoodAlchemistRecipe $recipe): array
    {
        $zutaten = $recipe->ingredients->pluck('raw_text')->take(30)->filter()->implode(', ');
        $beschreibung = trim((string) $recipe->name . "\n" . $zutaten);
        $wissen = app(KnowledgeContextService::class)->contextFor('recipe.steps', $beschreibung, null, [], [
            'rezept_typ' => 'basisrezept',
        ]);

        return [
            'block' => (string) ($wissen['block'] ?? ''),
            'files_used' => $wissen['files_used'] ?? [],
        ];
    }

    private function kiFotoFuerSchritt(FoodAlchemistRecipe $recipe, FoodAlchemistRecipeStep $step): void
    {
        $result = app(ImageGenerationService::class)->generateAndStore(
            $this->kiFotoPrompt($recipe, $step),
            'foodalchemist.recipe',
            $recipe->id,
            (int) Auth::id(),
            (int) $recipe->team_id,
            ['size' => '1024x1024', 'quality' => 'low'],
        );
        $contextFile = ContextFile::findOrFail((int) $result['id']);

        $foto = FoodAlchemistRecipeStepPhoto::create([
            'team_id' => $recipe->team_id,
            'recipe_id' => $recipe->id,
            'pfad' => (string) $contextFile->path,
            'context_file_id' => (int) $contextFile->id,
            'caption' => 'KI-Foto: Schritt ' . $step->position,
            'sort_order' => (int) $step->position * 10,
        ]);

        $step->photos()->attach($foto->id, ['position' => 1]);
    }

    private function kiFotoPrompt(FoodAlchemistRecipe $recipe, FoodAlchemistRecipeStep $step): string
    {
        $zutaten = $recipe->ingredients->pluck('raw_text')->take(20)->filter()->implode(', ');
        $alleSchritte = FoodAlchemistRecipeStep::where('recipe_id', $recipe->id)
            ->orderBy('position')->orderBy('id')
            ->get(['position', 'phase', 'text'])
            ->map(fn (FoodAlchemistRecipeStep $s) => $s->position . '. ' . trim(($s->phase ? "[{$s->phase}] " : '') . $s->text))
            ->implode("\n");

        return trim(<<<PROMPT
Photorealistic professional catering kitchen process photo.
Recipe: {$recipe->name}
Ingredients: {$zutaten}

Create one consistent visual documentation image for this exact preparation step:
Step {$step->position} ({$step->phase}): {$step->text}

Full step sequence for continuity:
{$alleSchritte}

Style rules: realistic food photography, same neutral stainless-steel catering kitchen, 45-degree angle, natural light, clean gastro containers and pans, no people, no hands, no faces, no text, no labels, no logos, no packaging, no surreal props. Show the food state of this step, not the final plated dish unless the step is plating or finishing.
PROMPT);
    }

    private function team(): Team
    {
        return Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
    }

    private function rezept(): ?FoodAlchemistRecipe
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->recipeId === null) {
            return null;
        }

        return FoodAlchemistRecipe::visibleToTeam($team)->with('ingredients')->find($this->recipeId);
    }

    /** Wie RecipeModal::fotoHochladen: geerbte Rezepte sind lesbar, aber nicht schreibbar (D1). */
    private function schreibbaresRezept(): ?FoodAlchemistRecipe
    {
        $team = Auth::user()?->currentTeamRelation;
        $r = $this->rezept();
        if ($r === null || $team === null) {
            return null;
        }
        if ((int) $r->team_id !== (int) $team->id) {
            $this->fehler = 'Geerbtes Rezept — Zubereitung nur durchs Besitzer-Team (D1).';

            return null;
        }

        return $r;
    }

    private function hydrierePuffer(): void
    {
        $this->texte = [];
        $this->phasen = [];
        if ($this->recipeId === null) {
            return;
        }

        foreach (FoodAlchemistRecipeStep::where('recipe_id', $this->recipeId)
            ->orderBy('position')->orderBy('id')->get() as $s) {
            $this->texte[$s->id] = (string) $s->text;
            $this->phasen[$s->id] = (string) ($s->phase ?? '');
        }
    }

    public function render()
    {
        $r = $this->rezept();

        $schritte = $r !== null
            ? FoodAlchemistRecipeStep::where('recipe_id', $r->id)
                ->with('photos')->orderBy('position')->orderBy('id')->get()
            : collect();

        $pool = $r !== null
            ? FoodAlchemistRecipeStepPhoto::where('recipe_id', $r->id)
                ->orderBy('sort_order')->orderBy('id')->get()
            : collect();

        // Freie Fotos = im Pool, aber an keinem Schritt UND nicht das Endprodukt-Bild
        // (das hat seinen eigenen Platz — es soll nicht als „hängt nirgends" gemeldet werden).
        $verlinkteIds = $schritte->flatMap(fn ($s) => $s->photos->pluck('id'))
            ->merge($pool->where('is_result', true)->pluck('id'))->unique()->all();

        return view('foodalchemist::livewire.recipes.step-editor', [
            'rezept' => $r,
            'schritte' => $schritte,
            'pool' => $pool,
            'freieFotoIds' => array_values(array_diff($pool->pluck('id')->all(), $verlinkteIds)),
            'endprodukt' => $pool->firstWhere('is_result', true),   // „so soll es fertig aussehen"
            'phasenVorschlaege' => $schritte->pluck('phase')->filter()->unique()->values()->all(),
            'schreibbar' => $r !== null && (int) $r->team_id === (int) (Auth::user()?->currentTeamRelation?->id ?? 0),
        ]);
    }
}
