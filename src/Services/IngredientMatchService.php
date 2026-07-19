<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\MatchBand;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\Ai\PoolEmbeddingService;
use Platform\FoodAlchemist\Services\Ai\SemanticRetrievalService;
use Platform\FoodAlchemist\Services\Matching\MatchHeuristics;
use Platform\FoodAlchemist\Services\Matching\TokenEngine;

/**
 * M4-09: GL-04 Voll-Port — Zutat-String → GP | Sub-Rezept | none.
 * Pipeline (match_ingredient_pref_bio, rs:1027): §4-Sub-Alias → §5-GP-Alias →
 * Pool-Priorität (Halbfabrikat-Gate 4.4k + Exact-Sub-Override 4.4l) →
 * Pool-Scans mit Containment-Floor (4.4o) + Varianten-/Bio-Tiebreaker (4.4m/r/u)
 * + Sub-Typ-Boost (4.4b) → Schwellen (§4.1).
 *
 * Determinismus (Inv. 7/W-4): Pool-Scans iterieren ORDER BY id ASC.
 * Modus: gp_first (Default) | sub_recipe_first (from_scratch UND teil_convenience — A-1).
 */
class IngredientMatchService
{
    public function __construct(
        private TokenEngine $engine,
        private MatchHeuristics $heuristik,
        // E2 (#507): nullable + container-resolved. Ohne Provider/Flag inert →
        // candidatesFor bleibt byte-identisch zum Legacy-Verhalten (84 Goldens grün).
        private ?SemanticRetrievalService $semantic = null,
        // #507 Weg-2: deterministische Alias-/Anti-Marker-Schicht (S1/S2).
        private ?TerminologyService $terminology = null,
    ) {
        $this->semantic ??= app(SemanticRetrievalService::class);
        $this->terminology ??= app(TerminologyService::class);
    }

    /**
     * @return array{target: string, status: MatchBand, gp_id: ?int, gp_name: ?string,
     *               recipe_id: ?int, recipe_name: ?string, score: float}
     */
    public function matchIngredient(
        Team $team,
        string $ingredientName,
        ?string $hauptzutatSlug = null,
        string $mode = 'gp_first',
        string $pref = 'neutral',
        bool $preferRaw = false,
        string $bio = 'neutral',
    ): array {
        $queryTokens = $this->engine->tokenize($ingredientName);
        $querySlug = $hauptzutatSlug !== null && trim($hauptzutatSlug) !== '' ? $hauptzutatSlug : null;

        if ($queryTokens === [] && $querySlug === null) {
            return $this->noMatch(0.0);
        }

        // 4.4n — §4-Default-Sub-Alias hat VORRANG (deterministisch, Existenz-Guard)
        if (($aliasName = $this->heuristik->defaultSubAlias($queryTokens)) !== null) {
            $ziel = $this->resolveSubByName($team, $aliasName);
            if ($ziel !== null) {
                return [
                    'target' => 'sub_recipe', 'status' => MatchBand::fuerScore(MatchHeuristics::SUB_ALIAS_SCORE),
                    'gp_id' => null, 'gp_name' => null,
                    'recipe_id' => $ziel['id'], 'recipe_name' => $ziel['name'],
                    'score' => MatchHeuristics::SUB_ALIAS_SCORE,
                ];
            }
        }

        // 4.4s — §5-Default-GP-Alias (sichere Degradation)
        if (($aliasName = $this->heuristik->defaultGpAlias($queryTokens, $preferRaw)) !== null) {
            $ziel = $this->resolveGpByName($team, $aliasName);
            if ($ziel !== null) {
                return [
                    'target' => 'gp', 'status' => MatchBand::fuerScore(MatchHeuristics::DEFAULT_GP_ALIAS_SCORE),
                    'gp_id' => $ziel['id'], 'gp_name' => $ziel['name'],
                    'recipe_id' => null, 'recipe_name' => null,
                    'score' => MatchHeuristics::DEFAULT_GP_ALIAS_SCORE,
                ];
            }
        }

        $final = $this->poolLauf($team, $queryTokens, $querySlug, $mode, $pref, $preferRaw, $bio);

        // M6-07 / V-05 (Audit-Hebel 4): Decompounding-FALLBACK — läuft NUR, wenn
        // der v1-Lauf unter der Schwelle bleibt (additiv; GL-04-Goldens unberührt).
        // »Kürbispüree« → kuerbis+pueree, validiert gegen das Pool-Token-Vokabular.
        if ($final['score'] < MatchHeuristics::MIN_MATCH_SCORE
            && config('foodalchemist.matching.decompound', true)) {
            $erweitert = $this->decompoundTokens($team, $queryTokens);
            if ($erweitert !== null) {
                $zweiter = $this->poolLauf($team, $erweitert, $querySlug, $mode, $pref, $preferRaw, $bio);
                if ($zweiter['score'] > $final['score']) {
                    $final = $zweiter;
                }
            }
        }

        return $final['score'] < MatchHeuristics::MIN_MATCH_SCORE
            ? $this->noMatch($final['score'])
            : $final;
    }

    /** 4.4k/l — Pool-Priorität (Halbfabrikat-Gate × Modus, §4.2) — v1-Kernlauf. */
    private function poolLauf(Team $team, array $queryTokens, ?string $querySlug, string $mode, string $pref, bool $preferRaw, string $bio): array
    {
        if ($this->heuristik->queryIstHalbfabrikat($queryTokens)) {
            $subBest = $this->bestSubrecipeMatch($team, $queryTokens, $querySlug);
            $gpBest = $this->bestGpMatch($team, $queryTokens, $querySlug, $pref, $preferRaw, $bio);
            $subScore = $subBest['score'] ?? 0.0;
            $preferSub = $mode === 'sub_recipe_first'
                ? $subScore >= MatchHeuristics::SUB_PRIORITY_THRESHOLD
                : $subScore >= MatchHeuristics::SUB_EXACT_OVERRIDE;

            return $preferSub
                ? ($subBest ?? $gpBest ?? $this->noMatch(0.0))
                : $this->besserer($gpBest, $subBest);
        }
        $gpBest = $this->bestGpMatch($team, $queryTokens, $querySlug, $pref, $preferRaw, $bio);
        $subBest = ($gpBest['score'] ?? 0.0) < MatchHeuristics::GP_PRIORITY_THRESHOLD
            ? $this->bestSubrecipeMatch($team, $queryTokens, $querySlug)
            : null;

        return $this->besserer($gpBest, $subBest);
    }

    /**
     * V-05: 2-Split je Kompositum-Token (≥8 Z.) gegen das Pool-Token-Vokabular
     * (Basisrezept- + GP-Namen, je Team gecacht); Fugen-s/-n toleriert.
     * null = nichts zerlegbar (kein zweiter Lauf).
     *
     * @param  list<string>  $queryTokens
     * @return ?list<string>
     */
    private function decompoundTokens(Team $team, array $queryTokens): ?array
    {
        $vokabular = $this->poolVokabular($team);
        $erweitert = [];
        $gefunden = false;
        foreach ($queryTokens as $tok) {
            $erweitert[] = $tok;
            if (mb_strlen($tok) < 8) {
                continue;
            }
            for ($i = 4; $i <= mb_strlen($tok) - 4; $i++) {
                $kopf = mb_substr($tok, 0, $i);
                $rest = mb_substr($tok, $i);
                // Fugen-Element am Kopf-Ende tolerieren (rinds|braten → rind)
                $kopfBasis = isset($vokabular[$kopf]) ? $kopf
                    : (in_array(mb_substr($kopf, -1), ['s', 'n'], true) && isset($vokabular[mb_substr($kopf, 0, -1)])
                        ? mb_substr($kopf, 0, -1) : null);
                if ($kopfBasis !== null && isset($vokabular[$rest])) {
                    $erweitert[] = $kopfBasis;
                    $erweitert[] = $rest;
                    $gefunden = true;
                    break;
                }
            }
        }

        return $gefunden ? array_values(array_unique($erweitert)) : null;
    }

    /** @var array<int, array<string, true>> Pool-Token-Vokabular je Team (Request-Cache) */
    private array $vokabularCache = [];

    private function poolVokabular(Team $team): array
    {
        if (isset($this->vokabularCache[$team->id])) {
            return $this->vokabularCache[$team->id];
        }
        $vokabular = [];
        foreach (FoodAlchemistRecipe::visibleToTeam($team)->basis()->pluck('name') as $name) {
            foreach ($this->engine->tokenize($name) as $t) {
                if (mb_strlen($t) >= 4) {
                    $vokabular[$t] = true;
                }
            }
        }
        foreach (FoodAlchemistGp::visibleToTeam($team)->where('is_platzhalter', false)->pluck('name') as $name) {
            foreach ($this->engine->tokenize($name) as $t) {
                if (mb_strlen($t) >= 4) {
                    $vokabular[$t] = true;
                }
            }
        }

        return $this->vokabularCache[$team->id] = $vokabular;
    }

    /**
     * 4.4p — Top-K-Shortlist aus BEIDEN Pools für die grounded LLM-Disambiguierung
     * (Rang = max(strikt, Substring-Overlap); Referenz-Token gp:<id> / sub:<id>).
     *
     * @return array<int, array{kind: string, id: int, name: string, score: float, reference: string}>
     */
    public function candidatesFor(Team $team, string $ingredientName, ?string $hauptzutatSlug = null, int $k = 5): array
    {
        $queryTokens = $this->engine->tokenize($ingredientName);
        $querySlug = $hauptzutatSlug !== null && trim($hauptzutatSlug) !== '' ? $hauptzutatSlug : null;
        if ($queryTokens === [] && $querySlug === null) {
            return [];
        }

        // S1 (#507 Weg-2): Alias-Phrasen (Dialekt/Übersetzung, „Paradeiser"→„tomate").
        // Union der Tokens erweitert NUR den Prefilter (fetcht den Alias-Kandidaten);
        // der SCORE kommt aus max(Original, jede Alias-Variante einzeln) — so bekommt
        // der Alias-Treffer VOLLEN Score statt im Token-Bag zu verwässern. Feuert nur
        // bei bekanntem Alias; Standardnamen bleiben unberührt.
        $aliasVariants = array_values(array_filter(
            array_map(fn ($p) => $this->engine->tokenize($p), $this->terminology->aliasPhrasesFor($ingredientName)),
            static fn ($v) => $v !== [],
        ));
        $poolTokens = $queryTokens;
        foreach ($aliasVariants as $v) {
            $poolTokens = array_merge($poolTokens, $v);
        }
        $poolTokens = array_values(array_unique($poolTokens));

        // Lexikalischer Pool → keyed map "kind\0id".
        $lex = [];
        foreach ($this->gpPool($team, $poolTokens, $querySlug) as $gp) {
            $combined = trim($gp->name . ' ' . ($gp->main_ingredient_display ?? ''));
            $score = $this->bestLexScore($queryTokens, $aliasVariants, $querySlug, $combined, $gp->main_ingredient_slug, $gp->name);
            if ($score > 0.0) {
                $lex["gp\0{$gp->id}"] = ['kind' => 'gp', 'id' => (int) $gp->id, 'name' => $gp->name, 'score' => $score, 'reference' => "gp:{$gp->id}"];
            }
        }
        foreach ($this->subPool($team, $poolTokens, $querySlug) as $sub) {
            $score = $this->bestLexScore($queryTokens, $aliasVariants, $querySlug, $sub->name, null, $sub->name);
            if ($score > 0.0) {
                $lex["sub\0{$sub->id}"] = ['kind' => 'sub', 'id' => (int) $sub->id, 'name' => $sub->name, 'score' => $score, 'reference' => "sub:{$sub->id}"];
            }
        }

        // Legacy-Pfad: Semantik aus ⇒ exakt wie bisher (nur Herkunfts-Marker + S2).
        if ($this->semantic === null || ! $this->semantic->enabled()) {
            $out = array_map(static function ($c) {
                $c['origin'] = 'lexical';

                return $c;
            }, array_values($lex));
            $out = $this->stripAntiMarkers($ingredientName, $out);
            usort($out, fn ($a, $b) => $b['score'] <=> $a['score']);

            return array_slice($out, 0, $k);
        }

        return $this->hybridMerge($team, $ingredientName, $lex, $k);
    }

    /**
     * E2 (#507): additiver Hybrid-Re-Rank (Plan §4 / GL-04 §6.1 V-04). Score:
     *  both → max(lexikalisch, cosine) · nur semantisch → cosine ·
     *  nur lexikalisch (kein Cosine für diese Query) → lexikalisch × 0.5.
     * 'origin' trägt die Herkunft (lexical|semantic|both) für Audit/UI. Bleibt
     * eine SHORTLIST — die Match-Entscheidung (matchIngredient) ist unberührt.
     *
     * @param  array<string, array{kind:string,id:int,name:string,score:float,reference:string}>  $lex
     * @return array<int, array{kind: string, id: int, name: string, score: float, reference: string, origin: string}>
     */
    private function hybridMerge(Team $team, string $ingredientName, array $lex, int $k): array
    {
        $cap = max($k * 3, (int) config('foodalchemist.semantic_search.pool_cap', 15));
        $hits = $this->semantic->candidates(
            $team,
            $ingredientName,
            [PoolEmbeddingService::ENTITY_TYPE_GP, PoolEmbeddingService::ENTITY_TYPE_RECIPE],
            $cap,
        );

        $cos = [];              // "kind\0id" => cosine
        $gpIds = $subIds = [];
        foreach ($hits as $h) {
            if ($h['entity_type'] === PoolEmbeddingService::ENTITY_TYPE_GP) {
                $cos["gp\0" . (int) $h['entity_id']] = (float) $h['score'];
                $gpIds[] = (int) $h['entity_id'];
            } elseif ($h['entity_type'] === PoolEmbeddingService::ENTITY_TYPE_RECIPE) {
                $cos["sub\0" . (int) $h['entity_id']] = (float) $h['score'];
                $subIds[] = (int) $h['entity_id'];
            }
        }

        // Namen + Eligibilität der SEMANTIK-ONLY-Kandidaten (gleiche Filter wie die
        // Lexik-Pools — nichts Unsichtbares/Rejected/VK darf durch die Semantik rein).
        $names = [];
        $gpMissing = array_values(array_filter($gpIds, static fn ($id) => ! isset($lex["gp\0$id"])));
        if ($gpMissing !== []) {
            foreach (FoodAlchemistGp::visibleToTeam($team)->whereIn('status', ['approved', 'tentative'])
                ->where('is_platzhalter', false)->whereIn('id', $gpMissing)->get(['id', 'name']) as $g) {
                $names["gp\0{$g->id}"] = $g->name;
            }
        }
        $subMissing = array_values(array_filter($subIds, static fn ($id) => ! isset($lex["sub\0$id"])));
        if ($subMissing !== []) {
            foreach (FoodAlchemistRecipe::visibleToTeam($team)->basis()->whereIn('status', ['stub', 'draft', 'review', 'approved'])
                ->whereIn('id', $subMissing)->get(['id', 'name']) as $r) {
                $names["sub\0{$r->id}"] = $r->name;
            }
        }

        $merged = [];
        foreach ($lex as $key => $c) {
            if (isset($cos[$key])) {
                $c['score'] = max((float) $c['score'], $cos[$key]);
                $c['origin'] = 'both';
            } else {
                $c['score'] = (float) $c['score'] * 0.5;
                $c['origin'] = 'lexical';
            }
            $merged[$key] = $c;
        }
        foreach ($cos as $key => $score) {
            if (isset($merged[$key]) || ! isset($names[$key])) {
                continue;
            }
            [$kind, $id] = explode("\0", $key, 2);
            $merged[$key] = [
                'kind' => $kind, 'id' => (int) $id, 'name' => $names[$key],
                'score' => $score, 'reference' => "{$kind}:{$id}", 'origin' => 'semantic',
            ];
        }

        $out = $this->stripAntiMarkers($ingredientName, array_values($merged));
        usort($out, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($out, 0, $k);
    }

    /**
     * S1 (#507 Weg-2): bester Lexik-Score über die Original-Query UND jede Alias-
     * Variante einzeln. So zählt „Karotte" (Alias von „Möhre") mit VOLLEM Score,
     * statt im gemeinsamen Token-Bag verwässert aus den Top-K zu fallen. Alias-
     * Varianten tragen keinen Slug (nur die Original-Query).
     *
     * @param  list<string>  $queryTokens
     * @param  list<list<string>>  $aliasVariants
     */
    private function bestLexScore(array $queryTokens, array $aliasVariants, ?string $querySlug, string $candText, ?string $candSlug, string $candName): float
    {
        $best = max(
            $this->scoreMitFloor($queryTokens, $querySlug, $candText, $candSlug, $candName),
            $this->heuristik->substringOverlap($queryTokens, $candText),
        );
        // Alias-Varianten NUR token-strikt (kein substringOverlap): ein kurzes
        // generisches Alias-Token wie „rind" darf nicht als Substring dutzende
        // GPs („Rinderbrühe", „Rinde") auf 1.0 fluten und den echten Treffer im
        // Gleichstand aus den Top-K drängen. Ganz-Token-Match reicht für Aliase.
        foreach ($aliasVariants as $variant) {
            $s = $this->scoreMitFloor($variant, null, $candText, $candSlug, $candName);
            if ($s > $best) {
                $best = $s;
            }
        }

        return $best;
    }

    /**
     * S2 (#507 Weg-2): entfernt bekannte Verwechslungs-Fallen aus der Shortlist,
     * bevor sie an die LLM-Disambig geht — unabhängig vom (lexikalischen ODER
     * semantischen) Score. Genau das entsperrt das Flag-Scharfstellen: Anti-Marker
     * werden deterministisch (Vault Anti_Marker.md) gefiltert, nicht per Floor
     * gehofft (Brie↛Bries, Sojasauce↛Hollandaise, Paprikapulver↛Paprika frisch).
     *
     * @param  array<int, array{name:string, ...}>  $candidates
     * @return array<int, array{name:string, ...}>
     */
    private function stripAntiMarkers(string $ingredientName, array $candidates): array
    {
        return array_values(array_filter(
            $candidates,
            fn ($c) => ! $this->terminology->isAntiMarker($ingredientName, (string) ($c['name'] ?? '')),
        ));
    }

    // ── Pool-Scans ───────────────────────────────────────────────────────

    private function bestGpMatch(Team $team, array $queryTokens, ?string $querySlug, string $pref, bool $preferRaw, string $bio): ?array
    {
        $best = null;
        $bestZustand = null;
        $bestBio = null;
        foreach ($this->gpPool($team, $queryTokens, $querySlug) as $gp) {
            $combined = trim($gp->name . ' ' . ($gp->main_ingredient_display ?? ''));
            $score = $this->scoreMitFloor($queryTokens, $querySlug, $combined, $gp->main_ingredient_slug, $gp->name);

            $take = false;
            if ($best === null) {
                $take = true;
            } elseif ($score > $best['score'] + MatchHeuristics::SCORE_EPS) {
                $take = true;
            } elseif (abs($score - $best['score']) <= MatchHeuristics::SCORE_EPS) {
                // 4.4l/u — Tiebreaker bei (Float-)Gleichstand, Feld-primär
                $take = $this->heuristik->variantRankResolved($gp->name, $pref, $preferRaw, $bio, $gp->condition, $gp->bio)
                    > $this->heuristik->variantRankResolved($best['gp_name'], $pref, $preferRaw, $bio, $bestZustand, $bestBio);
            }
            if ($take) {
                $bestZustand = $gp->condition;
                $bestBio = $gp->bio;
                $best = [
                    'target' => 'gp', 'status' => MatchBand::fuerScore($score),
                    'gp_id' => $gp->id, 'gp_name' => $gp->name,
                    'recipe_id' => null, 'recipe_name' => null, 'score' => $score,
                ];
            }
        }

        return $best;
    }

    private function bestSubrecipeMatch(Team $team, array $queryTokens, ?string $querySlug): ?array
    {
        $typHint = $this->heuristik->detectSubTypHint($queryTokens);

        $best = null;
        foreach ($this->subPool($team, $queryTokens, $querySlug) as $sub) {
            $score = $this->scoreMitFloor($queryTokens, $querySlug, $sub->name, null, $sub->name);

            // 4.4b — Sub-Typ-Hint-Boost (+0.20, capped) wenn der Kandidat den Tag trägt
            if ($typHint !== null && ($sub->sub_typ_slug ?? '') === $typHint) {
                $score = min(1.0, $score + MatchHeuristics::SUB_TYP_HINT_BOOST);
            }

            if ($best === null || $score > $best['score']) {
                $best = [
                    'target' => 'sub_recipe', 'status' => MatchBand::fuerScore($score),
                    'gp_id' => null, 'gp_name' => null,
                    'recipe_id' => $sub->id, 'recipe_name' => $sub->name, 'score' => $score,
                ];
            }
        }

        return $best;
    }

    /** match_score + Name-Containment-Floor (4.4o, pool-agnostisch). */
    private function scoreMitFloor(array $queryTokens, ?string $querySlug, string $candText, ?string $candSlug, string $candName): float
    {
        $score = $this->engine->matchScore($queryTokens, $querySlug, $this->engine->tokenize($candText), $candSlug ?: null);
        if ($score < MatchHeuristics::NAME_CONTAINMENT_FLOOR && $this->engine->headMatchesQuery($candName, $queryTokens)) {
            $score = MatchHeuristics::NAME_CONTAINMENT_FLOOR;
        }

        return $score;
    }

    /** LIKE-Vorfilter (build_like_clauses) — GP-Pool, ORDER BY id (Inv. 7), LIMIT 300. */
    private function gpPool(Team $team, array $queryTokens, ?string $querySlug)
    {
        $query = FoodAlchemistGp::visibleToTeam($team)
            ->whereIn('status', ['approved', 'tentative'])
            ->where('is_platzhalter', false);
        $this->likeVorfilter($query, $queryTokens, $querySlug, ['name', 'main_ingredient_slug', 'main_ingredient_display']);

        return $query->orderBy('id')->limit(300)
            ->get(['id', 'name', 'main_ingredient_slug', 'main_ingredient_display', 'condition', 'bio', 'team_id']);
    }

    /** Sub-Pool: Basisrezepte (alle Workflow-Stadien inkl. stub), ORDER BY id, LIMIT 200. */
    private function subPool(Team $team, array $queryTokens, ?string $querySlug)
    {
        $query = FoodAlchemistRecipe::visibleToTeam($team)->basis()
            ->whereIn('status', ['stub', 'draft', 'review', 'approved']);
        $this->likeVorfilter($query, $queryTokens, $querySlug, ['name']);

        return $query->orderBy('id')->limit(200)
            ->get(['id', 'name', 'sub_recipe_type_legacy_id', 'team_id'])
            ->each(function ($sub) {
                // 4.4b — Sub-Typ-Slug über das (Legacy-)Vokabular; Tabelle folgt mit V-20,
                // bis dahin leerer Tag (Boost greift nur bei gepflegtem Slug)
                $sub->setAttribute('sub_typ_slug', $this->subTypSlug($sub->sub_recipe_type_legacy_id));
            });
    }

    private array $subTypCache = [];

    private function subTypSlug(?int $legacyId): string
    {
        if ($legacyId === null) {
            return '';
        }
        if (! \Illuminate\Support\Facades\Schema::hasTable('foodalchemist_vocab_sub_rezept_typ')) {
            return '';
        }

        return $this->subTypCache[$legacyId] ??= (string) \Illuminate\Support\Facades\DB::table('foodalchemist_vocab_sub_rezept_typ')
            ->where('legacy_id', $legacyId)->value('slug');
    }

    private function likeVorfilter($query, array $queryTokens, ?string $querySlug, array $spalten): void
    {
        $tokens = array_values(array_filter($queryTokens, fn ($t) => mb_strlen($t) >= 3));
        if ($tokens === []) {
            $tokens = $queryTokens;
        }
        // M6-07 (Hebel-1-Rest »umlaut-blindes Reuse-Inventar«): Tokens sind
        // umlaut-expandiert (kuerbis), DB-Namen tragen Umlaute (Kürbis) — je
        // Token zusätzlich die Umlaut-Rückform LIKEn. Rein additiv: erweitert
        // NUR das Kandidaten-Set, Scores bleiben token-normalisiert.
        $varianten = [];
        foreach ($tokens as $t) {
            $varianten[$t] = true;
            $u = str_replace(['ae', 'oe', 'ue', 'ss'], ['ä', 'ö', 'ü', 'ß'], $t);
            if ($u !== $t) {
                $varianten[$u] = true;
            }
        }
        $tokens = array_keys($varianten);
        $slugN = $querySlug !== null ? $this->engine->normalizeSlug($querySlug) : null;

        $query->where(function ($q) use ($tokens, $slugN, $spalten) {
            foreach ($tokens as $t) {
                foreach ($spalten as $spalte) {
                    $q->orWhereRaw("LOWER({$spalte}) LIKE ?", ['%' . $t . '%']);
                }
            }
            if ($slugN !== null && $slugN !== '') {
                foreach ($spalten as $spalte) {
                    $q->orWhereRaw("LOWER({$spalte}) LIKE ?", ['%' . $slugN . '%']);
                }
            }
        });
    }

    // ── Alias-Auflösung (Token-Set-Gleichheit, Existenz-Guard) ──────────

    private function resolveSubByName(Team $team, string $targetName): ?array
    {
        $targetTokens = $this->engine->tokenize($targetName);
        if ($targetTokens === []) {
            return null;
        }
        sort($targetTokens);
        foreach (FoodAlchemistRecipe::visibleToTeam($team)->basis()
            ->whereIn('status', ['stub', 'draft', 'review', 'approved'])
            ->orderBy('id')->cursor() as $r) {
            $tokens = $this->engine->tokenize($r->name);
            sort($tokens);
            if ($tokens === $targetTokens) {
                return ['id' => $r->id, 'name' => $r->name];
            }
        }

        return null;
    }

    private function resolveGpByName(Team $team, string $targetName): ?array
    {
        $targetTokens = $this->engine->tokenize($targetName);
        if ($targetTokens === []) {
            return null;
        }
        sort($targetTokens);
        foreach (FoodAlchemistGp::visibleToTeam($team)
            ->whereIn('status', ['approved', 'tentative'])->where('is_platzhalter', false)
            ->orderBy('id')->cursor() as $gp) {
            $tokens = $this->engine->tokenize($gp->name);
            sort($tokens);
            if ($tokens === $targetTokens) {
                return ['id' => $gp->id, 'name' => $gp->name];
            }
        }

        return null;
    }

    private function besserer(?array $a, ?array $b): array
    {
        if ($a !== null && $b !== null) {
            return $a['score'] >= $b['score'] ? $a : $b;
        }

        return $a ?? $b ?? $this->noMatch(0.0);
    }

    private function noMatch(float $score): array
    {
        return [
            'target' => 'none', 'status' => MatchBand::NoMatch,
            'gp_id' => null, 'gp_name' => null,
            'recipe_id' => null, 'recipe_name' => null, 'score' => $score,
        ];
    }
}
