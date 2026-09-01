<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Platform\Core\Models\ContextFile;
use Platform\Core\Models\Team;
use Platform\Core\Services\ImageGenerationService;
use Platform\FoodAlchemist\Jobs\EnrichRecipeJob;
use Platform\FoodAlchemist\Livewire\Planung\Index;
use Platform\FoodAlchemist\Livewire\Recipes\StepEditor;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeStep;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeStepPhoto;

/**
 * KI-Fotos für ein Rezept (Preisfrage-Feature): ein Produktfoto (Endergebnis) + je Zubereitungsschritt
 * ein Foto. Nutzt den Core-{@see ImageGenerationService} (gpt-image-1.5); jeder Call wird zur Kosten-
 * Transparenz in `foodalchemist_ai_call_log` protokolliert (Muster aus {@see StepEditor}).
 * Ausgelöst bei der Freigabe eines gestuften Drafts über {@see EnrichRecipeJob},
 * wenn der KI-Bilder-Toggle am Go gesetzt war. Jedes Bild ist einzeln fail-soft.
 */
class RecipeImageService
{
    private const SIZE = '1024x1024';

    /** Bis zum Core-Update ist `low` die gemeinsame, von Core und OpenAI akzeptierte Qualität. */
    private const QUALITY = 'low';

    private const MODEL = 'gpt-image-1.5';

    /** Feature-Keys der KI-Foto-Calls im `foodalchemist_ai_call_log` — EINE Wahrheit für Erzeuger
     *  UND Consumer (Kosten-Transparenz im Cockpit, {@see Index}). */
    public const FEATURE_PRODUKTFOTO = 'recipe.product_photo';

    public const FEATURE_SCHRITTFOTOS = 'recipe.step_photos';

    public const BILD_FEATURES = [self::FEATURE_PRODUKTFOTO, self::FEATURE_SCHRITTFOTOS];

    /**
     * Produktfoto + je Schritt ein Foto. Jedes Bild einzeln abgesichert (ein Fehler kippt den Rest
     * nicht) — aber NICHT mehr stumm: das Ergebnis wird als Bilanz zurückgegeben, damit der Aufrufer
     * ({@see EnrichRecipeJob}) einen ehrlichen Bild-Status persistieren
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

    /**
     * KI-erzeugte Fotos eines Rezepts (soft-)löschen — für das „neu erzeugen" der Kaskade (Etappe 7,
     * Teil 2b: {@see EnrichRecipeJob} im `nurBilder`-Modus), damit ein
     * Re-Trigger die alten Bilder ERSETZT statt sie anzuhäufen. Discriminator = der Kosten-Call-Log:
     * nur Fotos, die als `target_id` eines BILD_FEATURES-Calls dieses Teams auftauchen, sind KI-erzeugt
     * — MANUELLE Uploads (kein Call-Log) bleiben unangetastet. Rückgabe: Zahl der gelöschten Fotos.
     */
    public function loescheKiFotos(Team $team, FoodAlchemistRecipe $recipe): int
    {
        $kiFotoIds = DB::table('foodalchemist_ai_call_log')
            ->where('team_id', $team->id)
            ->where('target_table', 'foodalchemist_recipe_step_photos')
            ->whereIn('feature', self::BILD_FEATURES)
            ->whereNotNull('target_id')
            ->pluck('target_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($kiFotoIds === []) {
            return 0;
        }

        return FoodAlchemistRecipeStepPhoto::where('team_id', $team->id)
            ->where('recipe_id', $recipe->id)
            ->whereIn('id', $kiFotoIds)
            ->delete();
    }

    /**
     * Ein manuell hochgeladenes Foto als Rezept-Foto übernehmen — die NICHT-KI-Alternative zu
     * {@see erzeugeFuerRezept} (Etappe 7: „Foto-Wiederverwendung / manueller Upload als Alternative",
     * Teil 1). Nutzt {@see FoodAlchemistMediaService::storeImage} (frische ContextFile → kein Sharing-/
     * Lösch-Hazard mit anderen Fotos) und legt eine {@see FoodAlchemistRecipeStepPhoto} an — BEWUSST
     * OHNE Kosten-Call-Log, da kein KI-Call anfällt. Dadurch bleibt das Foto vom KI-Re-Trigger-Purge
     * ({@see loescheKiFotos}) unangetastet: dessen Discriminator ist genau der (hier fehlende)
     * BILD_FEATURES-Call-Log-Eintrag. Ein manuell übernommenes Foto überlebt also ein „neu erzeugen".
     *
     * Default = Pool-Foto (`is_result=false`). Mit `$istErgebnis=true` wird es zum HERO/Ergebnis-Bild
     * (»so soll es aussehen«) — der häufigste Fall, wenn der Nutzer die KI-Erzeugung durch ein eigenes
     * Teller-Foto ersetzt (Teil 2). Die max.-1-Invariante wird hier gewahrt: vor dem Anlegen werden alle
     * bestehenden `is_result`-Fotos des Rezepts zurückgesetzt (in einer Transaktion), sodass genau EIN
     * Ergebnis-Foto existiert — dieselbe Semantik wie {@see RecipeStepService::endproduktSetzen},
     * aber ohne Cross-Service-Kopplung. Consumer: der Cockpit-Upload-Knopf am Bild-Status ({@see Index::fotoHochladen}).
     */
    public function uebernimmManuellesFoto(Team $team, FoodAlchemistRecipe $recipe, UploadedFile $datei, ?string $caption = null, bool $istErgebnis = false): FoodAlchemistRecipeStepPhoto
    {
        $media = app(FoodAlchemistMediaService::class)->storeImage(
            $datei,
            $team,
            'foodalchemist.recipe',
            (int) $recipe->id,
            "foodalchemist/rezepte/{$recipe->id}",
        );

        $caption = $caption !== null ? trim($caption) : '';

        return DB::transaction(function () use ($team, $recipe, $media, $caption, $istErgebnis) {
            if ($istErgebnis) {
                // Max.-1-Ergebnis-Invariante (wie endproduktSetzen): das neue Foto wird der einzige Hero.
                FoodAlchemistRecipeStepPhoto::where('recipe_id', $recipe->id)
                    ->where('is_result', true)->update(['is_result' => false]);
            }

            return FoodAlchemistRecipeStepPhoto::create([
                'team_id' => $team->id,
                'recipe_id' => $recipe->id,
                'pfad' => $media['path'],
                'context_file_id' => $media['context_file_id'],
                'caption' => $caption !== '' ? $caption : 'Manuelles Foto',
                'is_result' => $istErgebnis,
            ]);
        });
    }

    /**
     * Ein VORHANDENES Team-Foto auf ein anderes Rezept übernehmen — die Foto-Wiederverwendung
     * (Etappe 7: „Foto-Wiederverwendung / manueller Upload als Alternative", Teil 3). Statt eines
     * neuen Uploads wird ein bereits existierendes {@see FoodAlchemistRecipeStepPhoto} des Teams als
     * Vorlage genommen.
     *
     * COPY-ON-REUSE (Design-Entscheid #105): das Foto wird PHYSISCH kopiert — die Quell-Bytes werden
     * gelesen und über {@see uebernimmManuellesFoto} in eine FRISCHE ContextFile am Ziel-Rezept
     * gelegt. Es gibt KEINEN geteilten `context_file_id` zwischen Quelle und Kopie → damit auch keinen
     * Waisen-/Doppel-Lösch-Hazard: ein {@see loescheKiFotos}/{@see FoodAlchemistMediaService::delete}
     * am einen Rezept lässt das andere Foto unangetastet. Bewusst gegen „Sharing mit Ref-Count/Delete-
     * Guard" gewählt — es hält die etablierte Anti-Sharing-Linie aus Teil 1 (frische ContextFile je
     * Foto) konsistent; das Speicher-Duplikat ist der akzeptierte Preis für die Lösch-Sicherheit.
     *
     * Durch die Delegation an {@see uebernimmManuellesFoto} erbt die Kopie automatisch: KEIN Kosten-
     * Call-Log (überlebt den KI-Re-Trigger-Purge) und die max.-1-Hero-Invariante bei `$istErgebnis`.
     * Der Team-Guard schützt das Primitive selbst — der Reuse-Picker (Teil 3b) zeigt ohnehin nur
     * `visibleToTeam`-Fotos.
     *
     * @throws \InvalidArgumentException wenn das Quell-Foto nicht zum Team gehört
     * @throws \RuntimeException wenn die Quell-Datei physisch fehlt (gelöscht/nie geschrieben)
     */
    public function uebernimmVorhandenesFoto(
        Team $team,
        FoodAlchemistRecipe $zielRecipe,
        FoodAlchemistRecipeStepPhoto $quelle,
        ?string $caption = null,
        bool $istErgebnis = false,
    ): FoodAlchemistRecipeStepPhoto {
        if ((int) $quelle->team_id !== (int) $team->id) {
            throw new \InvalidArgumentException('Quell-Foto gehört nicht zum Team.');
        }

        $daten = $this->leseFotoBytes($quelle);
        if ($daten === null) {
            throw new \RuntimeException('Quell-Foto hat keine lesbare Datei.');
        }

        // Quell-Bytes in eine temporäre Datei, dann als (Test-Modus-)UploadedFile an die EINE
        // Schreib-Wahrheit reichen. $test=true → copy() statt move_uploaded_file(), zulässig auch
        // außerhalb eines HTTP-Requests. Die tmp-Datei wird nach dem Store wieder entfernt.
        $tmp = tempnam(sys_get_temp_dir(), 'fa_foto_');
        file_put_contents($tmp, $daten['bytes']);
        $upload = new UploadedFile($tmp, 'reuse.'.$daten['ext'], $daten['mime'], null, true);

        try {
            return $this->uebernimmManuellesFoto(
                $team,
                $zielRecipe,
                $upload,
                $caption ?? (($quelle->caption ?? '') !== '' ? $quelle->caption : null),
                $istErgebnis,
            );
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Roh-Bytes + MIME + Datei-Endung eines vorhandenen Fotos lesen — Quelle in Reihenfolge:
     * (1) die ContextFile (`context_file_id`), (2) der Legacy-`pfad` auf dem public-Disk. Null, wenn
     * die Datei physisch fehlt. Spiegelt die Fallback-Kette aus {@see FoodAlchemistMediaService::dataUri}.
     *
     * @return array{bytes:string, mime:string, ext:string}|null
     */
    private function leseFotoBytes(FoodAlchemistRecipeStepPhoto $foto): ?array
    {
        $file = $foto->context_file_id ? ContextFile::find($foto->context_file_id) : null;
        if ($file !== null && Storage::disk($file->disk)->exists($file->path)) {
            $mime = $file->mime_type ?: (Storage::disk($file->disk)->mimeType($file->path) ?: 'image/jpeg');

            return [
                'bytes' => (string) Storage::disk($file->disk)->get($file->path),
                'mime' => $mime,
                'ext' => $this->endungFuerMime($mime),
            ];
        }

        $legacy = trim((string) $foto->pfad);
        if ($legacy !== '' && Storage::disk('public')->exists($legacy)) {
            $mime = Storage::disk('public')->mimeType($legacy) ?: 'image/jpeg';

            return [
                'bytes' => (string) Storage::disk('public')->get($legacy),
                'mime' => $mime,
                'ext' => $this->endungFuerMime($mime),
            ];
        }

        return null;
    }

    private function endungFuerMime(string $mime): string
    {
        return match (strtolower(trim($mime))) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => 'jpg',
        };
    }

    /** Ein Foto des fertig angerichteten Gerichts (Hero/Endergebnis, ohne Schritt-Kopplung → is_result). */
    public function produktFoto(Team $team, FoodAlchemistRecipe $recipe): FoodAlchemistRecipeStepPhoto
    {
        $prompt = trim('Professionelles, appetitliches Food-Foto des fertig angerichteten Gerichts «'.$recipe->name.'». '
            .mb_strimwidth((string) ($recipe->description ?? ''), 0, 280)
            .' Natürliches Licht, Restaurant-Qualität, klarer Fokus auf das Gericht, kein Text, kein Logo.');

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
        $prompt = $this->schrittPrompt($recipe, $step);

        $foto = $this->generiereFoto($team, $recipe, $prompt, 'KI-Foto: Schritt '.$step->position, (int) $step->position * 10, false, self::FEATURE_SCHRITTFOTOS, (int) $step->id);
        $step->photos()->syncWithoutDetaching([$foto->id => ['position' => 1]]);

        return true;
    }

    /** Eine zentrale Prompt-Wahrheit für Planung, Rezept-/Gerichte-Editor und MCP. */
    public function schrittPrompt(FoodAlchemistRecipe $recipe, FoodAlchemistRecipeStep $step): string
    {
        $zutaten = $recipe->ingredients->pluck('raw_text')->take(20)->filter()->implode(', ');
        $alleSchritte = FoodAlchemistRecipeStep::where('recipe_id', $recipe->id)
            ->orderBy('position')->orderBy('id')
            ->get(['position', 'phase', 'text'])
            ->map(fn (FoodAlchemistRecipeStep $s) => $s->position.'. '.trim(($s->phase ? "[{$s->phase}] " : '').$s->text))
            ->implode("\n");

        if ($recipe->is_sales_recipe) {
            return trim(<<<PROMPT
Photorealistic professional restaurant kitchen service and plating process photo.
Dish: {$recipe->name}
Prepared components and ingredients: {$zutaten}

Create one consistent visual documentation image for this exact service or plating step:
Step {$step->position} ({$step->phase}): {$step->text}

Full service sequence for continuity only:
{$alleSchritte}

Dish rules: all recipe components are already professionally prepared. Never show their production from raw ingredients. Show only the current action: mise en place for service, regeneration, final seasoning, portioning, assembly, saucing, garnishing or plating as stated. Show one coherent serving of this exact dish and the food state immediately after the current action. If the step contains alternatives such as "or", show only the first stated method; never show multiple alternatives in parallel. Do not invent extra components, garnishes, tableware or processing stages.

Style rules: realistic food photography, same neutral stainless-steel restaurant pass or catering kitchen, 45-degree angle, natural light, clean professional gastro containers and plating tools, no people, no hands, no faces, no text, no labels, no logos, no packaging, no surreal props. Do not show a finished plated dish before the plating or finishing step.
PROMPT);
        }

        return trim(<<<PROMPT
Photorealistic professional catering kitchen process photo.
Recipe: {$recipe->name}
Ingredients: {$zutaten}

Create one consistent visual documentation image for this exact preparation step:
Step {$step->position} ({$step->phase}): {$step->text}

Full step sequence for continuity only:
{$alleSchritte}

Content rules: show only the current step, as one unambiguous action, and the food state immediately after that action. Use only ingredients, tools and containers relevant to this step. Do not depict actions from earlier or later steps. If the step contains alternatives such as "or", show only the first stated method; never show multiple alternative methods in parallel. Do not invent additional ingredients, garnishes, vessels or processing stages.

Style rules: realistic food photography, same neutral stainless-steel catering kitchen, 45-degree angle, natural light, clean gastro containers and pans, no people, no hands, no faces, no text, no labels, no logos, no packaging, no surreal props. Show the food state of this step, not the final plated dish unless the step is plating or finishing.
PROMPT);
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
            [
                'size' => self::SIZE,
                'quality' => self::QUALITY,
            ],
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
