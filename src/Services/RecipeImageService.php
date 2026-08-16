<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Platform\Core\Models\ContextFile;
use Platform\Core\Models\Team;
use Platform\Core\Services\ImageGenerationService;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeStep;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeStepPhoto;

/**
 * KI-Fotos für ein Rezept (Preisfrage-Feature): ein Produktfoto (Endergebnis) + je Zubereitungsschritt
 * ein Foto. Nutzt den Core-{@see ImageGenerationService} (gpt-image-1.5); jeder Call wird zur Kosten-
 * Transparenz in `foodalchemist_ai_call_log` protokolliert (Muster aus {@see \Platform\FoodAlchemist\Livewire\Recipes\StepEditor}).
 * Ausgelöst bei der Freigabe eines gestuften Drafts über {@see \Platform\FoodAlchemist\Jobs\EnrichRecipeJob},
 * wenn der KI-Bilder-Toggle am Go gesetzt war. Jedes Bild ist einzeln fail-soft.
 */
class RecipeImageService
{
    private const SIZE = '1024x1024';
    private const QUALITY = 'low';   // Kosten: „low" reicht für Vorschau-/Doku-Bilder
    private const MODEL = 'gpt-image-1.5';

    /** Feature-Keys der KI-Foto-Calls im `foodalchemist_ai_call_log` — EINE Wahrheit für Erzeuger
     *  UND Consumer (Kosten-Transparenz im Cockpit, {@see \Platform\FoodAlchemist\Livewire\Planung\Index}). */
    public const FEATURE_PRODUKTFOTO = 'recipe.product_photo';
    public const FEATURE_SCHRITTFOTOS = 'recipe.step_photos';
    public const BILD_FEATURES = [self::FEATURE_PRODUKTFOTO, self::FEATURE_SCHRITTFOTOS];

    /**
     * Produktfoto + je Schritt ein Foto. Jedes Bild einzeln abgesichert (ein Fehler kippt den Rest
     * nicht) — aber NICHT mehr stumm: das Ergebnis wird als Bilanz zurückgegeben, damit der Aufrufer
     * ({@see \Platform\FoodAlchemist\Jobs\EnrichRecipeJob}) einen ehrlichen Bild-Status persistieren
     * kann (`deferred.bilder`, Cockpit-Badge). Rückgabe: `erzeugt` = angelegte Fotos, `fehler` =
     * fehlgeschlagene Calls, `letzter_fehler` = Message des jüngsten Fehlers (oder null).
     *
     * @return array{erzeugt:int, fehler:int, letzter_fehler:?string}
     */
    public function erzeugeFuerRezept(Team $team, FoodAlchemistRecipe $recipe, bool $produktFoto = true, bool $schrittFotos = true): array
    {
        $erzeugt = 0;
        $fehler = 0;
        $letzterFehler = null;

        if ($produktFoto) {
            try {
                $this->produktFoto($team, $recipe);
                $erzeugt++;
            } catch (\Throwable $e) {
                // ein fehlgeschlagenes Bild darf die übrigen nicht verhindern — aber es wird gezählt
                $fehler++;
                $letzterFehler = $e->getMessage();
            }
        }
        if ($schrittFotos) {
            foreach (FoodAlchemistRecipeStep::where('recipe_id', $recipe->id)->orderBy('position')->get() as $step) {
                try {
                    if ($this->schrittFoto($team, $recipe, $step)) {
                        $erzeugt++;
                    }
                    // schrittFoto liefert false bei leerem Schritt-Text (übersprungen, kein Fehler).
                } catch (\Throwable $e) {
                    // dito — nächster Schritt
                    $fehler++;
                    $letzterFehler = $e->getMessage();
                }
            }
        }

        return ['erzeugt' => $erzeugt, 'fehler' => $fehler, 'letzter_fehler' => $letzterFehler];
    }

    /** Ein Foto des fertig angerichteten Gerichts (Hero/Endergebnis, ohne Schritt-Kopplung → is_result). */
    public function produktFoto(Team $team, FoodAlchemistRecipe $recipe): FoodAlchemistRecipeStepPhoto
    {
        $prompt = trim('Professionelles, appetitliches Food-Foto des fertig angerichteten Gerichts «' . $recipe->name . '». '
            . mb_strimwidth((string) ($recipe->description ?? ''), 0, 280)
            . ' Natürliches Licht, Restaurant-Qualität, klarer Fokus auf das Gericht, kein Text, kein Logo.');

        return $this->generiereFoto($team, $recipe, $prompt, 'KI-Produktfoto', 0, true, self::FEATURE_PRODUKTFOTO, null);
    }

    /** Ein Foto zu einem Zubereitungsschritt (an den Schritt gehängt). Rückgabe: true = Foto erzeugt,
     *  false = übersprungen (leerer Schritt-Text — kein Fehler, kein erzeugtes Bild). */
    public function schrittFoto(Team $team, FoodAlchemistRecipe $recipe, FoodAlchemistRecipeStep $step): bool
    {
        $text = trim((string) $step->text);
        if ($text === '') {
            return false;
        }
        $prompt = 'Food-Foto zum Zubereitungsschritt ' . $step->position . ' von «' . $recipe->name . '»: '
            . mb_strimwidth($text, 0, 280) . ' Realistischer Küchen-Kontext, klarer Fokus, kein Text.';

        $foto = $this->generiereFoto($team, $recipe, $prompt, 'KI-Foto: Schritt ' . $step->position, (int) $step->position * 10, false, self::FEATURE_SCHRITTFOTOS, (int) $step->id);
        $step->photos()->syncWithoutDetaching([$foto->id => ['position' => 1]]);

        return true;
    }

    private function generiereFoto(Team $team, FoodAlchemistRecipe $recipe, string $prompt, string $caption, int $sort, bool $isResult, string $feature, ?int $stepId): FoodAlchemistRecipeStepPhoto
    {
        $started = microtime(true);
        $result = app(ImageGenerationService::class)->generateAndStore(
            $prompt,
            'foodalchemist.recipe',
            (int) $recipe->id,
            (int) (Auth::id() ?? 0),
            (int) $team->id,
            ['size' => self::SIZE, 'quality' => self::QUALITY],
        );
        $contextFile = ContextFile::findOrFail((int) $result['id']);

        $foto = FoodAlchemistRecipeStepPhoto::create([
            'team_id' => $team->id,
            'recipe_id' => $recipe->id,
            'pfad' => (string) $contextFile->path,
            'context_file_id' => (int) $contextFile->id,
            'caption' => $caption,
            'sort_order' => $sort,
            'is_result' => $isResult,
        ]);

        $this->logCall($team, $recipe, $prompt, $feature, $started, $stepId, (int) $foto->id);

        return $foto;
    }

    /** Kosten-/Nutzungs-Log (fail-soft — Logging-Fehler darf die Bild-Erzeugung nie kippen). */
    private function logCall(Team $team, FoodAlchemistRecipe $recipe, string $prompt, string $feature, float $started, ?int $stepId, int $photoId): void
    {
        try {
            DB::table('foodalchemist_ai_call_log')->insert([
                'uuid' => (string) Str::orderedUuid(),
                'team_id' => $team->id,
                'user_id' => Auth::id(),
                'feature' => $feature,
                'tier' => 'I',
                'model' => self::MODEL,
                'prompt_hash' => hash('sha256', $prompt),
                'response_summary' => $feature,
                'tokens_in' => 0,
                'tokens_out' => 0,
                'target_table' => 'foodalchemist_recipe_step_photos',
                'target_id' => $photoId,
                'error' => null,
                'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable) {
            // Log ist Beiwerk — nie blockierend.
        }
    }
}
