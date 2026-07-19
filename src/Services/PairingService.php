<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;

/**
 * M5-04/05: GL-10 — Pairing-Kohäsion & Anker-Graph (deterministisch, ohne KI).
 * Queries read-only (Inv. 7); Schreibpfade nur set/remove (Caps Inv. 1,
 * manual gewinnt Inv. 3). Kanten sind seit dem V-23-Backfill symmetrisch (Inv. 4).
 * Fehlende Kante = unbekannt, nie Clash (Inv. 5); Scores = runde Ganzzahlen (Inv. 8).
 */
class PairingService
{
    // Taxonomie 2026-07-12: drei zeitlose Kanten-Typen. erprobt = in der Küche bewährt
    // (verschmilzt das frühere klassisch+modern — Ära ist kein Fit-Kriterium), aroma =
    // geteiltes Aromamolekül (Buch/computed), kontrast = passt durch Gegensatz.
    private const GEWICHTE = ['erprobt' => 1.0, 'aroma' => 0.9, 'kontrast' => 0.5]; // Tabelle 1

    private const TYP_PRIO = ['erprobt' => 1, 'aroma' => 2, 'kontrast' => 3];

    /** Geschmacks-Achsen (anchor_taste_vectors / vocab_process_sensory_deltas). */
    private const TASTE_ACHSEN = ['suess', 'salzig', 'sauer', 'bitter', 'umami', 'fettig', 'scharf'];

    public const CAP_GP = 3;

    public const CAP_RECIPE = 5;

    // ── Slug-Matching (Tabelle 2/3) ──────────────────────────────────────

    /** Pairing-Slug-Normalisierung: ä→a … PLUS Digraphen ae→a/oe→o/ue→u (Tabelle 2). */
    public function normalizeAnkerSlug(string $s): string
    {
        $s = mb_strtolower($s);
        $s = strtr($s, ['ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ß' => 'ss']);

        return strtr($s, ['ae' => 'a', 'oe' => 'o', 'ue' => 'u']);
    }

    /** T1/T2: tolerant + ungerichtet — roh/normalisiert exakt oder _-Präfix; nie Geschwister-Sorten. */
    public function ankerSlugMatches(string $a, string $b): bool
    {
        $praefix = fn (string $x, string $y) => str_starts_with($y, $x . '_') || str_starts_with($x, $y . '_');
        if ($a === $b || $praefix($a, $b)) {
            return true;
        }
        $an = $this->normalizeAnkerSlug($a);
        $bn = $this->normalizeAnkerSlug($b);

        return $an === $bn || $praefix($an, $bn);
    }

    /** T3: GERICHTET — nur gleich/allgemeiner als die Hauptzutat; längster gültiger gewinnt. */
    public function bestIdentityAnchor(string $hauptzutatSlug, array $ankerSlugs): ?string
    {
        $hn = $this->normalizeAnkerSlug($hauptzutatSlug);
        $bester = null;
        foreach ($ankerSlugs as $anker) {
            $an = $this->normalizeAnkerSlug($anker);
            if ($an === $hn || str_starts_with($hn, $an . '_')) {   // gleich ODER allgemeiner
                if ($bester === null || mb_strlen($an) > mb_strlen($this->normalizeAnkerSlug($bester))) {
                    $bester = $anker;
                }
            }
        }

        return $bester;
    }

    // ── Komponenten-Auflösung (3.1) ──────────────────────────────────────

    private ?array $anchorIndex = null;

    /** fold(): lowercase, Umlaut→Digraph, nicht-alnum→Space, kollabiert, umrandet. */
    public function fold(string $s): string
    {
        $s = mb_strtolower($s);
        $s = strtr($s, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);

        return ' ' . trim(preg_replace('/\s+/', ' ', $s)) . ' ';
    }

    /** Term-Index über alle Anker (außer neutral): slug prio 0, display 1, Einzelwörter ≥ 4 prio 2. */
    private function anchorIndex(): array
    {
        if ($this->anchorIndex !== null) {
            return $this->anchorIndex;
        }
        $index = [];
        foreach (DB::table('foodalchemist_vocab_pairing_anchors')->whereNull('deleted_at')
            ->where('slug', '!=', 'neutral')->get(['id', 'slug', 'display_de']) as $anker) {
            $terme = [[trim($this->fold($anker->slug)), 0]];
            $display = trim($this->fold($anker->display_de));
            if ($display !== $terme[0][0]) {
                $terme[] = [$display, 1];
            }
            foreach (array_unique(array_merge(explode(' ', $terme[0][0]), explode(' ', $display))) as $wort) {
                if (mb_strlen($wort) >= 4) {
                    $terme[] = [$wort, 2];
                }
            }
            foreach ($terme as [$term, $prio]) {
                if ($term === '') {
                    continue;
                }
                if (! isset($index[$term]) || $index[$term][1] > $prio) {
                    $index[$term] = [$anker->id, $prio];            // bestehender mit ≤ prio bleibt
                }
            }
        }

        return $this->anchorIndex = $index;
    }

    /** resolve_by_name: längster Substring-Term gewinnt, Gleichstand → niedrigere prio. */
    public function resolveByName(string $name): ?int
    {
        $folded = $this->fold($name);
        $gewinner = null;
        foreach ($this->anchorIndex() as $term => [$ankerId, $prio]) {
            if (str_contains($folded, ' ' . $term . ' ') || str_contains($folded, $term)) {
                if ($gewinner === null
                    || mb_strlen($term) > $gewinner[0]
                    || (mb_strlen($term) === $gewinner[0] && $prio < $gewinner[2])) {
                    $gewinner = [mb_strlen($term), $ankerId, $prio];
                }
            }
        }

        return $gewinner[1] ?? null;
    }

    /**
     * B: semantische Anker-Auflösung als Fallback (opt-in). Gibt die Anker-ID
     * des besten Embedding-Treffers über der Schwelle zurück, sonst null.
     * Deaktiviert (Default) / kein Provider / Fehler ⇒ null (kein Verhalten).
     */
    private function resolveAnkerSemantically(string $name): ?int
    {
        if (trim($name) === '' || ! config('foodalchemist.semantic_search.enabled', false)) {
            return null;
        }
        try {
            return app(\Platform\FoodAlchemist\Services\Ai\KnowledgeEmbeddingService::class)->resolveAnkerId($name);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * resolve_recipe_anchors (Tabelle 4): pro Zutaten-Zeile GENAU EIN Kern
     * (+ Prozess-Anker nur bei Sub-Rezepten).
     *
     * @return array<int, array{label: string, kern: ?int, prozess: array<int>, via: string}>
     */
    public function resolveRecipeAnchors(FoodAlchemistRecipe $recipe): array
    {
        $neutralId = DB::table('foodalchemist_vocab_pairing_anchors')->where('slug', 'neutral')->value('id');
        $out = [];
        foreach ($recipe->ingredients()->with(['gp:id,name', 'referencedRecipe:id,name'])->whereNull('deleted_at')->orderBy('position')->get() as $z) {
            $label = $z->referencedRecipe?->name ?? $z->gp?->name ?? $z->raw_text;
            $kern = null;
            $via = 'unresolved';
            $prozess = [];

            if ($z->referenced_recipe_id !== null) {
                $mapping = DB::table('foodalchemist_recipe_anchor_mappings')
                    ->where('recipe_id', $z->referenced_recipe_id)->where('role', 'kern')->whereNull('deleted_at')
                    ->orderByRaw('COALESCE(ai_confidence, 1.0) DESC')->orderBy('id')
                    ->value('anchor_id');
                if ($mapping !== null) {
                    [$kern, $via] = $mapping === $neutralId ? [null, 'neutral'] : [$mapping, 'recipe_anker'];
                } else {
                    $kern = $this->resolveByName($z->referencedRecipe->name);
                    $via = $kern !== null ? 'name_match' : 'unresolved';
                }
                $prozess = DB::table('foodalchemist_recipe_process_anchors')
                    ->where('recipe_id', $z->referenced_recipe_id)->whereNull('deleted_at')
                    ->where('anchor_id', '!=', $kern ?? 0)->pluck('anchor_id')->all();
            } elseif ($z->gp_id !== null) {
                $mapping = DB::table('foodalchemist_gp_anchor_mappings')
                    ->where('gp_id', $z->gp_id)->where('role', 'kern')->whereNull('deleted_at')
                    ->orderByRaw('COALESCE(ai_confidence, 1.0) DESC')->orderBy('id')
                    ->value('anchor_id');
                if ($mapping !== null) {
                    [$kern, $via] = $mapping === $neutralId ? [null, 'neutral'] : [$mapping, 'gp_anker'];
                } else {
                    $kern = $this->resolveByName($z->gp->name);
                    $via = $kern !== null ? 'name_match' : 'unresolved';
                }
            } else {
                $kern = $this->resolveByName($z->raw_text);
                $via = $kern !== null ? 'name_match' : 'unresolved';
            }

            // B: semantischer Fallback NUR für sonst unauflösbare Zeilen (opt-in,
            // hinter foodalchemist.semantic_search.enabled). Überschreibt NIE
            // explizite gp/recipe-Mappings; markiert via='embedding' für Provenienz.
            if ($kern === null && $via === 'unresolved') {
                $semId = $this->resolveAnkerSemantically($label);
                if ($semId !== null) {
                    $kern = $semId;
                    $via = 'embedding';
                }
            }

            $out[] = ['label' => $label, 'kern' => $kern, 'prozess' => $prozess, 'via' => $via];
        }

        // Eigen-Zustand (Datenmodell Ebene 2/3): die Prozess-Charakter-Anker DIESES
        // Rezepts (raw_text-Prep + KI, z. B. roestaromen/rauch/karamell) gehören ins
        // eigene Netz — eine geröstete/geräucherte Komponente ist eine eigene Aroma-
        // Dimension, nicht nur die Rohzutat. Bisher flossen nur Prozess-Anker von
        // SUB-Rezepten (oben via referenced_recipe_id). Dedupe gegen bereits als kern
        // aufgelöste Anker, damit keine Selbst-Paare entstehen.
        $vorhandeneKerne = array_filter(array_map(fn ($k) => $k['kern'], $out));
        $eigeneProzess = DB::table('foodalchemist_recipe_process_anchors AS p')
            ->join('foodalchemist_vocab_pairing_anchors AS a', 'a.id', '=', 'p.anchor_id')
            ->where('p.recipe_id', $recipe->id)->whereNull('p.deleted_at')
            ->whereNull('a.deleted_at')
            ->get(['p.anchor_id', 'a.slug']);
        foreach ($eigeneProzess as $pa) {
            if (in_array((int) $pa->anchor_id, $vorhandeneKerne, true)) {
                continue;
            }
            $out[] = ['label' => $pa->slug . ' (Zustand)', 'kern' => (int) $pa->anchor_id, 'prozess' => [], 'via' => 'prozess_raw_text'];
        }

        return $out;
    }

    // ── Kohäsion (3.2 — T4/T5/T6/T9) ─────────────────────────────────────

    /**
     * @param array<int, array{label: string, kern: ?int, prozess: array<int>, via: string}> $komponenten
     */
    public function cohesionFor(array $komponenten): array
    {
        $aufgeloest = array_values(array_filter($komponenten, fn ($k) => $k['kern'] !== null || $k['prozess'] !== []));
        $n = count($aufgeloest);
        $totalPairs = intdiv($n * ($n - 1), 2);

        $alleAnker = [];
        foreach ($aufgeloest as $k) {
            foreach (array_merge($k['kern'] !== null ? [$k['kern']] : [], $k['prozess']) as $a) {
                $alleAnker[$a] = true;
            }
        }
        $kanten = $this->edgeBest(array_keys($alleAnker));

        $staerken = [];
        $unrated = [];
        $fit = array_fill(0, $n, ['sum' => 0.0, 'cnt' => 0]);
        $schwaechstes = null;
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $w = null;
                $typ = null;
                foreach (array_merge($aufgeloest[$i]['kern'] !== null ? [$aufgeloest[$i]['kern']] : [], $aufgeloest[$i]['prozess']) as $ka) {
                    foreach (array_merge($aufgeloest[$j]['kern'] !== null ? [$aufgeloest[$j]['kern']] : [], $aufgeloest[$j]['prozess']) as $kb) {
                        if ($ka === $kb) {
                            [$w, $typ] = [1.0, 'gleich'];
                        } elseif (isset($kanten[$ka][$kb]) && ($w === null || $kanten[$ka][$kb][0] > $w)) {
                            [$w, $typ] = $kanten[$ka][$kb];
                        }
                    }
                }
                if ($w !== null) {
                    $staerken[] = $w;
                    $fit[$i]['sum'] += $w;
                    $fit[$i]['cnt']++;
                    $fit[$j]['sum'] += $w;
                    $fit[$j]['cnt']++;
                    if ($schwaechstes === null || $w < $schwaechstes['w']) {
                        $schwaechstes = ['w' => $w, 'a' => $aufgeloest[$i]['label'], 'b' => $aufgeloest[$j]['label'], 'type' => $typ];
                    }
                } else {
                    $unrated[] = [$aufgeloest[$i]['label'], $aufgeloest[$j]['label']];
                }
            }
        }

        $anyRated = $staerken !== [];
        $komponentenOut = [];
        foreach ($aufgeloest as $i => $k) {
            $komponentenOut[] = [
                'label' => $k['label'], 'via' => $k['via'],
                'fit' => $fit[$i]['cnt'] > 0 ? (int) round(100 * $fit[$i]['sum'] / $fit[$i]['cnt']) : null,
                'rated_links' => $fit[$i]['cnt'],
                'is_orphan' => $k['kern'] !== null && $fit[$i]['cnt'] === 0 && $anyRated,  // T9: keine Daten ≠ kein Fit
            ];
        }

        return [
            'score' => $anyRated ? (int) round(100 * array_sum($staerken) / count($staerken)) : 0,
            'min_score' => $anyRated ? (int) round(100 * min($staerken)) : 0,
            'rated_pairs' => count($staerken),
            'total_pairs' => $totalPairs,
            'coverage_pct' => $totalPairs > 0 ? (int) round(100 * count($staerken) / $totalPairs) : 0,
            'weakest_pair' => $schwaechstes !== null
                ? ['a' => $schwaechstes['a'], 'b' => $schwaechstes['b'], 'score' => (int) round(100 * $schwaechstes['w']), 'type' => $schwaechstes['type']]
                : null,
            'unrated_pairs' => $unrated,
            'komponenten' => $komponentenOut,
        ];
    }

    public function recipeCohesion(FoodAlchemistRecipe $recipe): array
    {
        return $this->cohesionFor($this->resolveRecipeAnchors($recipe));
    }

    /** R6.1: flache, eindeutige Anker-IDs eines Rezepts (kern + prozess über alle Zutaten). */
    public function anchorsForRecipe(FoodAlchemistRecipe $recipe): array
    {
        $ids = [];
        foreach ($this->resolveRecipeAnchors($recipe) as $k) {
            foreach (array_merge($k['kern'] !== null ? [$k['kern']] : [], $k['prozess']) as $a) {
                $ids[$a] = true;
            }
        }

        return array_keys($ids);
    }

    /** R6.1: beste Kanten zwischen Anker-IDs (öffentlicher Zugriff auf edgeBest — Menü-Ranking im Generator). */
    public function edgesFor(array $ankerIds): array
    {
        return $this->edgeBest($ankerIds);
    }

    /**
     * R6.1 Kohäsions-Beweis über eine MENÜFOLGE: jedes Gericht ist EINE Komponente
     * (Anker = Union seiner Zutaten-Anker), Score/Coverage/schwächstes Paar über die
     * Gericht-Paare. Gleiche Mechanik wie der Teller-Score (cohesionFor), eine Ebene höher.
     *
     * @param  list<FoodAlchemistRecipe>  $dishes
     */
    public function menuCohesion(array $dishes): array
    {
        $komponenten = [];
        foreach ($dishes as $dish) {
            $komponenten[] = [
                'label' => $dish->name,
                'via' => 'menu',
                'kern' => null,
                'prozess' => $this->anchorsForRecipe($dish),
            ];
        }

        return $this->cohesionFor($komponenten);
    }

    // ── Suggest (3.3 — T8) ───────────────────────────────────────────────

    /** @return array{klassiker: array, signature: array} */
    public function componentSuggestions(FoodAlchemistRecipe $recipe, int $top = 8): array
    {
        $dish = [];
        foreach ($this->resolveRecipeAnchors($recipe) as $k) {
            foreach (array_merge($k['kern'] !== null ? [$k['kern']] : [], $k['prozess']) as $a) {
                $dish[$a] = true;
            }
        }
        if (count($dish) < 2) {
            return ['klassiker' => [], 'signature' => []];
        }
        $dishIds = array_keys($dish);

        $kandidaten = [];
        foreach (DB::table('foodalchemist_pairing_anchor_edges')->whereIn('anchor_b_id', $dishIds)
            ->whereNotIn('anchor_a_id', $dishIds)
            ->get(['anchor_a_id', 'anchor_b_id', 'type', 'weight']) as $kante) {
            // wie edgeBest(): computed-Gewicht gewinnt, sonst typ-getrieben.
            $w = $kante->weight !== null ? (float) $kante->weight : (self::GEWICHTE[$kante->type] ?? 0.5);
            $k = &$kandidaten[$kante->anchor_a_id];
            $k['best'][$kante->anchor_b_id] = max($k['best'][$kante->anchor_b_id] ?? 0, $w);
        }
        unset($k);

        $grade = DB::table('foodalchemist_pairing_anchor_edges')->whereIn('anchor_a_id', array_keys($kandidaten))
            ->selectRaw('anchor_a_id, COUNT(*) AS n')->groupBy('anchor_a_id')->pluck('n', 'anchor_a_id');
        $namen = DB::table('foodalchemist_vocab_pairing_anchors')
            ->whereIn('id', array_merge(array_keys($kandidaten), $dishIds))   // + dish für »verbindet n/m: …«
            ->pluck('slug', 'id');

        $liste = [];
        foreach ($kandidaten as $id => $daten) {
            $cover = count($daten['best']);
            if ($cover < 2) {
                continue;                                           // Filter cover ≥ 2
            }
            $meanW = (int) round(100 * array_sum($daten['best']) / $cover);
            $degree = (int) ($grade[$id] ?? 0);
            $liste[] = [
                'anchor_id' => $id, 'slug' => (string) $namen[$id], 'cover' => $cover,
                'mean_w' => $meanW, 'degree' => $degree,
                'spec' => ($cover * $meanW / 100) / sqrt(max($degree, 1)),
                // Anzeige-Zusatz (Ist-App »Aroma-Nachbarn«): welche Teller-Anker er trifft,
                // |dish| als Nenner, Allrounder = promiskuitiver Kandidat (hoher Grad)
                'trifft' => collect(array_keys($daten['best']))->map(fn ($d) => (string) ($namen[$d] ?? $d))->sort()->values()->all(),
                'dish_n' => count($dishIds),
                'allrounder' => $degree >= 50,
            ];
        }

        $klassiker = $liste;
        usort($klassiker, fn ($a, $b) => [$b['cover'], $b['mean_w'], $a['degree'], $a['slug']] <=> [$a['cover'], $a['mean_w'], $b['degree'], $b['slug']]);
        $signature = $liste;
        usort($signature, fn ($a, $b) => [$b['spec'], $b['mean_w'], $a['slug']] <=> [$a['spec'], $a['mean_w'], $b['slug']]);

        return ['klassiker' => array_slice($klassiker, 0, $top), 'signature' => array_slice($signature, 0, $top)];
    }

    // ── Bridge / verwandte Rezepte / Nachbarn (3.4 — T7) ────────────────

    public function pairingBridge(int $recipeA, int $recipeB): array
    {
        $ankerA = DB::table('foodalchemist_recipe_pairings')->where('recipe_id', $recipeA)->whereNull('deleted_at')->distinct()->pluck('anchor_id')->all();
        $ankerB = DB::table('foodalchemist_recipe_pairings')->where('recipe_id', $recipeB)->whereNull('deleted_at')->distinct()->pluck('anchor_id')->all();

        $direkte = array_values(array_intersect($ankerA, $ankerB));
        // LIMIT 30 deckelt die indirekte Zählung (Ist holt max 30 Zeilen) — COUNT ignoriert
        // LIMIT in SQL, daher explizit über die gedeckelte Ergebnisliste zählen
        $indirekte = DB::table('foodalchemist_pairing_anchor_edges')
            ->whereIn('anchor_a_id', $ankerA)->whereIn('anchor_b_id', $ankerB)
            ->whereColumn('anchor_a_id', '!=', 'anchor_b_id')
            ->orderByRaw("CASE type WHEN 'erprobt' THEN 1 WHEN 'aroma' THEN 2 WHEN 'kontrast' THEN 3 ELSE 4 END")
            ->limit(30)->get(['id'])->count();

        return [
            'direkte' => count($direkte),
            'indirekte' => $indirekte,
            'bridge_strength' => 2 * count($direkte) + $indirekte,
        ];
    }

    public function recipesSharingPairings(Team $team, int $recipeId, int $minShared = 2, int $limit = 10): Collection
    {
        $minShared = max(1, $minShared);
        $limit = max(1, min(50, $limit));
        $eigene = DB::table('foodalchemist_recipe_pairings')->where('recipe_id', $recipeId)->whereNull('deleted_at')->distinct()->pluck('anchor_id');
        if ($eigene->isEmpty()) {
            return collect();
        }

        $treffer = DB::table('foodalchemist_recipe_pairings AS rp')
            ->whereIn('rp.anchor_id', $eigene)->where('rp.recipe_id', '!=', $recipeId)->whereNull('rp.deleted_at')
            ->selectRaw('rp.recipe_id, COUNT(DISTINCT rp.anchor_id) AS shared')
            ->groupBy('rp.recipe_id')->havingRaw('COUNT(DISTINCT rp.anchor_id) >= ?', [$minShared])
            ->get();

        $gesamt = DB::table('foodalchemist_recipe_pairings')->whereIn('recipe_id', $treffer->pluck('recipe_id'))
            ->whereNull('deleted_at')->selectRaw('recipe_id, COUNT(DISTINCT anchor_id) AS n')->groupBy('recipe_id')->pluck('n', 'recipe_id');
        $rezepte = FoodAlchemistRecipe::visibleToTeam($team)->whereIn('id', $treffer->pluck('recipe_id'))->pluck('name', 'id');

        return $treffer->filter(fn ($t) => $rezepte->has($t->recipe_id))
            ->map(fn ($t) => [
                'recipe_id' => $t->recipe_id,
                'name' => $rezepte[$t->recipe_id],
                'shared' => (int) $t->shared,
                'eigene_gesamt' => (int) ($gesamt[$t->recipe_id] ?? 0),
                'shared_slugs' => DB::table('foodalchemist_recipe_pairings AS rp')
                    ->join('foodalchemist_vocab_pairing_anchors AS a', 'a.id', '=', 'rp.anchor_id')
                    ->where('rp.recipe_id', $t->recipe_id)->whereIn('rp.anchor_id', $eigene)->whereNull('rp.deleted_at')
                    ->distinct()->limit(5)->pluck('a.slug')->all(),
            ])
            ->sortBy([fn ($a, $b) => [$b['shared'], $a['eigene_gesamt'], $a['recipe_id']] <=> [$a['shared'], $b['eigene_gesamt'], $b['recipe_id']]])
            ->take($limit)->values();
    }

    public function ankerNeighbors(string $slug, ?string $typ = null, int $limit = 30): Collection
    {
        $limit = max(1, min(200, $limit));
        $ankerId = DB::table('foodalchemist_vocab_pairing_anchors')->where('slug', $slug)->value('id');
        if ($ankerId === null) {
            return collect();
        }

        return DB::table('foodalchemist_pairing_anchor_edges AS e')
            ->join('foodalchemist_vocab_pairing_anchors AS a', 'a.id', '=', 'e.anchor_b_id')
            ->where('e.anchor_a_id', $ankerId)
            ->when($typ !== null, fn ($q) => $q->where('e.type', $typ))
            ->orderByRaw("CASE e.type WHEN 'erprobt' THEN 1 WHEN 'aroma' THEN 2 WHEN 'kontrast' THEN 3 ELSE 4 END")
            ->orderBy('a.slug')->limit($limit)
            ->get(['a.id', 'a.slug', 'a.display_de', 'e.type', 'e.evidence']);
    }

    // ── Schreibpfade (Inv. 1/3) ──────────────────────────────────────────

    public function setRecipeAnker(Team $team, int $recipeId, int $ankerId): void
    {
        $recipe = FoodAlchemistRecipe::visibleToTeam($team)->findOrFail($recipeId);
        $vorhanden = DB::table('foodalchemist_recipe_anchor_mappings')
            ->where('recipe_id', $recipe->id)->where('anchor_id', $ankerId)->whereNull('deleted_at')->first();
        if ($vorhanden === null
            && DB::table('foodalchemist_recipe_anchor_mappings')->where('recipe_id', $recipe->id)->whereNull('deleted_at')->count() >= self::CAP_RECIPE) {
            throw new \RuntimeException('Limit erreicht: max ' . self::CAP_RECIPE . ' Kern-Anker pro Rezept.');
        }
        DB::table('foodalchemist_recipe_anchor_mappings')->updateOrInsert(
            ['recipe_id' => $recipe->id, 'anchor_id' => $ankerId],
            ['uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(), 'team_id' => $team->id, 'role' => 'kern',
                'source' => 'manual', 'ai_confidence' => null, 'ai_reasoning' => null,    // manual gewinnt (Inv. 3)
                'deleted_at' => null, 'updated_at' => now(), 'created_at' => now()],
        );
    }

    public function removeRecipeAnker(Team $team, int $recipeId, int $ankerId): void
    {
        FoodAlchemistRecipe::visibleToTeam($team)->findOrFail($recipeId);
        DB::table('foodalchemist_recipe_anchor_mappings')
            ->where('recipe_id', $recipeId)->where('anchor_id', $ankerId)->update(['deleted_at' => now()]);
    }

    /** Anker eines Rezepts inkl. Slug/Quelle (Panel-Chips). */
    public function recipeAnkers(int $recipeId): Collection
    {
        return DB::table('foodalchemist_recipe_anchor_mappings AS m')
            ->join('foodalchemist_vocab_pairing_anchors AS a', 'a.id', '=', 'm.anchor_id')
            ->where('m.recipe_id', $recipeId)->whereNull('m.deleted_at')
            ->orderByRaw('COALESCE(m.ai_confidence, 1.0) DESC')->orderBy('m.id')
            ->get(['a.id', 'a.slug', 'a.display_de', 'm.source', 'm.ai_confidence']);
    }

    /** Pairing-Partner eines Rezepts (recipe_pairings — Chips, M5-05). */
    public function recipePairings(int $recipeId): Collection
    {
        return DB::table('foodalchemist_recipe_pairings AS rp')
            ->join('foodalchemist_vocab_pairing_anchors AS a', 'a.id', '=', 'rp.anchor_id')
            ->where('rp.recipe_id', $recipeId)->whereNull('rp.deleted_at')
            ->orderByRaw("CASE rp.type WHEN 'erprobt' THEN 1 WHEN 'verbund' THEN 2 WHEN 'trinitas' THEN 3 ELSE 4 END")
            ->orderBy('a.slug')
            ->get(['a.id', 'a.slug', 'a.display_de', 'rp.type', 'rp.confidence', 'rp.created_via']);
    }

    /** Manuelles Pairing setzen (recipe_pairings, created_via='manual' — bewusst gesetzt, gewinnt). */
    public function setRecipePairing(Team $team, int $recipeId, int $ankerId, string $typ = 'erprobt'): void
    {
        $recipe = FoodAlchemistRecipe::visibleToTeam($team)->findOrFail($recipeId);
        $typ = in_array($typ, ['erprobt', 'aroma', 'kontrast', 'verbund', 'trinitas'], true) ? $typ : 'erprobt';
        DB::table('foodalchemist_recipe_pairings')->updateOrInsert(
            ['recipe_id' => $recipe->id, 'anchor_id' => $ankerId, 'type' => $typ],
            ['uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(), 'team_id' => $team->id,
                'confidence' => 'hoch', 'created_via' => 'manual', 'note' => null,
                'deleted_at' => null, 'updated_at' => now(), 'created_at' => now()],
        );
    }

    public function removeRecipePairing(Team $team, int $recipeId, int $ankerId, ?string $typ = null): void
    {
        FoodAlchemistRecipe::visibleToTeam($team)->findOrFail($recipeId);
        DB::table('foodalchemist_recipe_pairings')
            ->where('recipe_id', $recipeId)->where('anchor_id', $ankerId)
            ->when($typ !== null, fn ($q) => $q->where('type', $typ))
            ->update(['deleted_at' => now()]);
    }

    /** Kern-Aroma-Anker eines GP inkl. Slug/Quelle (GP-Pairing-Panel). */
    public function gpAnkers(int $gpId): Collection
    {
        return DB::table('foodalchemist_gp_anchor_mappings AS m')
            ->join('foodalchemist_vocab_pairing_anchors AS a', 'a.id', '=', 'm.anchor_id')
            ->where('m.gp_id', $gpId)->where('m.role', 'kern')->whereNull('m.deleted_at')
            ->orderByRaw('COALESCE(m.ai_confidence, 1.0) DESC')->orderBy('m.id')
            ->get(['a.id', 'a.slug', 'a.display_de', 'm.source', 'm.ai_confidence']);
    }

    // ── Kompakt-Panels fürs »Sensorik & Pairing«-Tab (read-only) ─────────

    /** Prozess-/neutrale Anker sind keine Zutat-Vorschläge (»Fermentiert« kauft man nicht). */
    private const NICHT_ZUTAT_ANKER = ['neutral', 'roestaromen', 'ferment', 'karamell', 'rauch'];

    /**
     * Aroma-Nachbarn eines Kanten-Typs über mehrere Anker, dedupliziert, ohne die eigenen.
     * Quelle = dieselben Anker-Kanten wie der Aroma-Netz-Graph (klassisch | kontrast). Ranking
     * nach »cover« (mit wie vielen Teller-Ankern bringt der Kandidat den Typ) — relevanteste zuerst;
     * Prozess-/Neutral-Anker rausgefiltert (keine Zutat).
     */
    private function ankerNachbarnAggregiert(array $ankerSlugs, array $eigeneIds, string $typ): array
    {
        $treffer = [];
        foreach ($ankerSlugs as $slug) {
            foreach ($this->ankerNeighbors($slug, $typ, 20) as $n) {
                $id = (int) $n->id;
                if (in_array($id, $eigeneIds, true) || in_array($n->slug, self::NICHT_ZUTAT_ANKER, true)) {
                    continue;
                }
                $treffer[$id] ??= ['name' => $n->display_de ?: $n->slug, 'cover' => 0];
                $treffer[$id]['cover']++;
            }
        }
        uasort($treffer, fn ($a, $b) => [$b['cover'], $a['name']] <=> [$a['cover'], $b['name']]);

        return array_slice(array_map(fn ($t) => $t['name'], array_values($treffer)), 0, 18);
    }

    /**
     * Pairing-Panel (read-only, keine KI). Immer: Kohäsion + Kern-Anker + Kontrast.
     * GERICHT zusätzlich: »komplettiert den Teller« (klassiker) + »macht den Teller
     * eigen« (signature) — Teller-Logik. BASISREZEPT (Komponente) stattdessen die
     * Graph-Sicht: klassische Aroma-Nachbarn + verwandte Basisrezepte.
     */
    public function panelRecipe(FoodAlchemistRecipe $recipe): array
    {
        $k = $this->recipeCohesion($recipe);
        $ankerRows = $this->recipeAnkers($recipe->id);
        $slugs = $ankerRows->pluck('slug')->all();
        $eigene = $ankerRows->pluck('id')->map(fn ($i) => (int) $i)->all();

        // Zustands-Charakter (Ebene 2/3) prägt das EMERGENTE Paarungsprofil mit: die
        // eigenen Prozess-Anker (roestaromen/rauch/karamell) in die auswärtige
        // Nachbar-Aggregation (aroma/modern/kontrast) einspeisen — sonst bliebe das
        // Röst-/Rauch-Profil unsichtbar, obwohl es die Kohäsion schon mitträgt.
        $prozessRows = DB::table('foodalchemist_recipe_process_anchors AS p')
            ->join('foodalchemist_vocab_pairing_anchors AS a', 'a.id', '=', 'p.anchor_id')
            ->where('p.recipe_id', $recipe->id)->whereNull('p.deleted_at')->whereNull('a.deleted_at')
            ->get(['a.id', 'a.slug']);
        foreach ($prozessRows as $pr) {
            if (! in_array((int) $pr->id, $eigene, true)) {
                $slugs[] = $pr->slug;
                $eigene[] = (int) $pr->id;
            }
        }

        // Teller-Logik (»komplettiert den Teller« + »macht den Teller eigen«) ergibt
        // NUR fürs GERICHT Sinn — ein Basisrezept ist eine Komponente, kein Teller.
        // Basisrezept ⇒ stattdessen die Graph-Sicht: klassische Aroma-Nachbarn +
        // verwandte Basisrezepte (geteilte Pairing-Anker).
        $istGericht = (bool) $recipe->is_sales_recipe;
        $vorschlaege = $signature = $nachbarn = $verwandte = [];

        if ($istGericht) {
            $sug = $this->componentSuggestions($recipe, 6);
            $mapV = fn ($v) => [
                'slug' => $v['slug'], 'cover' => $v['cover'], 'dish_n' => $v['dish_n'],
                'mean_w' => $v['mean_w'], 'allrounder' => $v['allrounder'],
            ];
            $vorschlaege = collect($sug['klassiker'])->map($mapV)->all();
            $signature = collect($sug['signature'])->map($mapV)->all();
        } else {
            $nachbarn = $this->ankerNachbarnAggregiert($slugs, $eigene, 'erprobt');
            $team = Team::find((int) $recipe->team_id);
            $verwandte = $team !== null
                ? $this->recipesSharingPairings($team, $recipe->id)->all()
                : [];
        }

        return [
            'type' => 'recipe',
            'ist_gericht' => $istGericht,
            'score' => $k['score'],
            'coverage_pct' => $k['coverage_pct'],
            'rated_pairs' => $k['rated_pairs'],
            'total_pairs' => $k['total_pairs'],
            'weakest_pair' => $k['weakest_pair'],
            'orphans' => array_values(array_map(
                fn ($c) => $c['label'],
                array_filter($k['komponenten'], fn ($c) => $c['is_orphan']),
            )),
            'anker' => $ankerRows
                ->map(fn ($a) => ['slug' => $a->slug, 'display_de' => $a->display_de, 'source' => $a->source])->all(),
            'vorschlaege' => $vorschlaege,
            'signature' => $signature,
            'nachbarn' => $nachbarn,
            'verwandte' => $verwandte,
            'aroma' => $this->ankerNachbarnAggregiert($slugs, $eigene, 'aroma'),
            'kontrast' => $this->ankerNachbarnAggregiert($slugs, $eigene, 'kontrast'),
            'geschmack' => $this->aggregatedTaste($eigene),
        ];
    }

    /** GP: eigene Aroma-Anker + klassische Nachbarn (»passt zu«) + Kontrast (Gegenpol). */
    public function panelGp(int $gpId): array
    {
        $anker = $this->gpAnkers($gpId);
        $slugs = $anker->pluck('slug')->all();
        $eigene = $anker->pluck('id')->map(fn ($i) => (int) $i)->all();

        return [
            'type' => 'gp',
            'anker' => $anker->map(fn ($a) => ['slug' => $a->slug, 'display_de' => $a->display_de, 'source' => $a->source])->all(),
            'nachbarn' => $this->ankerNachbarnAggregiert($slugs, $eigene, 'erprobt'),
            'aroma' => $this->ankerNachbarnAggregiert($slugs, $eigene, 'aroma'),
            'kontrast' => $this->ankerNachbarnAggregiert($slugs, $eigene, 'kontrast'),
            'geschmack' => $this->aggregatedTaste($eigene),
        ];
    }

    // ── M5-07: Aroma-Netz-Graph (D-7, 13_REFERENZ Nachlieferung 2) ───────

    /**
     * Datenbasis fürs Aroma-Netz-Modal: Ring = Kern-Anker (★, zuerst) +
     * Pairing-Anker des Rezepts (Cap 28 für Lesbarkeit), Brücken = beste Kante
     * je ungeordnetem Anker-Paar im Ring (GL-10-Typen), Verwandte = Rezepte
     * mit gemeinsamen Pairing-Ankern inkl. Andock-Anker-Ids, Vorschläge =
     * je Ring-Anker die stärksten Nachbarn AUSSERHALB des Rings.
     *
     * @return array{zentrum: ?array, anker: list<array>, kanten: list<array>, verwandte: list<array>, vorschlaege: list<array>}
     */
    public function aromaNetz(Team $team, int $recipeId, int $vorschlaegeProAnker = 0): array
    {
        $recipe = FoodAlchemistRecipe::visibleToTeam($team)->find($recipeId);
        if ($recipe === null) {
            return ['zentrum' => null, 'anker' => [], 'kanten' => [], 'verwandte' => [], 'vorschlaege' => []];
        }

        $kern = $this->recipeAnkers($recipeId);
        $pairing = DB::table('foodalchemist_recipe_pairings AS rp')
            ->join('foodalchemist_vocab_pairing_anchors AS a', 'a.id', '=', 'rp.anchor_id')
            ->where('rp.recipe_id', $recipeId)->whereNull('rp.deleted_at')
            ->whereNotIn('a.id', $kern->pluck('id'))
            ->orderByRaw("CASE rp.type WHEN 'erprobt' THEN 1 WHEN 'verbund' THEN 2 WHEN 'trinitas' THEN 3 ELSE 4 END")
            ->orderBy('a.slug')
            ->get(['a.id', 'a.slug', 'a.display_de']);

        $anker = $kern->map(fn ($a) => ['id' => (int) $a->id, 'slug' => $a->slug, 'display_de' => $a->display_de, 'kern' => true])
            ->concat($pairing->map(fn ($a) => ['id' => (int) $a->id, 'slug' => $a->slug, 'display_de' => $a->display_de, 'kern' => false]))
            ->unique('id')->take(28)->values();
        $ringIds = $anker->pluck('id')->all();

        // Brücken: beste Kante je ungeordnetem Paar (a < b dedupe)
        $kanten = [];
        foreach ($this->edgeBest($ringIds) as $a => $nachbarn) {
            foreach ($nachbarn as $b => [$w, $typ]) {
                if ($a < $b) {
                    $kanten[] = ['a' => (int) $a, 'b' => (int) $b, 'type' => $typ];
                }
            }
        }

        $verwandte = $this->recipesSharingPairings($team, $recipeId)->map(function (array $v) use ($ringIds) {
            $v['shared_anker_ids'] = DB::table('foodalchemist_recipe_pairings')
                ->where('recipe_id', $v['recipe_id'])->whereNull('deleted_at')
                ->whereIn('anchor_id', $ringIds)->distinct()->pluck('anchor_id')->map(fn ($i) => (int) $i)->all();
            $v['vk'] = (bool) FoodAlchemistRecipe::withoutGlobalScopes()->whereKey($v['recipe_id'])->value('is_sales_recipe');

            return $v;
        })->values()->all();

        // Vorschlags-Modus: je Anker top-n Nachbarn außerhalb des Rings (Typ-Priorität wie Ist)
        $vorschlaege = [];
        if ($vorschlaegeProAnker > 0) {
            $gesehen = array_flip($ringIds);
            foreach ($anker as $a) {
                $neu = $this->ankerNeighbors($a['slug'], null, 60)
                    ->filter(fn ($n) => ! isset($gesehen[(int) $n->id]))
                    ->take($vorschlaegeProAnker);
                foreach ($neu as $n) {
                    $vorschlaege[] = [
                        'anchor_id' => $a['id'], 'id' => (int) $n->id,
                        'slug' => $n->slug, 'display_de' => $n->display_de, 'type' => $n->type,
                    ];
                }
            }
        }

        return [
            'zentrum' => ['id' => $recipe->id, 'name' => $recipe->name],
            'anker' => $anker->all(),
            'kanten' => $kanten,
            'verwandte' => $verwandte,
            'vorschlaege' => $vorschlaege,
        ];
    }

    // ── Geschmacks-Vektoren & Zubereitungs-Deltas (read-only, 2026-07-11) ─
    // Quelle: anchor_taste_vectors (anker-level, 7 Achsen) + vocab_process_sensory_deltas
    // (Zubereitungs-Deltas). Aus der chemie_db-Migration; speisen Kontrast/Balance + Prep.

    /** Geschmacks-Vektor eines Ankers (7 Achsen), oder null wenn keiner. */
    public function anchorTasteVector(int $anchorId): ?array
    {
        $row = DB::table('foodalchemist_anchor_taste_vectors')->where('anchor_id', $anchorId)->first();
        if ($row === null) {
            return null;
        }
        $out = [];
        foreach (self::TASTE_ACHSEN as $a) {
            $out[$a] = (float) $row->$a;
        }

        return $out;
    }

    /** Gemittelter Geschmacks-Vektor über mehrere Anker (nur die mit Vektor); leere Achsen = 0. */
    private function aggregatedTaste(array $anchorIds): array
    {
        $out = array_fill_keys(self::TASTE_ACHSEN, 0.0);
        $anchorIds = array_values(array_unique(array_filter($anchorIds)));
        if ($anchorIds === []) {
            return $out;
        }
        $rows = DB::table('foodalchemist_anchor_taste_vectors')->whereIn('anchor_id', $anchorIds)->get();
        if ($rows->isEmpty()) {
            return $out;
        }
        foreach ($rows as $r) {
            foreach (self::TASTE_ACHSEN as $a) {
                $out[$a] += (float) $r->$a;
            }
        }
        foreach (self::TASTE_ACHSEN as $a) {
            $out[$a] = round($out[$a] / $rows->count(), 3);
        }

        return $out;
    }

    /**
     * PreparedForm: effektiver Geschmack = Basis-Anker-Vektor ⊕ Prozess-Delta
     * (vocab_process_sensory_deltas), on-demand, geklemmt auf [0,1]. Kein N×M-Speicher.
     * Null wenn der Anker keinen Basis-Vektor hat; unbekannte Zubereitung ⇒ Basis unverändert.
     */
    public function preparedTaste(int $anchorId, string $prepSlug): ?array
    {
        $basis = $this->anchorTasteVector($anchorId);
        if ($basis === null) {
            return null;
        }
        $delta = DB::table('foodalchemist_vocab_process_sensory_deltas')->where('anchor_slug', $prepSlug)->first();
        if ($delta === null) {
            return $basis;
        }
        $spalte = ['suess' => 'd_suess', 'salzig' => 'd_salzig', 'sauer' => 'd_sauer', 'bitter' => 'd_bitter',
            'umami' => 'd_umami', 'fettig' => 'd_fettig', 'scharf' => 'd_scharf'];
        $out = [];
        foreach (self::TASTE_ACHSEN as $a) {
            $out[$a] = round(max(0.0, min(1.0, $basis[$a] + (float) $delta->{$spalte[$a]})), 3);
        }

        return $out;
    }

    // ── Zustands-abhängiges Pairing (Ebene 2, 2026-07-11) ────────────────
    // Spec §3 Ebene 2: eine Zubereitung verschiebt das Aromaprofil KONSTANT
    // (geröstet → +roasted/nutty/caramel). PreparedForm-Vektor = unit(Basis-14-Typ)
    // ⊕ scale·prep_aroma_delta; Pairing wird auf DEM verschobenen Vektor neu
    // gerechnet (Kosinus) → geröstete Mandel paart anders als rohe. On-demand.
    // Grenze: nur Anker mit ingredient_aroma_vector; Preps ohne Aroma-Delta → [].

    private const AROMA_TYPES = ['fruity', 'citrus', 'floral', 'green', 'herbal', 'vegetable', 'caramel',
        'roasted', 'nutty', 'woody', 'spicy', 'cheesy', 'animal', 'chemical'];

    private const STATE_SCALE = 0.5;

    /** Basis-14-Typ-Aromavektor eines Ankers (via anchor_ingredient_map → ingredient_aroma_vector), oder null. */
    private function anchorAromaVector(int $anchorId): ?array
    {
        $iid = DB::table('foodalchemist_anchor_ingredient_map')->where('anchor_id', $anchorId)->value('ingredient_id');
        if ($iid === null) {
            return null;
        }
        $row = DB::table('foodalchemist_ingredient_aroma_vector')->where('ingredient_id', $iid)->first();
        if ($row === null) {
            return null;
        }
        $v = [];
        foreach (self::AROMA_TYPES as $t) {
            $v[] = (float) ($row->$t ?? 0.0);
        }

        return $v;
    }

    /** Alle Anker mit Aromavektor: [anchor_id => ['slug'=>..., 'vec'=>[14]]]. Für Kandidaten-Scoring. */
    private function allAnchorAromaVectors(): array
    {
        $rows = DB::table('foodalchemist_anchor_ingredient_map AS m')
            ->join('foodalchemist_ingredient_aroma_vector AS v', 'v.ingredient_id', '=', 'm.ingredient_id')
            ->join('foodalchemist_vocab_pairing_anchors AS a', 'a.id', '=', 'm.anchor_id')
            ->get(array_merge(['m.anchor_id', 'a.slug', 'a.display_de'], array_map(fn ($t) => 'v.'.$t, self::AROMA_TYPES)));
        $out = [];
        foreach ($rows as $r) {
            $vec = [];
            foreach (self::AROMA_TYPES as $t) {
                $vec[] = (float) ($r->$t ?? 0.0);
            }
            $out[(int) $r->anchor_id] = ['slug' => $r->slug, 'display_de' => $r->display_de, 'vec' => $vec];
        }

        return $out;
    }

    private function vecUnit(array $v): array
    {
        $n = sqrt(array_sum(array_map(fn ($x) => $x * $x, $v)));

        return $n > 0 ? array_map(fn ($x) => $x / $n, $v) : $v;
    }

    private function vecCos(array $a, array $b): float
    {
        $d = 0.0;
        $na = 0.0;
        $nb = 0.0;
        foreach ($a as $i => $x) {
            $d += $x * $b[$i];
            $na += $x * $x;
            $nb += $b[$i] * $b[$i];
        }

        return ($na > 0 && $nb > 0) ? $d / (sqrt($na) * sqrt($nb)) : 0.0;
    }

    /**
     * Zustands-abhängige Pairing-Partner der Form (Anker ⊕ Zubereitung).
     * @return list<array{anchor_id:int, slug:string, display_de:?string, score:float}>
     */
    public function statePairingNeighbors(int $anchorId, string $prepSlug, int $limit = 12): array
    {
        $base = $this->anchorAromaVector($anchorId);
        if ($base === null) {
            return [];  // Anker ohne Aromavektor → kein Zustands-Pairing
        }
        // Prep-Aroma-Delta (leer bei Preps mit nur Geschmacks-Delta, z.B. getrocknet)
        $delta = array_fill_keys(self::AROMA_TYPES, 0.0);
        $rows = DB::table('foodalchemist_prep_aroma_delta AS d')
            ->join('foodalchemist_preparations AS p', 'p.id', '=', 'd.prep_id')
            ->join('foodalchemist_aroma_types AS at', 'at.id', '=', 'd.aroma_type_id')
            ->where('p.slug', $prepSlug)->get(['at.type_key', 'd.delta']);
        if ($rows->isEmpty()) {
            return [];  // kein Aroma-Shift für diese Zubereitung
        }
        foreach ($rows as $r) {
            $delta[$r->type_key] = (float) $r->delta;
        }
        $u = $this->vecUnit($base);
        $prepared = [];
        foreach (self::AROMA_TYPES as $i => $t) {
            $prepared[$i] = $u[$i] + self::STATE_SCALE * $delta[$t];
        }
        $scored = [];
        foreach ($this->allAnchorAromaVectors() as $cid => $c) {
            if ($cid === $anchorId) {
                continue;
            }
            $scored[] = ['anchor_id' => $cid, 'slug' => $c['slug'], 'display_de' => $c['display_de'],
                'score' => round($this->vecCos($prepared, $c['vec']), 4)];
        }
        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($scored, 0, $limit);
    }

    // ── R6.8: Aroma-treue Substitution (read-only, 2026-07-19) ───────────
    // Ersatz, der den GESCHMACK erhält — nicht nur den Preis senkt. Zwei vorhandene
    // Basen kombiniert (kein Neubau der Mathematik): (1) Anker-Kanten-Überlappung —
    // welche der Aroma-Brücken des Quell-GP trägt/erreicht der Kandidat (edgeBest über
    // die gpAnkers beider Seiten); (2) Aroma-Vektor-Cosinus (14-Typ) der aggregierten
    // GP-Aromaprofile. Bewusste Abweichung von der Spec-Notation »× Cosinus«: ein hartes
    // Produkt würde das Ranking überall dort auf 0 kollabieren, wo Aroma-Vektoren fehlen
    // (sie sind dünn — nur Anker mit ingredient_aroma_vector). Darum GRACEFUL gewichtete
    // Mischung: nur Kanten wenn kein Vektor da ist, sonst 0.6·Kanten + 0.4·Cosinus. Manuell
    // kuratierte Äquivalente (ComponentEquivalentService) werden geboostet (Inv. 3: manual
    // gewinnt). Der eigentliche Tausch bleibt tauscheZutat (Allergen-/swap_locked-Guards dort).

    private const SUBST_W_EDGE = 0.6;

    private const SUBST_W_AROMA = 0.4;

    /**
     * Aggregierter 14-Typ-Aromavektor eines GP (Mittel über seine kern-Anker mit Vektor),
     * aus einer vorgeladenen anchor_id→vec-Map. Null, wenn kein kern-Anker einen Vektor hat.
     *
     * @param  list<int>  $anchorIds
     * @param  array<int, array{vec: list<float>}>  $aromaByAnchor
     */
    private function gpAromaVectorFromMap(array $anchorIds, array $aromaByAnchor): ?array
    {
        $sum = null;
        $n = 0;
        foreach ($anchorIds as $aid) {
            if (! isset($aromaByAnchor[$aid])) {
                continue;
            }
            $vec = $aromaByAnchor[$aid]['vec'];
            $sum ??= array_fill(0, count(self::AROMA_TYPES), 0.0);
            foreach ($vec as $i => $x) {
                $sum[$i] += $x;
            }
            $n++;
        }
        if ($sum === null || $n === 0) {
            return null;
        }

        return array_map(fn ($x) => $x / $n, $sum);
    }

    /** Listen-EK (indikativ) der Lead-LA eines GP — aktive Preiszeile (valid_to NULL). Null wenn keine. */
    private function gpLeadListenEk(?int $leadLaId): ?float
    {
        if ($leadLaId === null) {
            return null;
        }
        $p = DB::table('foodalchemist_prices')
            ->where('supplier_item_id', $leadLaId)->whereNull('valid_to')->whereNull('deleted_at')
            ->orderByDesc('id')->value('price');

        return $p !== null ? (float) $p : null;
    }

    /**
     * R6.8 — Aroma-treue Ersatz-GPs für einen Quell-GP, gerankt nach erhaltenem Geschmack.
     * Optionaler Rezept-Kontext (recipe_ingredient_id) liefert zusätzlich das Kohäsions-Delta
     * fürs Gesamtgericht + swap_locked-Status. Read-only.
     *
     * @return array{source: ?array, context: array, candidates: list<array>}
     */
    public function aromaTrueSubstitutes(Team $team, int $sourceGpId, int $limit = 8, ?int $recipeIngredientId = null): array
    {
        $context = ['recipe_ingredient_id' => null, 'recipe_id' => null, 'swap_locked' => false, 'base_cohesion' => null];
        $recipe = null;

        // Rezept-Kontext: Zutat auf Sichtbarkeit prüfen, gp_id daraus ableiten (überschreibt Param).
        if ($recipeIngredientId !== null) {
            $zutat = \Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient::find($recipeIngredientId);
            if ($zutat !== null
                && FoodAlchemistRecipe::visibleToTeam($team)->whereKey($zutat->recipe_id)->exists()) {
                if ($zutat->gp_id !== null) {
                    $sourceGpId = (int) $zutat->gp_id;
                }
                $recipe = FoodAlchemistRecipe::visibleToTeam($team)->find($zutat->recipe_id);
                $context['recipe_ingredient_id'] = (int) $zutat->id;
                $context['recipe_id'] = (int) $zutat->recipe_id;
                $context['swap_locked'] = (bool) $zutat->swap_locked;
            }
        }

        $sourceGp = FoodAlchemistGp::visibleToTeam($team)->find($sourceGpId);
        if ($sourceGp === null) {
            return ['source' => null, 'context' => $context, 'candidates' => []];
        }

        $sourceAnker = $this->gpAnkers($sourceGpId);
        $sourceAnkerIds = $sourceAnker->pluck('id')->map(fn ($i) => (int) $i)->all();
        $sourceSlugs = [];
        foreach ($sourceAnker as $a) {
            $sourceSlugs[(int) $a->id] = $a->display_de ?: $a->slug;
        }

        // ── Kandidaten-Pool: Aroma-Geschwister (teilen ≥1 Quell-Anker) ∪ gleiche Warengruppe
        //    ∪ manuelle Äquivalente. Konservativ begrenzt; Rohware/Derivat/Platzhalter raus.
        $poolIds = [];
        if ($sourceAnkerIds !== []) {
            foreach (DB::table('foodalchemist_gp_anchor_mappings')
                ->whereIn('anchor_id', $sourceAnkerIds)->where('role', 'kern')->whereNull('deleted_at')
                ->where('gp_id', '!=', $sourceGpId)->distinct()->pluck('gp_id') as $gid) {
                $poolIds[(int) $gid] = true;
            }
        }
        if ($sourceGp->commodity_group_code !== null) {
            foreach (FoodAlchemistGp::visibleToTeam($team)
                ->where('commodity_group_code', $sourceGp->commodity_group_code)
                ->where('id', '!=', $sourceGpId)->limit(300)->pluck('id') as $gid) {
                $poolIds[(int) $gid] = true;
            }
        }
        $manuelleIds = [];
        foreach (app(ComponentEquivalentService::class)->fuer($team, 'gp', $sourceGpId) as $eq) {
            if ($eq->gegen_kind === 'gp' && $eq->gegen_id !== null) {
                $poolIds[(int) $eq->gegen_id] = true;
                $manuelleIds[(int) $eq->gegen_id] = true;
            }
        }
        unset($poolIds[$sourceGpId]);

        // Sichtbar + keine Derivate/Platzhalter; manuelle Äquivalente bleiben immer drin (kuratiert).
        $poolModels = FoodAlchemistGp::visibleToTeam($team)->whereIn('id', array_keys($poolIds))
            ->where(fn ($q) => $q
                ->where(fn ($w) => $w->where('is_derivat', false)->where('is_platzhalter', false))
                ->orWhereIn('id', array_keys($manuelleIds)))
            ->get(['id', 'name', 'lead_la_supplier_item_id']);
        if ($poolModels->isEmpty()) {
            return ['source' => $this->substSourceOut($sourceGp, $sourceSlugs), 'context' => $context, 'candidates' => []];
        }
        $poolIdList = $poolModels->pluck('id')->map(fn ($i) => (int) $i)->all();

        // Kandidaten-Anker in EINER Query gruppieren.
        $candAnkerByGp = [];
        foreach (DB::table('foodalchemist_gp_anchor_mappings AS m')
            ->join('foodalchemist_vocab_pairing_anchors AS a', 'a.id', '=', 'm.anchor_id')
            ->whereIn('m.gp_id', $poolIdList)->where('m.role', 'kern')->whereNull('m.deleted_at')
            ->get(['m.gp_id', 'a.id', 'a.slug', 'a.display_de']) as $row) {
            $candAnkerByGp[(int) $row->gp_id][] = ['id' => (int) $row->id, 'label' => $row->display_de ?: $row->slug];
        }

        // Aroma-Vektoren (ein Query) + Kanten über die Anker-Union (ein Query).
        $aromaByAnchor = $this->allAnchorAromaVectors();
        $sourceAroma = $this->gpAromaVectorFromMap($sourceAnkerIds, $aromaByAnchor);
        $unionAnker = $sourceAnkerIds;
        foreach ($candAnkerByGp as $rows) {
            foreach ($rows as $r) {
                $unionAnker[] = $r['id'];
            }
        }
        $kanten = $this->edgeBest(array_values(array_unique($unionAnker)));

        // ── Scoring je Kandidat ──────────────────────────────────────────
        $scored = [];
        $nSource = max(1, count($sourceAnkerIds));
        foreach ($poolModels as $cand) {
            $cid = (int) $cand->id;
            $candAnker = $candAnkerByGp[$cid] ?? [];
            $candIds = array_map(fn ($r) => $r['id'], $candAnker);

            $erhalten = [];
            $verloren = [];
            foreach ($sourceAnkerIds as $sa) {
                $keep = in_array($sa, $candIds, true);
                if (! $keep) {
                    foreach ($candIds as $ca) {
                        if (isset($kanten[$sa][$ca]) && $kanten[$sa][$ca][0] > 0) {
                            $keep = true;
                            break;
                        }
                    }
                }
                $label = $sourceSlugs[$sa] ?? (string) $sa;
                if ($keep) {
                    $erhalten[] = $label;
                } else {
                    $verloren[] = $label;
                }
            }
            $edgeOverlap = round(count($erhalten) / $nSource, 4);

            $candAroma = $this->gpAromaVectorFromMap($candIds, $aromaByAnchor);
            $aromaCos = ($sourceAroma !== null && $candAroma !== null)
                ? round($this->vecCos($sourceAroma, $candAroma), 4) : null;

            $flavorScore = $aromaCos !== null
                ? round(self::SUBST_W_EDGE * $edgeOverlap + self::SUBST_W_AROMA * $aromaCos, 4)
                : $edgeOverlap;

            $isManual = isset($manuelleIds[$cid]);
            // Rein-lexikalische Warengruppen-Nachbarn ohne jede Aroma-Beziehung fliegen raus
            // (kein Ersatz-Vorschlag »ins Blaue«) — manuelle Äquivalente bleiben immer.
            if (! $isManual && $flavorScore <= 0.0) {
                continue;
            }

            $scored[] = [
                'gp_id' => $cid,
                'name' => $cand->name,
                'lead_la_supplier_item_id' => $cand->lead_la_supplier_item_id !== null ? (int) $cand->lead_la_supplier_item_id : null,
                'flavor_score' => $flavorScore,
                'edge_overlap' => $edgeOverlap,
                'aroma_cos' => $aromaCos,
                'erhaltene_bruecken' => $erhalten,
                'verlorene_bruecken' => $verloren,
                'is_manual_equiv' => $isManual,
                'candidate_anchor_ids' => $candIds,
            ];
        }

        // Sortierung: kuratiert zuerst, dann Aroma-Treue, dann meiste erhaltene Brücken, dann Name.
        usort($scored, fn ($a, $b) => [
            $b['is_manual_equiv'], $b['flavor_score'], count($b['erhaltene_bruecken']), $a['name'],
        ] <=> [
            $a['is_manual_equiv'], $a['flavor_score'], count($a['erhaltene_bruecken']), $b['name'],
        ]);
        $scored = array_slice($scored, 0, max(1, $limit));

        // ── Anreicherung nur der Top-N (teure Schritte: Allergene, Preis, Kohäsion) ──
        $agg = app(GpAggregateService::class);
        $sourceAllergene = $agg->allergene($sourceGp);
        $sourceEk = $this->gpLeadListenEk($sourceGp->lead_la_supplier_item_id !== null ? (int) $sourceGp->lead_la_supplier_item_id : null);

        if ($recipe !== null) {
            $context['base_cohesion'] = $this->recipeCohesion($recipe)['score'];
        }

        $candidates = [];
        foreach ($scored as $c) {
            // Voll-Model (poolModels trägt nur id/name/lead — Allergen-Spalten fehlen dort).
            $candGp = FoodAlchemistGp::find($c['gp_id']);
            if ($candGp === null) {
                continue;
            }

            // Allergen-Neuberechnung VOR Tausch: was der Kandidat NEU/STÄRKER einbringt.
            $candAllergene = $agg->allergene($candGp);
            $allergenWarn = [];
            foreach (FoodAlchemistGp::ALLERGEN_FIELDS as $feld) {
                $cv = $candAllergene[$feld]['value'] ?? null;
                $sv = $sourceAllergene[$feld]['value'] ?? null;
                if ($cv instanceof \Platform\FoodAlchemist\Enums\AllergenValue
                    && in_array($cv, [\Platform\FoodAlchemist\Enums\AllergenValue::Enthalten, \Platform\FoodAlchemist\Enums\AllergenValue::Spuren], true)
                    && ($sv === null || $cv->rank() > $sv->rank())) {
                    $allergenWarn[$feld] = $cv->value;
                }
            }

            // Cost-Achse (R6.3): indikativer Listen-EK der jeweiligen Lead-LA (NICHT mengennormalisiert).
            $candEk = $this->gpLeadListenEk($c['lead_la_supplier_item_id']);
            $cost = [
                'source_listen_ek' => $sourceEk,
                'candidate_listen_ek' => $candEk,
                'guenstiger' => ($sourceEk !== null && $candEk !== null) ? ($candEk < $sourceEk) : null,
                'hinweis' => 'indikativ: Listen-EK der Lead-LA, nicht mengennormalisiert',
            ];

            // Kohäsions-Delta fürs Gesamtgericht (nur mit Rezept-Kontext).
            $kohaesionsDelta = null;
            if ($recipe !== null && $context['base_cohesion'] !== null) {
                $kohaesionsDelta = $this->substCohesionDelta(
                    $recipe, $sourceGp->name, $sourceAnkerIds, $c['candidate_anchor_ids'], $context['base_cohesion']
                );
            }

            $candidates[] = [
                'gp_id' => $c['gp_id'],
                'name' => $c['name'],
                'flavor_score' => $c['flavor_score'],
                'edge_overlap' => $c['edge_overlap'],
                'aroma_cos' => $c['aroma_cos'],
                'erhaltene_bruecken' => $c['erhaltene_bruecken'],
                'verlorene_bruecken' => $c['verlorene_bruecken'],
                'kohaesions_delta' => $kohaesionsDelta,
                'is_manual_equiv' => $c['is_manual_equiv'],
                'allergen_warnungen' => $allergenWarn,
                'cost' => $cost,
                'evidenz' => [
                    'tier' => $c['is_manual_equiv'] ? 'kuratiert' : 'abgeleitet',
                    'basis' => $c['is_manual_equiv'] ? 'manuelles Äquivalent' : 'Anker-Kanten' . ($c['aroma_cos'] !== null ? ' + Aroma-Vektor' : ''),
                    'aroma_vektor' => $c['aroma_cos'] !== null,
                ],
            ];
        }

        return [
            'source' => $this->substSourceOut($sourceGp, $sourceSlugs),
            'context' => $context,
            'candidates' => $candidates,
        ];
    }

    /** @param array<int, string> $sourceSlugs */
    private function substSourceOut(FoodAlchemistGp $gp, array $sourceSlugs): array
    {
        return ['gp_id' => (int) $gp->id, 'name' => $gp->name, 'anker' => array_values($sourceSlugs)];
    }

    /**
     * Kohäsions-Delta: Teller-Score MIT Kandidat statt Quell-Komponente minus Basis-Score.
     * Findet die zu ersetzende Komponente über den GP-Namen (Fallback: geteilter kern-Anker),
     * tauscht deren Anker gegen die des Kandidaten, rechnet cohesionFor neu. Null wenn nicht gefunden.
     *
     * @param  list<int>  $sourceAnkerIds
     * @param  list<int>  $candAnkerIds
     */
    private function substCohesionDelta(FoodAlchemistRecipe $recipe, string $sourceName, array $sourceAnkerIds, array $candAnkerIds, int $baseScore): ?int
    {
        $komponenten = $this->resolveRecipeAnchors($recipe);
        $trefferIdx = null;
        foreach ($komponenten as $i => $k) {
            if ($k['label'] === $sourceName) {
                $trefferIdx = $i;
                break;
            }
        }
        if ($trefferIdx === null) {
            foreach ($komponenten as $i => $k) {
                if ($k['kern'] !== null && in_array($k['kern'], $sourceAnkerIds, true)) {
                    $trefferIdx = $i;
                    break;
                }
            }
        }
        if ($trefferIdx === null || $candAnkerIds === []) {
            return null;
        }
        $komponenten[$trefferIdx]['kern'] = $candAnkerIds[0];
        $komponenten[$trefferIdx]['prozess'] = array_slice($candAnkerIds, 1);

        return $this->cohesionFor($komponenten)['score'] - $baseScore;
    }

    // ── intern ───────────────────────────────────────────────────────────

    /** Beste Kante je ungeordnetem Anker-Paar: [a][b] => [gewicht, typ]. */
    private function edgeBest(array $ankerIds): array
    {
        if ($ankerIds === []) {
            return [];
        }
        $out = [];
        foreach (DB::table('foodalchemist_pairing_anchor_edges')
            ->whereIn('anchor_a_id', $ankerIds)->whereIn('anchor_b_id', $ankerIds)
            ->get(['anchor_a_id', 'anchor_b_id', 'type', 'weight']) as $kante) {
            // computed-Kante trägt ihr eigenes (gradiertes) Gewicht; kuratiert (weight NULL) = typ-getrieben.
            $w = $kante->weight !== null ? (float) $kante->weight : (self::GEWICHTE[$kante->type] ?? 0.5);
            foreach ([[$kante->anchor_a_id, $kante->anchor_b_id], [$kante->anchor_b_id, $kante->anchor_a_id]] as [$a, $b]) {
                if (! isset($out[$a][$b]) || $out[$a][$b][0] < $w) {
                    $out[$a][$b] = [$w, $kante->type];
                }
            }
        }

        return $out;
    }

    /**
     * MCP-Discovery (Phase K): Pairing-Partner für einen Zutat-NAMEN oder
     * Anker-Slug. Auflösung: exakter/normalisierter Slug → resolveByName
     * (Anker-Index). Liefert den aufgelösten Anker mit, damit der Client
     * sieht, worauf gematcht wurde.
     *
     * @return array{anker: ?array{id: int, slug: string, display_de: ?string}, partner: list<object>}
     */
    public function neighborsForName(string $name, ?string $typ = null, int $limit = 30): array
    {
        $anker = DB::table('foodalchemist_vocab_pairing_anchors')
            ->whereIn('slug', array_unique([trim($name), $this->normalizeAnkerSlug($name)]))
            ->first(['id', 'slug', 'display_de']);

        if ($anker === null && ($ankerId = $this->resolveByName($name)) !== null) {
            $anker = DB::table('foodalchemist_vocab_pairing_anchors')
                ->where('id', $ankerId)->first(['id', 'slug', 'display_de']);
        }
        if ($anker === null) {
            return ['anker' => null, 'partner' => []];
        }

        return [
            'anker' => ['id' => (int) $anker->id, 'slug' => $anker->slug, 'display_de' => $anker->display_de],
            'partner' => $this->ankerNeighbors($anker->slug, $typ, $limit)->all(),
        ];
    }

    // ── R6.11 · S1: Hypothesen-Modus (read-only, 2026-07-19) ─────────────
    // Offensive Nutzung des Chemie-/Pairing-Fundaments: „paar mir X ungewöhnlich".
    // Rankt Kandidaten-Anker nach GETEILTEN Aroma-Compound-Klassen (Ahn-Sinn:
    // ingredient_key_component) + geteilten Molekül-Klassen (molecules.chem_class),
    // je mit Mechanismus-Text + Evidenz-Stufe. Fällt graceful auf Aroma-Vektor-Cosinus
    // zurück, wenn die Compound-Daten dünn sind. Ergebnis ist IMMER als Hypothese (T3)
    // markiert — nie als Fakt (Inv./Nicht-Ziel §6). Keine KI nötig; das optionale
    // Narrativ ist ein Folge-Add (S1-Rest), der deterministische Kern trägt für sich.

    /**
     * Geteilte Compound-Klassen zweier Anker: Aroma-key_components (Schnitt) +
     * Molekül-chem_class (Schnitt). Graceful leer, wenn ein Anker keine Zutat/kein
     * Profil hat. Basis für hypothesizeFor + den Mechanismus-Text.
     *
     * @return array{key_components: list<array{key:?string, family:?string, aroma_type:?string}>, chem_classes: list<string>, n_key_components:int, n_chem_classes:int}
     */
    public function sharedCompoundClasses(int $anchorA, int $anchorB): array
    {
        $leer = ['key_components' => [], 'chem_classes' => [], 'n_key_components' => 0, 'n_chem_classes' => 0];
        $ingA = $this->anchorIngredientId($anchorA);
        $ingB = $this->anchorIngredientId($anchorB);
        if ($ingA === null || $ingB === null) {
            return $leer;
        }

        $compA = $this->ingredientKeyComponentIds($ingA);
        $compB = $this->ingredientKeyComponentIds($ingB);
        $sharedComp = array_values(array_intersect($compA, $compB));

        $classA = $this->ingredientChemClasses($ingA);
        $classB = $this->ingredientChemClasses($ingB);
        $sharedClass = array_values(array_intersect($classA, $classB));

        $keyComponents = [];
        if ($sharedComp !== []) {
            foreach (DB::table('foodalchemist_key_components')->whereIn('id', $sharedComp)
                ->get(['key', 'family', 'aroma_type']) as $kc) {
                $keyComponents[] = ['key' => $kc->key, 'family' => $kc->family, 'aroma_type' => $kc->aroma_type];
            }
        }

        return [
            'key_components' => $keyComponents,
            'chem_classes' => $sharedClass,
            'n_key_components' => count($sharedComp),
            'n_chem_classes' => count($sharedClass),
        ];
    }

    /**
     * Hypothesen-Modus: für eine Quelle (GP oder Anker) Kandidaten-Anker nach
     * geteilten Compound-Klassen ranken. Jede Zeile trägt Mechanismus + Evidenz-Stufe
     * (T3 = Hypothese) + ob die Paarung im Graphen schon ETABLIERT ist (dann kein
     * „ungewöhnlicher" Vorschlag, sondern bekannt).
     *
     * @param  array{gp?:int, anchor?:int}  $source
     * @return array{source:array, methode:string, hypothesen:list<array>, hinweis:string}
     */
    public function hypothesizeFor(array $source, int $limit = 12): array
    {
        $limit = max(1, min(50, $limit));

        // 1) Quell-Anker auflösen (GP → kern-Anker; oder direkter Anker).
        $resolved = $this->resolveSource($source);
        if ($resolved === null) {
            return ['source' => [], 'methode' => 'none', 'hypothesen' => [], 'hinweis' => 'Keine Quelle (gp/anchor) angegeben.'];
        }
        $anchorIds = $resolved['anchorIds'];
        $srcMeta = $resolved['meta'];
        if ($anchorIds === []) {
            return ['source' => $srcMeta, 'methode' => 'none', 'hypothesen' => [],
                'hinweis' => 'Quelle hat keine kern-Anker — kein Hypothesen-Ranking möglich.'];
        }

        // 2) Quell-Compound-Profil (Aroma-key_components + Molekül-chem_class) aggregiert.
        $srcComp = [];
        $srcClass = [];
        $srcIngredientIds = [];
        foreach ($anchorIds as $aid) {
            $iid = $this->anchorIngredientId($aid);
            if ($iid === null) {
                continue;
            }
            $srcIngredientIds[] = $iid;
            $srcComp = array_merge($srcComp, $this->ingredientKeyComponentIds($iid));
            $srcClass = array_merge($srcClass, $this->ingredientChemClasses($iid));
        }
        $srcComp = array_values(array_unique($srcComp));
        $srcClass = array_values(array_unique($srcClass));
        $srcMeta['anchor_ids'] = $anchorIds;
        $srcMeta['n_key_components'] = count($srcComp);
        $srcMeta['n_chem_classes'] = count($srcClass);

        // 3) Etablierte Kanten der Quelle (für die „ungewöhnlich?"-Markierung).
        $edges = [];
        foreach (DB::table('foodalchemist_pairing_anchor_edges')
            ->whereIn('anchor_a_id', $anchorIds)->get(['anchor_b_id', 'type']) as $e) {
            $edges[(int) $e->anchor_b_id] = $e->type;   // ein Typ je Kandidat reicht für die Markierung
        }

        // 4) Compound-Klassen-Ranking (primär); Fallback Aroma-Vektor-Cosinus.
        if ($srcComp !== []) {
            $kompMap = $this->keyComponentsByAnchor();          // anchor_id => [component_id,...]
            $scored = [];
            foreach ($kompMap as $cid => $comps) {
                if (in_array($cid, $anchorIds, true)) {
                    continue;
                }
                $shared = array_intersect($srcComp, $comps);
                if ($shared === []) {
                    continue;
                }
                $scored[$cid] = count($shared);
            }
            arsort($scored);
            $methode = 'compound_class';
            $kandidaten = array_slice(array_keys($scored), 0, $limit, true);
        } else {
            // Fallback: kein Compound-Profil → Aroma-Vektor-Cosinus über die Quell-Anker.
            $methode = 'aroma_vector_fallback';
            $kandidaten = $this->aromaCosineCandidates($anchorIds, $limit);
            $scored = $kandidaten;                              // [anchor_id => cosine]
            $kandidaten = array_keys($kandidaten);
        }
        if ($kandidaten === []) {
            return ['source' => $srcMeta, 'methode' => $methode, 'hypothesen' => [],
                'hinweis' => 'Keine Kandidaten mit geteilten Klassen gefunden.'];
        }

        // 5) Kandidaten anreichern: Namen, Mechanismus, Novität, Evidenz-Stufe.
        $names = DB::table('foodalchemist_vocab_pairing_anchors')->whereIn('id', $kandidaten)
            ->get(['id', 'slug', 'display_de'])->keyBy('id');
        $hypothesen = [];
        foreach ($kandidaten as $cid) {
            $meta = $names[$cid] ?? null;
            if ($meta === null) {
                continue;
            }
            // Mechanismus + geteilte Klassen: gegen den ERSTEN Quell-Anker (repräsentativ,
            // günstig); der Score bleibt der aggregierte Overlap oben.
            $shared = $this->sharedCompoundClasses($anchorIds[0], (int) $cid);
            $etabliert = isset($edges[(int) $cid]);
            $familien = array_values(array_unique(array_map(fn ($k) => $k['family'] ?? $k['key'] ?? '?', $shared['key_components'])));
            $klassenText = $familien !== []
                ? implode(', ', array_slice($familien, 0, 6))
                : ($methode === 'aroma_vector_fallback' ? 'ähnliches Aroma-Vektor-Profil (kein Compound-Profil)' : 'geteilte Klassen quell-übergreifend');
            $hypothesen[] = [
                'anchor_id' => (int) $cid,
                'slug' => $meta->slug,
                'display_de' => $meta->display_de,
                'score' => $methode === 'compound_class' ? (int) ($scored[$cid] ?? 0) : round((float) ($scored[$cid] ?? 0), 4),
                'geteilte_klassen' => $shared['key_components'],
                'n_geteilt' => $shared['n_key_components'],
                'geteilte_chem_klassen' => array_slice($shared['chem_classes'], 0, 8),
                'mechanismus' => 'Teilt: ' . $klassenText,
                'ist_etabliert' => $etabliert,
                'edge_typ' => $edges[(int) $cid] ?? null,
                'evidenz_tier' => 'T3',   // Hypothese (E1) — nie Fakt
            ];
        }

        return [
            'source' => $srcMeta,
            'methode' => $methode,
            'hypothesen' => $hypothesen,
            'hinweis' => 'Hypothesen — markierte Spekulation (Evidenz-Stufe T3), KEIN Fakt. '
                . 'Mechanismus = geteilte Aroma-/Molekül-Klassen aus den Daten (ingredient_key_component / molecules.chem_class). '
                . 'ist_etabliert=true ⇒ die Paarung ist im Graphen bereits bekannt (nicht „ungewöhnlich").',
        ];
    }

    // ── R6.11 · S4: Kontrast-Hypothesen (read-only, 2026-07-19) ──────────
    // Der zweite offensive Zug: „paar mir X über SPANNUNG statt Verwandtschaft".
    // Aroma-Harmonie (hypothesizeFor) findet nur geteilte Moleküle — Kontrast ist das
    // Gegenteil und für nicht-negative Aroma-Vektoren mathematisch unsichtbar. Darum
    // zwei geerdete Quellen: (1) die kuratierten `kontrast`-Kanten (T0, bewährt), (2)
    // generativ über den 7-Achsen-GESCHMACKS-Vektor entlang kulinarischer Gegensatz-
    // Paare (Fett↔Säure, Süß↔Bitter … = Lehrbuch/Buch-Kontrast-Layer, keine Erfindung).

    /**
     * Kulinarische Gegensatz-Paare (undirektional) über die 7 Geschmacks-Achsen.
     * Quelle: Foodpairing-Kontrast-Layer (Buch S.36) + Küchen-Grundlagen (Säure
     * schneidet Fett, Süße mildert Bitter/Schärfe, süß-salzig/süß-sauer-Spannung).
     *
     * @var list<array{0:string,1:string}>
     */
    private const GESCHMACK_GEGENSATZ = [
        ['fettig', 'sauer'], ['fettig', 'scharf'], ['suess', 'bitter'],
        ['suess', 'scharf'], ['suess', 'salzig'], ['suess', 'sauer'], ['umami', 'sauer'],
    ];

    /**
     * Kontrast-Hypothesen zu einer Quelle (GP/Anker): kuratierte kontrast-Kanten (T0)
     * + generative Kandidaten nach Geschmacks-Gegensatz (T3). Ergebnis ist markierte
     * Spekulation, nie Fakt.
     *
     * @param  array{gp?:int, anchor?:int}  $source
     * @return array{source:array, methode:string, kuratiert:list<array>, hypothesen:list<array>, hinweis:string}
     */
    public function contrastHypothesesFor(array $source, int $limit = 12): array
    {
        $limit = max(1, min(50, $limit));
        $resolved = $this->resolveSource($source);
        if ($resolved === null) {
            return ['source' => [], 'methode' => 'none', 'kuratiert' => [], 'hypothesen' => [], 'hinweis' => 'Keine Quelle (gp/anchor) angegeben.'];
        }
        $anchorIds = $resolved['anchorIds'];
        $srcMeta = $resolved['meta'];
        if ($anchorIds === []) {
            return ['source' => $srcMeta, 'methode' => 'none', 'kuratiert' => [], 'hypothesen' => [],
                'hinweis' => 'Quelle hat keine kern-Anker — kein Kontrast-Ranking möglich.'];
        }

        // (1) Kuratierte kontrast-Kanten — bewährte Gegensätze, bisher offensiv ungenutzt.
        $kuratiert = [];
        $kontrastPartner = [];
        foreach (DB::table('foodalchemist_pairing_anchor_edges AS e')
            ->join('foodalchemist_vocab_pairing_anchors AS a', 'a.id', '=', 'e.anchor_b_id')
            ->whereIn('e.anchor_a_id', $anchorIds)->where('e.type', 'kontrast')
            ->whereNotIn('e.anchor_b_id', $anchorIds)->whereNull('a.deleted_at')
            ->distinct()->get(['a.id', 'a.slug', 'a.display_de']) as $r) {
            $kontrastPartner[(int) $r->id] = true;
            $kuratiert[] = ['anchor_id' => (int) $r->id, 'slug' => $r->slug, 'display_de' => $r->display_de,
                'typ' => 'kontrast', 'evidenz_tier' => 'T0'];
        }

        // (2) Generativ über Geschmacks-Gegensatz.
        $srcTaste = $this->aggregatedTaste($anchorIds);
        $methode = array_sum($srcTaste) > 0 ? 'kontrast_geschmack' : 'nur_kuratiert';
        $hypothesen = [];
        if ($methode === 'kontrast_geschmack') {
            $rows = DB::table('foodalchemist_anchor_taste_vectors AS t')
                ->join('foodalchemist_vocab_pairing_anchors AS a', 'a.id', '=', 't.anchor_id')
                ->whereNotIn('t.anchor_id', $anchorIds)->whereNull('a.deleted_at')
                ->get(array_merge(['t.anchor_id', 'a.slug', 'a.display_de'], self::TASTE_ACHSEN));
            $scored = [];
            foreach ($rows as $r) {
                if (in_array($r->slug, self::NICHT_ZUTAT_ANKER, true)) {
                    continue;   // Prozess/Neutral sind keine Kontrast-Partner
                }
                $cand = [];
                foreach (self::TASTE_ACHSEN as $ax) {
                    $cand[$ax] = (float) $r->$ax;
                }
                [$score, $achsen] = $this->contrastScore($srcTaste, $cand);
                if ($score <= 0) {
                    continue;
                }
                $scored[] = ['anchor_id' => (int) $r->anchor_id, 'slug' => $r->slug, 'display_de' => $r->display_de,
                    'score' => round($score, 3), 'opponierende_achsen' => $achsen,
                    'mechanismus' => 'Spannung über: ' . implode(', ', array_slice($achsen, 0, 4)),
                    'ist_etabliert' => isset($kontrastPartner[(int) $r->anchor_id]),
                    'evidenz_tier' => 'T3'];
            }
            usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);
            $hypothesen = array_slice($scored, 0, $limit);
        }

        $srcMeta['geschmack'] = $srcTaste;

        return [
            'source' => $srcMeta,
            'methode' => $methode,
            'kuratiert' => $kuratiert,
            'hypothesen' => $hypothesen,
            'hinweis' => 'Kontrast = Paarung durch SPANNUNG (Gegensatz), nicht Verwandtschaft. '
                . 'kuratiert = bewährte kontrast-Kanten (T0); hypothesen = generative Geschmacks-Gegensätze (T3, markierte Spekulation). '
                . ($methode === 'nur_kuratiert' ? 'Quelle ohne Geschmacks-Vektor → nur kuratierte Kontraste.' : ''),
        ];
    }

    /**
     * Kontrast-Score zweier 7-Achsen-Geschmacksvektoren entlang der Gegensatz-Paare:
     * belohnt „Quelle stark auf x ⊕ Kandidat stark auf gegensätzlicher y" (und umgekehrt).
     * Harmonie/identisch → 0. @return array{0:float, 1:list<string>} score + beteiligte Achsen.
     */
    private function contrastScore(array $src, array $cand): array
    {
        $score = 0.0;
        $achsen = [];
        foreach (self::GESCHMACK_GEGENSATZ as [$x, $y]) {
            $vorwaerts = ($src[$x] ?? 0.0) * ($cand[$y] ?? 0.0);
            $rueckwaerts = ($src[$y] ?? 0.0) * ($cand[$x] ?? 0.0);
            $beitrag = $vorwaerts + $rueckwaerts;
            if ($beitrag > 0.05) {   // Rausch-Schwelle: nur nennenswerte Spannung
                $score += $beitrag;
                $achsen[] = $vorwaerts >= $rueckwaerts ? "{$x}↔{$y}" : "{$y}↔{$x}";
            }
        }

        return [$score, $achsen];
    }

    /**
     * Quell-Anker + Meta aus {gp:id}|{anchor:id} auflösen (geteilt von Harmonie- und
     * Kontrast-Hypothesen). @return array{anchorIds:list<int>, meta:array}|null
     */
    private function resolveSource(array $source): ?array
    {
        if (isset($source['gp'])) {
            $ids = $this->gpAnkers((int) $source['gp'])->pluck('id')->map(fn ($v) => (int) $v)->all();
            $name = DB::table('foodalchemist_gps')->where('id', (int) $source['gp'])->value('name');
            $meta = ['typ' => 'gp', 'id' => (int) $source['gp'], 'name' => $name];
        } elseif (isset($source['anchor'])) {
            $ids = [(int) $source['anchor']];
            $a = DB::table('foodalchemist_vocab_pairing_anchors')->where('id', (int) $source['anchor'])->first(['slug', 'display_de']);
            $meta = ['typ' => 'anchor', 'id' => (int) $source['anchor'], 'name' => $a?->display_de ?? $a?->slug];
        } else {
            return null;
        }
        $ids = array_values(array_unique(array_filter($ids)));
        $meta['anchor_ids'] = $ids;

        return ['anchorIds' => $ids, 'meta' => $meta];
    }

    /** anchor_id → ingredient_id (memoierbar; hier direkt, da selten je Aufruf). */
    private function anchorIngredientId(int $anchorId): ?int
    {
        $v = DB::table('foodalchemist_anchor_ingredient_map')->where('anchor_id', $anchorId)->value('ingredient_id');

        return $v !== null ? (int) $v : null;
    }

    /** @return list<int> component_ids der Zutat (Aroma-Compound-Klassen). */
    private function ingredientKeyComponentIds(int $ingredientId): array
    {
        return DB::table('foodalchemist_ingredient_key_component')
            ->where('ingredient_id', $ingredientId)->pluck('component_id')
            ->map(fn ($v) => (int) $v)->all();
    }

    /** @return list<string> distinkte chem_class-Werte der Moleküle der Zutat. */
    private function ingredientChemClasses(int $ingredientId): array
    {
        return DB::table('foodalchemist_ingredient_molecule AS im')
            ->join('foodalchemist_molecules AS m', 'm.id', '=', 'im.molecule_id')
            ->where('im.ingredient_id', $ingredientId)
            ->whereNotNull('m.chem_class')->distinct()->pluck('m.chem_class')
            ->map(fn ($v) => (string) $v)->all();
    }

    /**
     * anchor_id → [component_id,...] für ALLE profil-tragenden Anker in einem Rutsch
     * (ein Join statt N Queries — Kandidaten-Scoring).
     *
     * @return array<int, list<int>>
     */
    private function keyComponentsByAnchor(): array
    {
        $rows = DB::table('foodalchemist_anchor_ingredient_map AS m')
            ->join('foodalchemist_ingredient_key_component AS ikc', 'ikc.ingredient_id', '=', 'm.ingredient_id')
            ->get(['m.anchor_id', 'ikc.component_id']);
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->anchor_id][] = (int) $r->component_id;
        }

        return $out;
    }

    /**
     * Aroma-Vektor-Cosinus-Fallback: aggregierter Quell-Vektor über die Quell-Anker,
     * gegen alle anderen Anker mit Vektor. @return array<int,float> anchor_id => cosine.
     */
    private function aromaCosineCandidates(array $anchorIds, int $limit): array
    {
        $all = $this->allAnchorAromaVectors();
        $src = $this->gpAromaVectorFromMap($anchorIds, $all);
        if ($src === null) {
            return [];
        }
        $scored = [];
        foreach ($all as $cid => $c) {
            if (in_array($cid, $anchorIds, true)) {
                continue;
            }
            $cos = $this->vecCos($src, $c['vec']);
            if ($cos > 0) {
                $scored[$cid] = $cos;
            }
        }
        arsort($scored);

        return array_slice($scored, 0, $limit, true);
    }
}
