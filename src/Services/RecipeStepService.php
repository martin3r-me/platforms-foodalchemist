<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeStep;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeStepPhoto;

/**
 * Spec 27 — Step-by-Step-Zubereitung: der EINZIGE Schreibweg auf `recipe_steps`.
 *
 * Die Schritte sind Master, `recipes.preparation` ist ihr gerenderter Lese-Spiegel
 * (EINBAHN Schritte → Markdown). Das hält die 15 bestehenden `preparation`-Konsumenten
 * (Produktionsdruck, Prozessanker-Parser, Sensorik-source_hash, Embeddings,
 * DataQuality-Signal, MCP-Tools) unverändert am Laufen — kein Big-Bang.
 *
 * Der Parser ist DETERMINISTISCH (0 LLM): dieselbe Logik trägt den Bestands-Backfill,
 * den „Markdown einfügen"-Import im Editor und den Markdown-Eingang der Schreibwege
 * (Generator, MCP recipes.POST/PUT). Was nicht im Text steht, wird nicht erfunden.
 *
 * Round-Trip-Garantie: parse(render($steps)) === $steps. Darum wird angehängter
 * Fließtext mit Leerzeichen (nicht Newline) an den vorigen Schritt geklebt — ein
 * Schritt-Text ist immer einzeilig.
 */
class RecipeStepService
{
    /** Abschnitts-Überschrift: Markdown-Header (`## Mise en Place`). */
    private const RE_HEADER = '/^#{1,6}\s*(.+?)\s*$/u';

    /**
     * Abschnitts-Überschrift: komplett fett gesetzte Zeile (`**Finish**`).
     * Kein `*`/`_` im Innern — sonst würde „**A** und **B**" fälschlich als Phase gelesen.
     */
    private const RE_BOLD_ONLY = '/^(?:\*\*|__)\s*([^*_]+?)\s*(?:\*\*|__)$/u';

    /** Schritt-Marker: `1.` `1)` `- ` `* ` `• `. */
    private const RE_STEP = '/^(?:\d{1,3}[.)]|[-*•])\s+(.+?)\s*$/u';

    // ── Parser / Renderer ────────────────────────────────────────────────

    /**
     * Markdown-Zubereitung → Schritt-Zeilen.
     *
     * Regeln (Spec 27 §4):
     *  1. `## Titel` bzw. eine komplett fette Zeile → neue Phase, folgende Schritte erben sie
     *  2. Zeile mit Schritt-Marker (`1.` `1)` `- ` `* `) → neuer Schritt
     *  3. Fließtext ohne Marker → an den vorigen Schritt anhängen (konservativ);
     *     gibt es keinen vorigen Schritt → eigener Schritt
     *  4. Leerzeilen werden ignoriert
     *
     * @return list<array{phase: ?string, text: string}>
     */
    public function parse(?string $markdown): array
    {
        $text = trim((string) $markdown);
        if ($text === '') {
            return [];
        }

        $zeilen = preg_split('/\R/u', $text) ?: [];
        $phase = null;
        $schritte = [];

        foreach ($zeilen as $rohZeile) {
            $zeile = trim($rohZeile);
            if ($zeile === '') {
                continue;
            }

            // 1. Phase
            if (preg_match(self::RE_HEADER, $zeile, $m) === 1) {
                $phase = $this->saubereUeberschrift($m[1]);
                continue;
            }
            if (preg_match(self::RE_BOLD_ONLY, $zeile, $m) === 1) {
                $phase = $this->saubereUeberschrift($m[1]);
                continue;
            }

            // 2. Schritt
            if (preg_match(self::RE_STEP, $zeile, $m) === 1) {
                $schritte[] = ['phase' => $phase, 'text' => trim($m[1])];
                continue;
            }

            // 3. Fließtext → an den vorigen Schritt, sonst eigener Schritt
            $letzter = count($schritte) - 1;
            if ($letzter >= 0 && $schritte[$letzter]['phase'] === $phase) {
                $schritte[$letzter]['text'] = trim($schritte[$letzter]['text'] . ' ' . $zeile);
            } else {
                $schritte[] = ['phase' => $phase, 'text' => $zeile];
            }
        }

        return array_values(array_filter($schritte, fn (array $s) => $s['text'] !== ''));
    }

    /**
     * Schritte → Markdown-Spiegel. Phasen als `##`, Schritte fortlaufend nummeriert.
     *
     * ⚠️ Bekannte Grenze des Spiegels: Markdown kann „ab hier wieder KEINE Phase"
     * nicht ausdrücken. Fällt ein Schritt mitten in der Liste auf `phase = null`
     * zurück, erbt er beim Re-Parsen die vorige Phase. Verlustfrei ist die DB —
     * der Spiegel ist nur Lese-Ausgabe (EINBAHN), er wird nie zurückgelesen.
     *
     * @param  iterable<FoodAlchemistRecipeStep|array{phase?: ?string, text?: string}>  $steps
     */
    public function render(iterable $steps): string
    {
        $blocks = [];
        $aktuellePhase = null;
        $nr = 0;
        $erste = true;

        foreach ($steps as $step) {
            $phase = is_array($step) ? ($step['phase'] ?? null) : $step->phase;
            $text = trim((string) (is_array($step) ? ($step['text'] ?? '') : $step->text));
            if ($text === '') {
                continue;
            }
            $phase = trim((string) $phase) !== '' ? trim((string) $phase) : null;

            if ($phase !== $aktuellePhase || $erste) {
                if ($phase !== null) {
                    $blocks[] = ($erste ? '' : "\n") . '## ' . $phase;
                }
                $aktuellePhase = $phase;
                $erste = false;
            }

            $nr++;
            $blocks[] = $nr . '. ' . $text;
        }

        return implode("\n", $blocks);
    }

    // ── Schreiben ────────────────────────────────────────────────────────

    /**
     * Setzt die Schritt-Liste eines Rezepts. Zeilen MIT `id` werden an ihrer Stelle
     * aktualisiert (damit verlinkte Fotos beim Umsortieren/Umformulieren kleben
     * bleiben), fehlende Zeilen werden gelöscht. `position` = Array-Reihenfolge.
     *
     * @param  list<array{id?: int|string|null, phase?: ?string, text?: ?string}>  $rows
     */
    public function sync(FoodAlchemistRecipe $recipe, array $rows): void
    {
        DB::transaction(function () use ($recipe, $rows) {
            $behalten = [];
            $pos = 0;

            foreach ($rows as $row) {
                $text = trim((string) ($row['text'] ?? ''));
                if ($text === '') {
                    continue;   // Leerzeilen sind kein Schritt
                }
                $phaseRoh = trim((string) ($row['phase'] ?? ''));
                $phase = $phaseRoh !== '' ? mb_substr($phaseRoh, 0, 120) : null;
                $pos++;

                $id = isset($row['id']) ? (int) $row['id'] : 0;
                $step = $id > 0
                    ? FoodAlchemistRecipeStep::where('recipe_id', $recipe->id)->whereKey($id)->first()
                    : null;

                if ($step !== null) {
                    $step->update(['position' => $pos, 'phase' => $phase, 'text' => $text]);
                } else {
                    $step = FoodAlchemistRecipeStep::create([
                        'team_id' => $recipe->team_id,
                        'recipe_id' => $recipe->id,
                        'position' => $pos,
                        'phase' => $phase,
                        'text' => $text,
                    ]);
                }
                $behalten[] = $step->id;
            }

            FoodAlchemistRecipeStep::where('recipe_id', $recipe->id)
                ->whereNotIn('id', $behalten !== [] ? $behalten : [0])
                ->get()->each->delete();

            $this->spiegele($recipe);
        });
    }

    /**
     * Nummeriert die Schritte eines Rezepts lückenlos neu (1..n) und zieht den
     * Spiegel nach. Nach Anlegen/Löschen/Umsortieren einzelner Schritte aufrufen.
     */
    public function renumber(FoodAlchemistRecipe $recipe): void
    {
        $pos = 0;
        foreach (FoodAlchemistRecipeStep::where('recipe_id', $recipe->id)
            ->orderBy('position')->orderBy('id')->get() as $step) {
            $pos++;
            if ((int) $step->position !== $pos) {
                $step->update(['position' => $pos]);
            }
        }

        $this->spiegele($recipe);
    }

    /**
     * EINBAHN Schritte → `recipes.preparation`.
     *
     * Schreibt NUR bei echter Änderung: `preparation` ist ein Trigger-Feld des
     * RecipeEmbeddingObserver — ein blindes Update bei jedem Schritt-Edit würde
     * für jedes Zeichen ein Re-Embedding auslösen.
     */
    public function spiegele(FoodAlchemistRecipe $recipe): void
    {
        $steps = FoodAlchemistRecipeStep::where('recipe_id', $recipe->id)
            ->orderBy('position')->orderBy('id')->get();

        if ($steps->isEmpty()) {
            return;   // keine Schritte → bestehenden Freitext NICHT wegwerfen
        }

        $md = $this->render($steps);
        if (trim($md) === trim((string) $recipe->preparation)) {
            return;
        }

        $recipe->forceFill(['preparation' => $md])->save();
    }

    /**
     * Endprodukt-Bild setzen/aufheben („so soll es fertig aussehen").
     *
     * Genau EINES je Rezept: die Eindeutigkeit wird hier erzwungen, nicht per
     * DB-Constraint (partielles Unique WHERE is_result=1 kann MySQL nicht).
     * `$photoId = null` oder dasselbe Foto erneut = Markierung aufheben.
     */
    public function endproduktSetzen(FoodAlchemistRecipe $recipe, ?int $photoId): ?FoodAlchemistRecipeStepPhoto
    {
        return DB::transaction(function () use ($recipe, $photoId) {
            $foto = $photoId !== null
                ? FoodAlchemistRecipeStepPhoto::where('recipe_id', $recipe->id)->whereKey($photoId)->first()
                : null;

            $warSchon = $foto !== null && (bool) $foto->is_result;

            FoodAlchemistRecipeStepPhoto::where('recipe_id', $recipe->id)
                ->where('is_result', true)->update(['is_result' => false]);

            if ($foto === null || $warSchon) {
                return null;   // aufgehoben
            }

            $foto->update(['is_result' => true]);

            return $foto->refresh();
        });
    }

    /** Das Endprodukt-Bild eines Rezepts (null = keins markiert). */
    public function endprodukt(int $recipeId): ?FoodAlchemistRecipeStepPhoto
    {
        return FoodAlchemistRecipeStepPhoto::where('recipe_id', $recipeId)
            ->where('is_result', true)->first();
    }

    /**
     * Markdown-Eingang: parst Freitext in Schritte. Wird von den bestehenden
     * Schreibwegen genutzt (Generator, MCP recipes.POST/PUT, Editor-Import) —
     * damit erzeugt jeder Markdown-Write weiterhin echte Schritte.
     *
     * Überschreibt vorhandene Schritte nur mit $ueberschreiben = true.
     */
    public function ausMarkdown(FoodAlchemistRecipe $recipe, ?string $markdown, bool $ueberschreiben = false): int
    {
        $rows = $this->parse($markdown);
        if ($rows === []) {
            return 0;
        }
        if (! $ueberschreiben && FoodAlchemistRecipeStep::where('recipe_id', $recipe->id)->exists()) {
            return 0;
        }

        $this->sync($recipe, $rows);

        return count($rows);
    }

    // ── Backfill (Bestand) ───────────────────────────────────────────────

    /**
     * Parst den Bestand (`recipes.preparation`) in Schritte und hängt die
     * bestehenden Fotos über ihre alte `schritt_nr` an den Schritt gleicher
     * `position`. `schritt_nr = 0` oder keine passende Position → kein Link
     * (das Foto bleibt „allgemein" im Pool).
     *
     * Idempotent: Rezepte, die schon Schritte haben, werden übersprungen.
     *
     * @return array{scanned: int, recipes_touched: int, steps_created: int, photos_linked: int, photos_unassignable: int, skipped_no_prep: int, skipped_has_steps: int}
     */
    public function backfillBulk(?Team $team, bool $apply, ?int $limit = null, ?int $recipeId = null): array
    {
        $stats = [
            'scanned' => 0, 'recipes_touched' => 0, 'steps_created' => 0,
            'photos_linked' => 0, 'photos_unassignable' => 0,
            'skipped_no_prep' => 0, 'skipped_has_steps' => 0,
        ];

        $q = FoodAlchemistRecipe::query()->orderBy('id');
        if ($team !== null) {
            $q->where('team_id', $team->id);
        }
        if ($recipeId !== null) {
            $q->whereKey($recipeId);
        }
        if ($limit !== null) {
            $q->limit($limit);
        }

        foreach ($q->cursor() as $recipe) {
            $stats['scanned']++;

            if (trim((string) $recipe->preparation) === '') {
                $stats['skipped_no_prep']++;
                continue;
            }
            if (FoodAlchemistRecipeStep::where('recipe_id', $recipe->id)->exists()) {
                $stats['skipped_has_steps']++;
                continue;
            }

            $rows = $this->parse($recipe->preparation);
            if ($rows === []) {
                $stats['skipped_no_prep']++;
                continue;
            }

            $fotos = FoodAlchemistRecipeStepPhoto::where('recipe_id', $recipe->id)
                ->where('schritt_nr', '>=', 1)
                ->orderBy('schritt_nr')->orderBy('sort_order')->orderBy('id')
                ->get();

            $zuordenbar = $fotos->filter(fn ($f) => (int) $f->schritt_nr <= count($rows));

            $stats['recipes_touched']++;
            $stats['steps_created'] += count($rows);
            $stats['photos_linked'] += $zuordenbar->count();
            $stats['photos_unassignable'] += $fotos->count() - $zuordenbar->count();

            if (! $apply) {
                continue;
            }

            DB::transaction(function () use ($recipe, $rows, $zuordenbar) {
                $this->sync($recipe, $rows);

                $nachPosition = FoodAlchemistRecipeStep::where('recipe_id', $recipe->id)
                    ->get()->keyBy(fn ($s) => (int) $s->position);

                $lauf = [];
                foreach ($zuordenbar as $foto) {
                    $step = $nachPosition[(int) $foto->schritt_nr] ?? null;
                    if ($step === null) {
                        continue;
                    }
                    $lauf[$step->id] = ($lauf[$step->id] ?? 0) + 1;
                    $step->photos()->syncWithoutDetaching([
                        $foto->id => ['position' => $lauf[$step->id]],
                    ]);
                }
            });
        }

        return $stats;
    }

    /**
     * Ist-Abdeckung für `--verify`.
     *
     * @return array{recipes: int, recipes_with_prep: int, recipes_with_steps: int, steps_total: int, photos_total: int, photos_linked: int}
     */
    public function coverage(?Team $team): array
    {
        $recipes = FoodAlchemistRecipe::query();
        $mitPrep = FoodAlchemistRecipe::query()->whereRaw("TRIM(COALESCE(preparation, '')) <> ''");
        $mitSteps = FoodAlchemistRecipe::query()->whereHas('steps');
        $steps = FoodAlchemistRecipeStep::query();
        $fotos = FoodAlchemistRecipeStepPhoto::query();
        $verlinkt = FoodAlchemistRecipeStepPhoto::query()->whereHas('steps');

        if ($team !== null) {
            foreach ([$recipes, $mitPrep, $mitSteps, $steps, $fotos, $verlinkt] as $q) {
                $q->where('team_id', $team->id);
            }
        }

        return [
            'recipes' => $recipes->count(),
            'recipes_with_prep' => $mitPrep->count(),
            'recipes_with_steps' => $mitSteps->count(),
            'steps_total' => $steps->count(),
            'photos_total' => $fotos->count(),
            'photos_linked' => $verlinkt->count(),
        ];
    }

    // ── intern ───────────────────────────────────────────────────────────

    /** Überschrift säubern: Nummerierung, Doppelpunkt und Restauszeichnung raus. */
    private function saubereUeberschrift(string $roh): ?string
    {
        $t = trim($roh);
        $t = (string) preg_replace('/^\d{1,3}[.)]\s*/u', '', $t);       // „1. Mise en Place"
        $t = (string) preg_replace('/^(?:\*\*|__)|(?:\*\*|__)$/u', '', $t);
        $t = rtrim(trim($t), ':');

        return trim($t) !== '' ? mb_substr(trim($t), 0, 120) : null;
    }
}
