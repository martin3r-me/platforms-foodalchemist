<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;

/**
 * M5-04/05: GL-10 — Pairing-Kohäsion & Anker-Graph (deterministisch, ohne KI).
 * Queries read-only (Inv. 7); Schreibpfade nur set/remove (Caps Inv. 1,
 * manual gewinnt Inv. 3). Kanten sind seit dem V-23-Backfill symmetrisch (Inv. 4).
 * Fehlende Kante = unbekannt, nie Clash (Inv. 5); Scores = runde Ganzzahlen (Inv. 8).
 */
class PairingService
{
    private const GEWICHTE = ['klassisch' => 1.0, 'modern' => 0.75, 'kontrast' => 0.5]; // Tabelle 1

    private const TYP_PRIO = ['klassisch' => 1, 'modern' => 2, 'kontrast' => 3];

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
        foreach (DB::table('foodalchemist_vocab_pairing_ankers')->whereNull('deleted_at')
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
        $neutralId = DB::table('foodalchemist_vocab_pairing_ankers')->where('slug', 'neutral')->value('id');
        $out = [];
        foreach ($recipe->ingredients()->with(['gp:id,name', 'referencedRecipe:id,name'])->whereNull('deleted_at')->orderBy('position')->get() as $z) {
            $label = $z->referencedRecipe?->name ?? $z->gp?->name ?? $z->raw_text;
            $kern = null;
            $via = 'unresolved';
            $prozess = [];

            if ($z->referenced_recipe_id !== null) {
                $mapping = DB::table('foodalchemist_recipe_anker_mappings')
                    ->where('recipe_id', $z->referenced_recipe_id)->where('rolle', 'kern')->whereNull('deleted_at')
                    ->orderByRaw('COALESCE(ai_confidence, 1.0) DESC')->orderBy('id')
                    ->value('anker_id');
                if ($mapping !== null) {
                    [$kern, $via] = $mapping === $neutralId ? [null, 'neutral'] : [$mapping, 'recipe_anker'];
                } else {
                    $kern = $this->resolveByName($z->referencedRecipe->name);
                    $via = $kern !== null ? 'name_match' : 'unresolved';
                }
                $prozess = DB::table('foodalchemist_recipe_prozess_anker')
                    ->where('recipe_id', $z->referenced_recipe_id)->whereNull('deleted_at')
                    ->where('anker_id', '!=', $kern ?? 0)->pluck('anker_id')->all();
            } elseif ($z->gp_id !== null) {
                $mapping = DB::table('foodalchemist_gp_anker_mappings')
                    ->where('gp_id', $z->gp_id)->where('rolle', 'kern')->whereNull('deleted_at')
                    ->orderByRaw('COALESCE(ai_confidence, 1.0) DESC')->orderBy('id')
                    ->value('anker_id');
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
                        $schwaechstes = ['w' => $w, 'a' => $aufgeloest[$i]['label'], 'b' => $aufgeloest[$j]['label'], 'typ' => $typ];
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
                ? ['a' => $schwaechstes['a'], 'b' => $schwaechstes['b'], 'score' => (int) round(100 * $schwaechstes['w']), 'typ' => $schwaechstes['typ']]
                : null,
            'unrated_pairs' => $unrated,
            'komponenten' => $komponentenOut,
        ];
    }

    public function recipeCohesion(FoodAlchemistRecipe $recipe): array
    {
        return $this->cohesionFor($this->resolveRecipeAnchors($recipe));
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
        foreach (DB::table('foodalchemist_pairing_anker_edges')->whereIn('anker_b_id', $dishIds)
            ->whereNotIn('anker_a_id', $dishIds)
            ->get(['anker_a_id', 'anker_b_id', 'typ']) as $kante) {
            $w = self::GEWICHTE[$kante->typ] ?? 0.5;
            $k = &$kandidaten[$kante->anker_a_id];
            $k['best'][$kante->anker_b_id] = max($k['best'][$kante->anker_b_id] ?? 0, $w);
        }
        unset($k);

        $grade = DB::table('foodalchemist_pairing_anker_edges')->whereIn('anker_a_id', array_keys($kandidaten))
            ->selectRaw('anker_a_id, COUNT(*) AS n')->groupBy('anker_a_id')->pluck('n', 'anker_a_id');
        $namen = DB::table('foodalchemist_vocab_pairing_ankers')
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
                'anker_id' => $id, 'slug' => (string) $namen[$id], 'cover' => $cover,
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
        $ankerA = DB::table('foodalchemist_recipe_pairings')->where('recipe_id', $recipeA)->whereNull('deleted_at')->distinct()->pluck('anker_id')->all();
        $ankerB = DB::table('foodalchemist_recipe_pairings')->where('recipe_id', $recipeB)->whereNull('deleted_at')->distinct()->pluck('anker_id')->all();

        $direkte = array_values(array_intersect($ankerA, $ankerB));
        // LIMIT 30 deckelt die indirekte Zählung (Ist holt max 30 Zeilen) — COUNT ignoriert
        // LIMIT in SQL, daher explizit über die gedeckelte Ergebnisliste zählen
        $indirekte = DB::table('foodalchemist_pairing_anker_edges')
            ->whereIn('anker_a_id', $ankerA)->whereIn('anker_b_id', $ankerB)
            ->whereColumn('anker_a_id', '!=', 'anker_b_id')
            ->orderByRaw("CASE typ WHEN 'klassisch' THEN 1 WHEN 'modern' THEN 2 ELSE 3 END")
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
        $eigene = DB::table('foodalchemist_recipe_pairings')->where('recipe_id', $recipeId)->whereNull('deleted_at')->distinct()->pluck('anker_id');
        if ($eigene->isEmpty()) {
            return collect();
        }

        $treffer = DB::table('foodalchemist_recipe_pairings AS rp')
            ->whereIn('rp.anker_id', $eigene)->where('rp.recipe_id', '!=', $recipeId)->whereNull('rp.deleted_at')
            ->selectRaw('rp.recipe_id, COUNT(DISTINCT rp.anker_id) AS shared')
            ->groupBy('rp.recipe_id')->havingRaw('COUNT(DISTINCT rp.anker_id) >= ?', [$minShared])
            ->get();

        $gesamt = DB::table('foodalchemist_recipe_pairings')->whereIn('recipe_id', $treffer->pluck('recipe_id'))
            ->whereNull('deleted_at')->selectRaw('recipe_id, COUNT(DISTINCT anker_id) AS n')->groupBy('recipe_id')->pluck('n', 'recipe_id');
        $rezepte = FoodAlchemistRecipe::visibleToTeam($team)->whereIn('id', $treffer->pluck('recipe_id'))->pluck('name', 'id');

        return $treffer->filter(fn ($t) => $rezepte->has($t->recipe_id))
            ->map(fn ($t) => [
                'recipe_id' => $t->recipe_id,
                'name' => $rezepte[$t->recipe_id],
                'shared' => (int) $t->shared,
                'eigene_gesamt' => (int) ($gesamt[$t->recipe_id] ?? 0),
                'shared_slugs' => DB::table('foodalchemist_recipe_pairings AS rp')
                    ->join('foodalchemist_vocab_pairing_ankers AS a', 'a.id', '=', 'rp.anker_id')
                    ->where('rp.recipe_id', $t->recipe_id)->whereIn('rp.anker_id', $eigene)->whereNull('rp.deleted_at')
                    ->distinct()->limit(5)->pluck('a.slug')->all(),
            ])
            ->sortBy([fn ($a, $b) => [$b['shared'], $a['eigene_gesamt'], $a['recipe_id']] <=> [$a['shared'], $b['eigene_gesamt'], $b['recipe_id']]])
            ->take($limit)->values();
    }

    public function ankerNeighbors(string $slug, ?string $typ = null, int $limit = 30): Collection
    {
        $limit = max(1, min(200, $limit));
        $ankerId = DB::table('foodalchemist_vocab_pairing_ankers')->where('slug', $slug)->value('id');
        if ($ankerId === null) {
            return collect();
        }

        return DB::table('foodalchemist_pairing_anker_edges AS e')
            ->join('foodalchemist_vocab_pairing_ankers AS a', 'a.id', '=', 'e.anker_b_id')
            ->where('e.anker_a_id', $ankerId)
            ->when($typ !== null, fn ($q) => $q->where('e.typ', $typ))
            ->orderByRaw("CASE e.typ WHEN 'klassisch' THEN 1 WHEN 'modern' THEN 2 ELSE 3 END")
            ->orderBy('a.slug')->limit($limit)
            ->get(['a.id', 'a.slug', 'a.display_de', 'e.typ', 'e.evidenz']);
    }

    // ── Schreibpfade (Inv. 1/3) ──────────────────────────────────────────

    public function setRecipeAnker(Team $team, int $recipeId, int $ankerId): void
    {
        $recipe = FoodAlchemistRecipe::visibleToTeam($team)->findOrFail($recipeId);
        $vorhanden = DB::table('foodalchemist_recipe_anker_mappings')
            ->where('recipe_id', $recipe->id)->where('anker_id', $ankerId)->whereNull('deleted_at')->first();
        if ($vorhanden === null
            && DB::table('foodalchemist_recipe_anker_mappings')->where('recipe_id', $recipe->id)->whereNull('deleted_at')->count() >= self::CAP_RECIPE) {
            throw new \RuntimeException('Limit erreicht: max ' . self::CAP_RECIPE . ' Kern-Anker pro Rezept.');
        }
        DB::table('foodalchemist_recipe_anker_mappings')->updateOrInsert(
            ['recipe_id' => $recipe->id, 'anker_id' => $ankerId],
            ['uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(), 'team_id' => $team->id, 'rolle' => 'kern',
                'quelle' => 'manual', 'ai_confidence' => null, 'ai_begruendung' => null,    // manual gewinnt (Inv. 3)
                'deleted_at' => null, 'updated_at' => now(), 'created_at' => now()],
        );
    }

    public function removeRecipeAnker(Team $team, int $recipeId, int $ankerId): void
    {
        FoodAlchemistRecipe::visibleToTeam($team)->findOrFail($recipeId);
        DB::table('foodalchemist_recipe_anker_mappings')
            ->where('recipe_id', $recipeId)->where('anker_id', $ankerId)->update(['deleted_at' => now()]);
    }

    /** Anker eines Rezepts inkl. Slug/Quelle (Panel-Chips). */
    public function recipeAnkers(int $recipeId): Collection
    {
        return DB::table('foodalchemist_recipe_anker_mappings AS m')
            ->join('foodalchemist_vocab_pairing_ankers AS a', 'a.id', '=', 'm.anker_id')
            ->where('m.recipe_id', $recipeId)->whereNull('m.deleted_at')
            ->orderByRaw('COALESCE(m.ai_confidence, 1.0) DESC')->orderBy('m.id')
            ->get(['a.id', 'a.slug', 'a.display_de', 'm.quelle', 'm.ai_confidence']);
    }

    /** Pairing-Partner eines Rezepts (recipe_pairings — Chips, M5-05). */
    public function recipePairings(int $recipeId): Collection
    {
        return DB::table('foodalchemist_recipe_pairings AS rp')
            ->join('foodalchemist_vocab_pairing_ankers AS a', 'a.id', '=', 'rp.anker_id')
            ->where('rp.recipe_id', $recipeId)->whereNull('rp.deleted_at')
            ->orderByRaw("CASE rp.typ WHEN 'klassisch' THEN 1 WHEN 'verbund' THEN 2 WHEN 'trinitas' THEN 3 ELSE 4 END")
            ->orderBy('a.slug')
            ->get(['a.id', 'a.slug', 'a.display_de', 'rp.typ', 'rp.konfidenz', 'rp.created_via']);
    }

    /** Manuelles Pairing setzen (recipe_pairings, created_via='manual' — bewusst gesetzt, gewinnt). */
    public function setRecipePairing(Team $team, int $recipeId, int $ankerId, string $typ = 'klassisch'): void
    {
        $recipe = FoodAlchemistRecipe::visibleToTeam($team)->findOrFail($recipeId);
        $typ = in_array($typ, ['klassisch', 'modern', 'kontrast', 'verbund', 'trinitas'], true) ? $typ : 'klassisch';
        DB::table('foodalchemist_recipe_pairings')->updateOrInsert(
            ['recipe_id' => $recipe->id, 'anker_id' => $ankerId, 'typ' => $typ],
            ['uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(), 'team_id' => $team->id,
                'konfidenz' => 'hoch', 'created_via' => 'manual', 'note' => null,
                'deleted_at' => null, 'updated_at' => now(), 'created_at' => now()],
        );
    }

    public function removeRecipePairing(Team $team, int $recipeId, int $ankerId, ?string $typ = null): void
    {
        FoodAlchemistRecipe::visibleToTeam($team)->findOrFail($recipeId);
        DB::table('foodalchemist_recipe_pairings')
            ->where('recipe_id', $recipeId)->where('anker_id', $ankerId)
            ->when($typ !== null, fn ($q) => $q->where('typ', $typ))
            ->update(['deleted_at' => now()]);
    }

    /** Kern-Aroma-Anker eines GP inkl. Slug/Quelle (GP-Pairing-Panel). */
    public function gpAnkers(int $gpId): Collection
    {
        return DB::table('foodalchemist_gp_anker_mappings AS m')
            ->join('foodalchemist_vocab_pairing_ankers AS a', 'a.id', '=', 'm.anker_id')
            ->where('m.gp_id', $gpId)->where('m.rolle', 'kern')->whereNull('m.deleted_at')
            ->orderByRaw('COALESCE(m.ai_confidence, 1.0) DESC')->orderBy('m.id')
            ->get(['a.id', 'a.slug', 'a.display_de', 'm.quelle', 'm.ai_confidence']);
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

        // Teller-Logik (»komplettiert den Teller« + »macht den Teller eigen«) ergibt
        // NUR fürs GERICHT Sinn — ein Basisrezept ist eine Komponente, kein Teller.
        // Basisrezept ⇒ stattdessen die Graph-Sicht: klassische Aroma-Nachbarn +
        // verwandte Basisrezepte (geteilte Pairing-Anker).
        $istGericht = (bool) $recipe->ist_verkaufsrezept;
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
            $nachbarn = $this->ankerNachbarnAggregiert($slugs, $eigene, 'klassisch');
            $team = Team::find((int) $recipe->team_id);
            $verwandte = $team !== null
                ? $this->recipesSharingPairings($team, $recipe->id)->all()
                : [];
        }

        return [
            'typ' => 'recipe',
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
                ->map(fn ($a) => ['slug' => $a->slug, 'display_de' => $a->display_de, 'quelle' => $a->quelle])->all(),
            'vorschlaege' => $vorschlaege,
            'signature' => $signature,
            'nachbarn' => $nachbarn,
            'verwandte' => $verwandte,
            'kontrast' => $this->ankerNachbarnAggregiert($slugs, $eigene, 'kontrast'),
        ];
    }

    /** GP: eigene Aroma-Anker + klassische Nachbarn (»passt zu«) + Kontrast (Gegenpol). */
    public function panelGp(int $gpId): array
    {
        $anker = $this->gpAnkers($gpId);
        $slugs = $anker->pluck('slug')->all();
        $eigene = $anker->pluck('id')->map(fn ($i) => (int) $i)->all();

        return [
            'typ' => 'gp',
            'anker' => $anker->map(fn ($a) => ['slug' => $a->slug, 'display_de' => $a->display_de, 'quelle' => $a->quelle])->all(),
            'nachbarn' => $this->ankerNachbarnAggregiert($slugs, $eigene, 'klassisch'),
            'kontrast' => $this->ankerNachbarnAggregiert($slugs, $eigene, 'kontrast'),
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
            ->join('foodalchemist_vocab_pairing_ankers AS a', 'a.id', '=', 'rp.anker_id')
            ->where('rp.recipe_id', $recipeId)->whereNull('rp.deleted_at')
            ->whereNotIn('a.id', $kern->pluck('id'))
            ->orderByRaw("CASE rp.typ WHEN 'klassisch' THEN 1 WHEN 'verbund' THEN 2 WHEN 'trinitas' THEN 3 ELSE 4 END")
            ->orderBy('a.slug')->distinct()
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
                    $kanten[] = ['a' => (int) $a, 'b' => (int) $b, 'typ' => $typ];
                }
            }
        }

        $verwandte = $this->recipesSharingPairings($team, $recipeId)->map(function (array $v) use ($ringIds) {
            $v['shared_anker_ids'] = DB::table('foodalchemist_recipe_pairings')
                ->where('recipe_id', $v['recipe_id'])->whereNull('deleted_at')
                ->whereIn('anker_id', $ringIds)->distinct()->pluck('anker_id')->map(fn ($i) => (int) $i)->all();
            $v['vk'] = (bool) FoodAlchemistRecipe::withoutGlobalScopes()->whereKey($v['recipe_id'])->value('ist_verkaufsrezept');

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
                        'anker_id' => $a['id'], 'id' => (int) $n->id,
                        'slug' => $n->slug, 'display_de' => $n->display_de, 'typ' => $n->typ,
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

    // ── intern ───────────────────────────────────────────────────────────

    /** Beste Kante je ungeordnetem Anker-Paar: [a][b] => [gewicht, typ]. */
    private function edgeBest(array $ankerIds): array
    {
        if ($ankerIds === []) {
            return [];
        }
        $out = [];
        foreach (DB::table('foodalchemist_pairing_anker_edges')
            ->whereIn('anker_a_id', $ankerIds)->whereIn('anker_b_id', $ankerIds)
            ->get(['anker_a_id', 'anker_b_id', 'typ']) as $kante) {
            $w = self::GEWICHTE[$kante->typ] ?? 0.5;                 // unbekannter typ defensiv 0.5
            foreach ([[$kante->anker_a_id, $kante->anker_b_id], [$kante->anker_b_id, $kante->anker_a_id]] as [$a, $b]) {
                if (! isset($out[$a][$b]) || $out[$a][$b][0] < $w) {
                    $out[$a][$b] = [$w, $kante->typ];
                }
            }
        }

        return $out;
    }
}
