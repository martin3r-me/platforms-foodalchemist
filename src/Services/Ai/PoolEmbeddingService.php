<?php

namespace Platform\FoodAlchemist\Services\Ai;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Platform\Core\Services\EmbeddingProviderRegistry;
use Platform\Core\Services\EmbeddingService;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Throwable;

/**
 * E1 (#507): Embedding-Ausweitung vom Wissens-Korpus auf die STAMMDATEN-Pools —
 * Grundprodukte (GP) + Basis-/Verkaufsrezepte. Schwesterklasse zu
 * {@see KnowledgeEmbeddingService}: gleiche Infra (Cores {@see EmbeddingService},
 * source_hash-Idempotenz, Sentinel-Team für Global-NULL), anderer Korpus.
 *
 * ROLLE (Invariante, DoD #507): Die hier erzeugten Vektoren sind eine
 * Recall-/Shortlist-Schicht VOR der deterministischen Match-Logik
 * ({@see IngredientMatchService}) und der LLM-Disambiguierung — NIE finaler
 * Ranker, NIE Quelle für Pairing/Kontrast (der Anker-Graph bleibt die einzige
 * Pairing-Wahrheit). Diese Klasse LIEFERT nur den Index; der Hybrid-Re-Rank
 * (E2, {@see SemanticRetrievalService}) konsumiert ihn additiv.
 *
 * Was wird embeddet (die Qualitäts-Stellschraube — kompakt, 1 Vektor/Entität):
 *  - GP     : §6-Name + Hauptzutat-Oberfläche + Zustand (Warengruppen-CODE bewusst
 *             raus — Slice 1 Entrauschung #507; ein Zahlencode zieht den Vektor nur
 *             vom Klartext-Namen weg). Bewusst KEINE LA-Namen (Lieferanten-Kauderwelsch
 *             verrauscht den Index; die LA→GP-Brücke läuft über #505-Slice-2).
 *  - Rezept : Name + Kategorie-Label + die Top-Zutaten-NAMEN (die Oberfläche, die
 *             zu einer Freitext-Suche matchen soll — nicht die Mengen/Prosa).
 *             metadata.is_sales_recipe trennt Basis (D-5) von Verkauf (D-6).
 *
 * Team-Partition (identisch zu KnowledgeEmbeddingService): team_id NULL (global/
 * BHG-kuratiert, D1) → Sentinel (config global_team_id, default 0); team-eigene
 * Entitäten unter ihrer realen team_id. Cores Store verlangt team_id:int; der
 * FA-Retrieval merged die Partitionen modulseitig (Entscheid B, kein Core-Change).
 *
 * Graceful Degradation (GL-13 Invariante 6): kein Provider (Sandbox ohne Key)
 * ⇒ Backfill no-op / leere Treffer, NIE Fehler nach oben.
 */
class PoolEmbeddingService
{
    /** Polymorphe entity_types im Core-Store (die neuen Pools aus E1). */
    public const ENTITY_TYPE_GP = 'foodalchemist_gp';

    public const ENTITY_TYPE_RECIPE = 'foodalchemist_recipe';

    /** GP-Status, die in den Recall-Pool gehören (rejected/merged bleiben draußen). */
    private const GP_POOL_STATUS = ['approved', 'tentative', 'review'];

    /** Rezept-Status, die in den Recall-Pool gehören (stub..approved, wie der Sub-Pool). */
    private const RECIPE_POOL_STATUS = ['stub', 'draft', 'review', 'approved'];

    /** Max. Zutaten-Namen im Rezept-Embed-Text (die relevante Oberfläche). */
    private const RECIPE_MAX_INGREDIENTS = 8;

    // ── Provider/Config (Spiegel von KnowledgeEmbeddingService) ─────────────

    public function globalTeamId(): int
    {
        return (int) config('foodalchemist.semantic_search.global_team_id', 0);
    }

    public function providerName(): ?string
    {
        $name = config('foodalchemist.semantic_search.provider');

        return is_string($name) && $name !== '' ? $name : null;
    }

    /** Ist ein nutzbarer Embedding-Provider registriert + verfügbar? Fehler → false. */
    public function isProviderAvailable(): bool
    {
        try {
            $registry = app(EmbeddingProviderRegistry::class);
            $name = $this->providerName();
            if ($name !== null) {
                $provider = $registry->get($name);

                return $provider !== null && $provider->isAvailable();
            }

            return $registry->getDefaultProvider() !== null;
        } catch (Throwable) {
            return false;
        }
    }

    /** Partition-Team einer Entität: NULL (global) → Sentinel, sonst die reale ID. */
    public function partitionTeamId(int|string|null $teamId): int
    {
        return $teamId === null ? $this->globalTeamId() : (int) $teamId;
    }

    // ── Backfill ────────────────────────────────────────────────────────────

    /**
     * Indiziert den GP-Pool. Idempotent über Cores source_hash (unveränderter Text
     * ⇒ kein API-Call, kein DB-Write). Nach Team-Partition gruppiert.
     *
     * @param  int|null  $onlyTeamId  nur diese reale team_id (NULL = alle Partitionen)
     * @return array{available: bool, candidates: int, partitions: array<int,int>}
     */
    public function embedGps(?int $onlyTeamId = null): array
    {
        if (! $this->isProviderAvailable()) {
            return ['available' => false, 'candidates' => 0, 'partitions' => []];
        }

        $query = DB::table('foodalchemist_gps')
            ->whereIn('status', self::GP_POOL_STATUS)
            ->where('is_platzhalter', false)
            ->whereNull('merged_into_id')
            ->whereNull('deleted_at');
        if ($onlyTeamId !== null) {
            $query->where('team_id', $onlyTeamId);
        }

        $byTeam = [];
        foreach ($query->get(['id', 'name', 'main_ingredient_display', 'main_ingredient_slug', 'condition', 'commodity_group_code', 'team_id']) as $gp) {
            $text = $this->gpEmbedText($gp);
            if ($text === '') {
                continue;
            }
            $byTeam[$this->partitionTeamId($gp->team_id)][] = ['id' => (int) $gp->id, 'text' => $text];
        }

        return $this->storeByTeam(self::ENTITY_TYPE_GP, $byTeam);
    }

    /**
     * Indiziert den Rezept-Pool (Basis D-5 + Verkauf D-6; metadata.is_sales_recipe
     * trennt sie). Top-Zutaten-Namen fließen in den Embed-Text ein.
     *
     * @param  int|null  $onlyTeamId  nur diese reale team_id (NULL = alle)
     * @return array{available: bool, candidates: int, partitions: array<int,int>}
     */
    public function embedRecipes(?int $onlyTeamId = null): array
    {
        if (! $this->isProviderAvailable()) {
            return ['available' => false, 'candidates' => 0, 'partitions' => []];
        }

        $query = DB::table('foodalchemist_recipes as r')
            ->leftJoin('foodalchemist_recipe_categories as c', 'c.id', '=', 'r.category_id')
            ->whereIn('r.status', self::RECIPE_POOL_STATUS)
            ->whereNull('r.deleted_at');
        if ($onlyTeamId !== null) {
            $query->where('r.team_id', $onlyTeamId);
        }
        $recipes = $query->get(['r.id', 'r.name', 'r.is_sales_recipe', 'r.team_id', 'c.label as category_label']);

        if ($recipes->isEmpty()) {
            return ['available' => true, 'candidates' => 0, 'partitions' => []];
        }

        $ingredientsByRecipe = $this->topIngredientNames($recipes->pluck('id')->all());

        $byTeam = [];
        foreach ($recipes as $r) {
            $text = $this->recipeEmbedText($r, $ingredientsByRecipe[(int) $r->id] ?? []);
            if ($text === '') {
                continue;
            }
            $byTeam[$this->partitionTeamId($r->team_id)][] = [
                'id' => (int) $r->id,
                'text' => $text,
                'metadata' => ['is_sales_recipe' => (bool) $r->is_sales_recipe],
            ];
        }

        return $this->storeByTeam(self::ENTITY_TYPE_RECIPE, $byTeam);
    }

    // ── Inkrementell (Observer/Job) ──────────────────────────────────────────

    /**
     * Inkrementelles Re-Embed eines einzelnen GP (Observer-Pfad). Async über die
     * Queue — der Job löst Provider erst zur Laufzeit auf. No-op ohne Provider
     * oder wenn der GP nicht (mehr) in den Pool gehört → dann Vektor löschen.
     */
    public function queueGp(FoodAlchemistGp $gp): void
    {
        if (! $this->isProviderAvailable()) {
            return;
        }
        if (! $this->gpBelongsInPool($gp)) {
            $this->deleteGp((int) $gp->team_id, (int) $gp->id, $gp->team_id);

            return;
        }
        $text = $this->gpEmbedText((object) $gp->getAttributes());
        if ($text === '') {
            return;
        }
        app(EmbeddingService::class)->queueEmbedAndStore(
            teamId: $this->partitionTeamId($gp->team_id),
            entityType: self::ENTITY_TYPE_GP,
            entityId: (int) $gp->id,
            text: $text,
            providerName: $this->providerName(),
        );
    }

    /** Inkrementelles Re-Embed eines Rezepts (Observer-Pfad). */
    public function queueRecipe(FoodAlchemistRecipe $recipe): void
    {
        if (! $this->isProviderAvailable()) {
            return;
        }
        if (! in_array((string) $recipe->getRawOriginal('status'), self::RECIPE_POOL_STATUS, true)
            && ! in_array((string) $recipe->status?->value, self::RECIPE_POOL_STATUS, true)) {
            $this->deleteRecipe((int) $recipe->id, $recipe->team_id);

            return;
        }
        $names = $this->topIngredientNames([(int) $recipe->id])[(int) $recipe->id] ?? [];
        $label = DB::table('foodalchemist_recipe_categories')->where('id', $recipe->category_id)->value('label');
        $row = (object) ['name' => $recipe->name, 'category_label' => $label, 'is_sales_recipe' => $recipe->is_sales_recipe];
        $text = $this->recipeEmbedText($row, $names);
        if ($text === '') {
            return;
        }
        app(EmbeddingService::class)->queueEmbedAndStore(
            teamId: $this->partitionTeamId($recipe->team_id),
            entityType: self::ENTITY_TYPE_RECIPE,
            entityId: (int) $recipe->id,
            text: $text,
            providerName: $this->providerName(),
            metadata: ['is_sales_recipe' => (bool) $recipe->is_sales_recipe],
        );
    }

    /** Löscht den GP-Vektor (Merge/Delete/Status-Austritt). Fehler-tolerant. */
    public function deleteGp(int $realTeamId, int $id, int|string|null $rawTeamId = null): void
    {
        $this->safeDelete(self::ENTITY_TYPE_GP, $this->partitionTeamId($rawTeamId ?? $realTeamId), $id);
    }

    public function deleteRecipe(int $id, int|string|null $rawTeamId = null): void
    {
        $this->safeDelete(self::ENTITY_TYPE_RECIPE, $this->partitionTeamId($rawTeamId), $id);
    }

    // ── Embed-Text-Bau (die Qualitäts-Stellschraube) ─────────────────────────

    /** §6-Name + Hauptzutat-Oberfläche + Zustand + Warengruppe (kompakt). */
    public function gpEmbedText(object $gp): string
    {
        $name = trim((string) ($gp->name ?? ''));
        $parts = [$name];

        $haupt = trim((string) ($gp->main_ingredient_display ?? ''));
        if ($haupt === '' && ! empty($gp->main_ingredient_slug)) {
            $haupt = str_replace('_', ' ', (string) $gp->main_ingredient_slug);
        }
        if ($haupt !== '' && mb_stripos($name, $haupt) === false) {
            $parts[] = $haupt;
        }

        // Zustand nur, wenn er nicht ohnehin schon im §6-Namen steckt (sonst
        // dupliziert „…, frisch" + „frisch" den Token und verwässert den Vektor).
        $zustand = trim((string) ($gp->condition ?? ''));
        if ($zustand !== '' && mb_stripos($name, $zustand) === false) {
            $parts[] = $zustand;
        }

        // Warengruppe (Code, z.B. „13") entfällt bewusst: semantisches Rauschen,
        // das den Vektor vom Klartext-Namen wegzieht (Slice 1 Entrauschung #507).
        return self::normalizeForEmbedding(implode(' ', $parts));
    }

    /**
     * Symmetrischer Normalizer für BEIDE Enden des Vektorraums — Ziel-Embed-Text
     * (hier) UND die Suchquery ({@see SemanticRetrievalService::candidates}). Ohne
     * ihn steht die rohe Query („Aubergine") einer strukturierten Ziel-Oberfläche
     * („Aubergine · frisch · 13") gegenüber → kein Schwellwert trennt echte Treffer
     * von Anti-Markern (E5-Eichung 2026-07-19 fand kein brauchbares Floor-Fenster).
     * Wir kollabieren Struktur-Separatoren zu Leerzeichen → Query und Ziel leben im
     * selben, natürlichsprachlichen Raum. Casing bleibt (3-large ist natursprachlich
     * trainiert; Groß/Klein ist bei Zutat↔GP nie der trennende Faktor).
     */
    public static function normalizeForEmbedding(string $text): string
    {
        $text = preg_replace('/[·,;:\/|]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    /**
     * Name + Kategorie-Label + Top-Zutaten-Namen.
     *
     * @param  list<string>  $ingredientNames
     */
    public function recipeEmbedText(object $recipe, array $ingredientNames = []): string
    {
        $head = trim((string) ($recipe->name ?? ''));
        $label = trim((string) ($recipe->category_label ?? ''));
        if ($label !== '') {
            $head = $head !== '' ? $head . ' (' . $label . ')' : $label;
        }
        if ($ingredientNames !== []) {
            $head .= ': ' . implode(', ', array_slice($ingredientNames, 0, self::RECIPE_MAX_INGREDIENTS));
        }

        return self::normalizeForEmbedding($head);
    }

    // ── Interna ──────────────────────────────────────────────────────────────

    /**
     * @param  array<int, list<array{id:int,text:string,metadata?:array}>>  $byTeam
     * @return array{available: bool, candidates: int, partitions: array<int,int>}
     */
    private function storeByTeam(string $entityType, array $byTeam): array
    {
        $service = app(EmbeddingService::class);
        $providerName = $this->providerName();
        $partitions = [];
        $candidates = 0;

        foreach ($byTeam as $teamId => $entries) {
            $service->embedAndStoreBatch(
                teamId: (int) $teamId,
                entityType: $entityType,
                entries: $entries,
                providerName: $providerName,
            );
            $partitions[(int) $teamId] = count($entries);
            $candidates += count($entries);
        }

        return ['available' => true, 'candidates' => $candidates, 'partitions' => $partitions];
    }

    private function safeDelete(string $entityType, int $teamId, int $id): void
    {
        try {
            app(EmbeddingService::class)->delete($teamId, $entityType, $id);
        } catch (Throwable $e) {
            Log::warning('[PoolEmbeddingService] delete failed', [
                'entity_type' => $entityType, 'team' => $teamId, 'id' => $id, 'error' => $e->getMessage(),
            ]);
        }
    }

    /** Gehört ein GP in den Recall-Pool (Status/Platzhalter/Merge-Gate)? */
    private function gpBelongsInPool(FoodAlchemistGp $gp): bool
    {
        $status = $gp->status?->value ?? (string) $gp->getRawOriginal('status');

        return in_array($status, self::GP_POOL_STATUS, true)
            && ! (bool) $gp->is_platzhalter
            && $gp->merged_into_id === null
            && $gp->deleted_at === null;
    }

    /**
     * Top-N Zutaten-NAMEN je Rezept (position ASC), gebündelt für alle IDs in
     * EINER Query (kein N+1). Leere display_name-Zeilen werden ausgelassen.
     *
     * @param  list<int>  $recipeIds
     * @return array<int, list<string>>
     */
    private function topIngredientNames(array $recipeIds): array
    {
        if ($recipeIds === []) {
            return [];
        }
        $rows = DB::table('foodalchemist_recipe_ingredients')
            ->whereIn('recipe_id', $recipeIds)
            ->whereNull('deleted_at')
            ->orderBy('recipe_id')
            ->orderBy('position')
            ->get(['recipe_id', 'display_name']);

        $out = [];
        foreach ($rows as $row) {
            $name = trim((string) $row->display_name);
            $rid = (int) $row->recipe_id;
            if ($name === '' || count($out[$rid] ?? []) >= self::RECIPE_MAX_INGREDIENTS) {
                continue;
            }
            $out[$rid][] = $name;
        }

        return $out;
    }
}
