<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\Pairing\AnkerGraph;

/**
 * M5-04/05: GL-10 — Pairing-Kohäsion & Anker-Graph (deterministisch, ohne KI).
 * Queries read-only (Inv. 7); Schreibpfade nur set/remove (Caps Inv. 1,
 * manual gewinnt Inv. 3). Kanten sind seit dem V-23-Backfill symmetrisch (Inv. 4).
 * Fehlende Kante = unbekannt, nie Clash (Inv. 5); Scores = runde Ganzzahlen (Inv. 8).
 */
class PairingService
{
    /** Spec 60 · P2: einzige Lesestelle für die Harmonie zwischen Ankern. */
    private function graph(): AnkerGraph
    {
        return app(AnkerGraph::class);
    }

    public const CAP_GP = 3;

    public const CAP_RECIPE = 5;

    /**
     * V-045: der `neutral`-Anker wird einmal je Service-Instanz aufgelöst, nicht je
     * Rezept. `resolveRecipeAnchors` läuft im Kandidaten-Pool je Gericht — der Lookup
     * war damit eine Query pro Gericht für einen Wert, der sich nie ändert.
     * **Bewusst untypisiert** und ohne Cast: der Wert wird per `===` gegen
     * `->value('anchor_id')` verglichen, und beide kommen aus derselben untypisierten
     * Query-Builder-Quelle. Ein `(int)`-Cast hier würde die Identitäts-Vergleiche
     * unten je nach Treiber kippen.
     */
    private mixed $neutralAnkerId = null;

    private bool $neutralAnkerAufgeloest = false;

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

    /** Cache für {@see ankerSlugExakt()} — ein Grounding-Lauf prüft mehrere Tokens (bis zu 8 Zutaten
     * plus Singular-Kandidaten je Token), ohne Cache je Prüfung ein voller Tabellen-Scan (Orchestrierung
     * 2026-09-18: "sobald der Dossier-Import die Anker-Zahl hebt, lohnt ein Cache je Request"). */
    private ?\Illuminate\Support\Collection $ankerExaktListe = null;

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
     * Spec 50 · B-7: lexikalische Anker-KANDIDATEN für einen Namen — alle Anker, deren Term
     * als ganzes Wort im Namen steht (Wortgrenze wie lexicalAnkerIsSolid, KEINE Substrings:
     * „auberGINe" liefert hier kein Gin). Reihenfolge: längster Term zuerst, Gleichstand →
     * niedrigere prio. Das ist die billige Vorauswahl, aus der die KI den Kern-Anker wählt;
     * resolveByName (EIN Gewinner, auch Substring) bleibt für den Signal-Fixer.
     *
     * @return list<int> Anker-Ids
     */
    public function lexicalAnkerKandidaten(string $name, int $limit = 8): array
    {
        $folded = $this->fold($name);
        $treffer = [];                                                // id => [len, prio]
        foreach ($this->anchorIndex() as $term => [$ankerId, $prio]) {
            if (! str_contains($folded, ' ' . $term . ' ')) {
                continue;
            }
            $len = mb_strlen((string) $term);
            if (! isset($treffer[$ankerId]) || $treffer[$ankerId][0] < $len
                || ($treffer[$ankerId][0] === $len && $treffer[$ankerId][1] > $prio)) {
                $treffer[$ankerId] = [$len, $prio];
            }
        }
        uasort($treffer, fn ($a, $b) => [$b[0], $a[1]] <=> [$a[0], $b[1]]);

        return array_slice(array_map('intval', array_keys($treffer)), 0, $limit);
    }

    /**
     * Quality-Gate für einen lexikalischen Anker-Treffer: NUR akzeptieren, wenn
     * ein Anker-Term als ganzes Wort in der Anfrage steht. resolveByName matcht
     * auch beliebige Substrings — „auberGINe" trifft so den „Gin"-Anker. Ein
     * solcher Substring-Zufall ist ein stiller Falsch-Treffer, der den
     * semantischen Fallback fälschlich verhindern würde → hier verworfen.
     * fold() umschließt mit Spaces ⇒ Wortgrenze = space-delimitiertes Vorkommen
     * (konsistent zu anchorIndex/resolveByName; nur strenger — keine Substrings).
     */
    private function lexicalAnkerIsSolid(string $name, object $anker): bool
    {
        $q = $this->fold($name);
        foreach ([(string) $anker->slug, (string) $anker->display_de] as $raw) {
            $folded = trim($this->fold($raw));
            if ($folded === '') {
                continue;
            }
            if (str_contains($q, ' ' . $folded . ' ')) {          // ganzer Term als Wortfolge
                return true;
            }
            foreach (explode(' ', $folded) as $wort) {            // Einzelwörter ≥4 (wie anchorIndex prio 2)
                if (mb_strlen($wort) >= 4 && str_contains($q, ' ' . $wort . ' ')) {
                    return true;
                }
            }
        }

        return false;
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
     * Wie resolveAnkerSemantically, aber mit Score — für die transparente
     * Match-Weg-Kennzeichnung in neighborsForName (via=semantic + score).
     * Gleiche Gates: aus / kein Provider / Fehler ⇒ null.
     *
     * @return array{id: int, score: float}|null
     */
    private function resolveAnkerSemanticallyScored(string $name): ?array
    {
        if (trim($name) === '' || ! config('foodalchemist.semantic_search.enabled', false)) {
            return null;
        }
        try {
            return app(\Platform\FoodAlchemist\Services\Ai\KnowledgeEmbeddingService::class)
                ->resolveAnkerWithScore($name);
        } catch (\Throwable) {
            return null;
        }
    }

    /** V-045: `neutral` einmal je Instanz auflösen. Eigenes Flag, damit ein NULL-Ergebnis nicht jedes Mal neu gefragt wird. */
    private function neutralAnkerId(): mixed
    {
        if (! $this->neutralAnkerAufgeloest) {
            $this->neutralAnkerId = DB::table('foodalchemist_vocab_pairing_anchors')
                ->where('slug', 'neutral')->value('id');
            $this->neutralAnkerAufgeloest = true;
        }

        return $this->neutralAnkerId;
    }

    /**
     * V-045: bereits eager geladene Zutaten wiederverwenden statt neu zu fragen.
     *
     * Der Kandidaten-Pool lädt `ingredients` (+ `gp`/`referencedRecipe`) im Begriffe-/
     * Convenience-Modus schon eager — die Requery hier war eine Query je Gericht auf
     * Daten, die der Aufrufer in der Hand hält. Wiederverwendet wird **nur**, wenn auch
     * die beiden Label-Relationen geladen sind: sonst löste der Zugriff je Zeile ein
     * Lazy-Load aus und wäre teurer als die eine Sammel-Query.
     *
     * Zwei Feinheiten, damit das Ergebnis identisch bleibt: die Relation ist wegen
     * `SoftDeletes` am Ingredient-Model schon ohne getrashte Zeilen (das `whereNull`
     * unten ist dort redundant), und `position` wird in PHP sortiert, weil eine geladene
     * Relation keine `orderBy`-Garantie mitbringt.
     *
     * @return iterable<int, \Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient>
     */
    private function ankerZutaten(FoodAlchemistRecipe $recipe): iterable
    {
        if ($recipe->relationLoaded('ingredients')) {
            $zutaten = $recipe->ingredients;
            $labelRelationenDa = $zutaten->every(
                fn ($z) => $z->relationLoaded('gp') && $z->relationLoaded('referencedRecipe')
            );
            if ($labelRelationenDa) {
                return $zutaten->sortBy('position')->values();
            }
        }

        return $recipe->ingredients()
            ->with(['gp:id,name', 'referencedRecipe:id,name'])
            ->whereNull('deleted_at')
            ->orderBy('position')
            ->get();
    }

    /**
     * V-045 (zweiter Halbschritt): die Mapping-Lookups für MEHRERE Rezepte in einem Zug.
     *
     * Der Einzel-Fall {@see resolveRecipeAnchors} delegiert hierher — es gibt genau **eine**
     * Auflösungs-Wahrheit, damit Pool und Solver nicht auf einer zweiten Auswahl-Logik
     * rechnen als die Detail-Anzeige. Dominante Ebene sind die Mapping-Lookups **je Zutat**
     * (bei 1.000 Gerichten × ~12 Zutaten rund 12.000 Einzel-Queries); der erste Halbschritt
     * hat nur die Per-Gericht-Ebene erwischt.
     *
     * **Die Auswahl bleibt in SQL.** Jede der drei Mapping-Tabellen wird mit demselben
     * `ORDER BY COALESCE(ai_confidence, 1.0) DESC, id` gelesen wie vorher, nur mit `whereIn`
     * über alle Schlüssel; „gewonnen" hat die **erste** Zeile je Schlüssel. Die Auswahl in PHP
     * nachzubauen wäre die eigentliche Gefahr dieses Umbaus (NULL sortiert anders, Gleichstand
     * über Einfüge- statt `id`-Reihenfolge) — sie ist deshalb bewusst nicht nachgebaut. Der
     * Golden-Test `AnkerAufloesungGoldenTest` friert genau das ein.
     *
     * @param  iterable<int, FoodAlchemistRecipe>  $recipes
     * @return array<int, array<int, array{label: string, kern: ?int, prozess: array<int>, via: string}>> keyBy recipe id
     */
    public function resolveRecipeAnchorsMany(iterable $recipes): array
    {
        /** @var \Illuminate\Database\Eloquent\Collection<int, FoodAlchemistRecipe> $rezepte */
        $rezepte = (new \Illuminate\Database\Eloquent\Collection(
            $recipes instanceof \Traversable ? iterator_to_array($recipes) : (array) $recipes
        ))->filter(fn ($r) => $r instanceof FoodAlchemistRecipe && $r->id !== null)->keyBy('id');

        if ($rezepte->isEmpty()) {
            return [];
        }

        // Zutaten + Label-Relationen für den ganzen Satz — `loadMissing`, weil ein Aufrufer
        // sie mit einem breiteren Select geladen haben kann (Pool: `gp:id,name,tag_is_convenience`).
        // Ein blindes `load` würde diese Spalten still wegnehmen und den Convenience-Anteil kippen.
        $rezepte->loadMissing(['ingredients', 'ingredients.gp:id,name', 'ingredients.referencedRecipe:id,name']);

        $zutatenJeRezept = [];
        $subRezeptIds = [];
        $gpIds = [];
        foreach ($rezepte as $rid => $recipe) {
            $zutaten = $this->ankerZutaten($recipe);
            $zutatenJeRezept[$rid] = $zutaten;
            foreach ($zutaten as $z) {
                if ($z->referenced_recipe_id !== null) {
                    $subRezeptIds[$z->referenced_recipe_id] = true;
                } elseif ($z->gp_id !== null) {
                    $gpIds[$z->gp_id] = true;
                }
            }
        }

        $rezeptKerne = $this->kernMappingsBatch('foodalchemist_recipe_anchor_mappings', 'recipe_id', array_keys($subRezeptIds));
        $gpKerne = $this->kernMappingsBatch('foodalchemist_gp_anchor_mappings', 'gp_id', array_keys($gpIds));

        $out = [];
        foreach ($zutatenJeRezept as $rid => $zutaten) {
            $out[$rid] = $this->ankerZeilen($zutaten, $rezeptKerne, $gpKerne);
        }

        return $out;
    }

    /**
     * Kern-Mapping je Schlüssel: dieselbe Sortierung wie der frühere Einzel-`value()`-Aufruf,
     * nur mit `whereIn`. Die erste Zeile je Schlüssel gewinnt (`isset`-Riegel) — die DB
     * entscheidet also weiterhin, nicht PHP.
     *
     * @param  array<int, int|string>  $ids
     * @return array<int|string, mixed> Schlüssel → anchor_id (untypisiert wie zuvor)
     */
    private function kernMappingsBatch(string $tabelle, string $spalte, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $treffer = [];
        foreach (DB::table($tabelle)->whereIn($spalte, $ids)->where('role', 'kern')->whereNull('deleted_at')
            ->orderByRaw('COALESCE(ai_confidence, 1.0) DESC')->orderBy('id')
            ->get([$spalte, 'anchor_id']) as $zeile) {
            if (! isset($treffer[$zeile->{$spalte}])) {
                $treffer[$zeile->{$spalte}] = $zeile->anchor_id;
            }
        }

        return $treffer;
    }

    /**
     * resolve_recipe_anchors (Tabelle 4): pro Zutaten-Zeile GENAU EIN Kern
     * (+ Prozess-Anker nur bei Sub-Rezepten).
     *
     * @return array<int, array{label: string, kern: ?int, prozess: array<int>, via: string}>
     */
    public function resolveRecipeAnchors(FoodAlchemistRecipe $recipe): array
    {
        return $this->resolveRecipeAnchorsMany([$recipe])[$recipe->id] ?? [];
    }

    /**
     * Die Zeilen-Schleife selbst — unverändert in der Logik, sie liest die Mappings jetzt
     * aus den Batch-Karten statt sie je Zutat zu erfragen.
     *
     * @param  iterable<int, \Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient>  $zutaten
     * @param  array<int|string, mixed>  $rezeptKerne
     * @param  array<int|string, mixed>  $gpKerne
     * @return array<int, array{label: string, kern: ?int, prozess: array<int>, via: string}>
     */
    private function ankerZeilen(
        iterable $zutaten,
        array $rezeptKerne,
        array $gpKerne,
    ): array {
        $neutralId = $this->neutralAnkerId();
        $out = [];
        foreach ($zutaten as $z) {
            $label = $z->referencedRecipe?->name ?? $z->gp?->name ?? $z->raw_text;
            $kern = null;
            $via = 'unresolved';
            // Spec 60 · P3: Prozess-Anker gibt es nicht mehr (Zubereitung = Verfahren, P4). Der
            // Schlüssel bleibt bis zur Umstellung der Leser (P6) als leere Liste stehen.
            $prozess = [];

            if ($z->referenced_recipe_id !== null) {
                $mapping = $rezeptKerne[$z->referenced_recipe_id] ?? null;
                if ($mapping !== null) {
                    [$kern, $via] = $mapping === $neutralId ? [null, 'neutral'] : [$mapping, 'recipe_anker'];
                } else {
                    // Spec 58 · Paket 1: Basisrezept ohne eigenes Mapping über SEINE Zutaten auflösen
                    // (stärkster Bestandteil = Kern), nicht über Wortteile des Rezeptnamens.
                    $kern = $this->rekursiverKern((int) $z->referenced_recipe_id);
                    $via = $kern !== null ? 'rezept_zutaten' : 'unresolved';
                }
            } elseif ($z->gp_id !== null) {
                $mapping = $gpKerne[$z->gp_id] ?? null;
                if ($mapping !== null) {
                    [$kern, $via] = $mapping === $neutralId ? [null, 'neutral'] : [$mapping, 'gp_anker'];
                } else {
                    // Spec 58 · Paket 1: nur exakter Grundname, kein Wortteil-Treffer.
                    $kern = $this->ankerIdExakt($z->gp->name);
                    $via = $kern !== null ? 'exakt_name' : 'unresolved';
                }
            } else {
                $kern = $this->ankerIdExakt($z->raw_text);
                $via = $kern !== null ? 'exakt_name' : 'unresolved';
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

    /**
     * Spec 58: Gewicht einer Zutat nach Rolle (Dominique 2026-10-06). Aromaträger prägen das
     * Gericht, Garnitur setzt Akzente — ohne Rolle zählt eine Zutat wie eine Komponente.
     */
    public const ROLLEN_GEWICHT = ['aroma_treiber' => 1.5, 'komponente' => 1.0, 'beilage' => 0.6, 'garnitur' => 0.4];

    /** Zähl-Einheiten ohne Gramm-Angabe: grobe Näherung, nur für die Rangfolge der Bestandteile. */
    private const STUECK_ERSATZ_G = 50.0;

    /** Reine Salze prägen kein Aroma — sie dürfen nie Kern eines Basisrezepts werden (sonst „Rote Bete" → Meersalz). */
    private const KEIN_KERN_SLUGS = ['sea_salt', 'black_lava_salt'];

    /** Max. Rekursionstiefe der Basisrezept-Auflösung (Regelwerk Basisrezepte §4: 3 Ebenen). */
    private const REKURSION_MAX = 3;

    /**
     * Spec 58 · Paket 1: Kern-Anker eines Basisrezepts ohne eigenes Mapping — der mengen- und
     * rollengewichtet stärkste aufgelöste Bestandteil (Grundprodukt-Mapping, exakter Name oder
     * rekursiv ein tieferes Basisrezept). Null, wenn nichts auflösbar ist.
     *
     * @param  array<int, true>  $besucht  Zyklus-Schutz
     */
    private function rekursiverKern(int $recipeId, int $tiefe = 1, array $besucht = []): ?int
    {
        if ($tiefe > self::REKURSION_MAX || isset($besucht[$recipeId])) {
            return null;
        }
        $besucht[$recipeId] = true;
        $neutralId = $this->neutralAnkerId();

        $zeilen = DB::table('foodalchemist_recipe_ingredients AS ri')
            ->leftJoin('foodalchemist_vocab_units AS u', 'u.id', '=', 'ri.unit_vocab_id')
            ->leftJoin('foodalchemist_gps AS g', 'g.id', '=', 'ri.gp_id')
            ->where('ri.recipe_id', $recipeId)->whereNull('ri.deleted_at')->where('ri.is_optional', false)
            ->get(['ri.gp_id', 'ri.referenced_recipe_id', 'ri.raw_text', 'ri.quantity', 'ri.quantity_max', 'ri.role',
                'u.dimension', 'u.default_in_g', 'u.default_in_ml', 'u.slug AS unit_slug', 'g.name AS gp_name']);
        if ($zeilen->isEmpty()) {
            return null;
        }

        $gpKerne = $this->kernMappingsBatch('foodalchemist_gp_anchor_mappings', 'gp_id',
            $zeilen->pluck('gp_id')->filter()->unique()->values()->all());
        $subKerne = $this->kernMappingsBatch('foodalchemist_recipe_anchor_mappings', 'recipe_id',
            $zeilen->pluck('referenced_recipe_id')->filter()->unique()->values()->all());

        $bester = null;
        $besteGewicht = -1.0;
        foreach ($zeilen as $z) {
            $anker = null;
            if ($z->gp_id !== null) {
                $anker = $gpKerne[$z->gp_id] ?? $this->ankerIdExakt($z->gp_name);
            } elseif ($z->referenced_recipe_id !== null) {
                $anker = $subKerne[$z->referenced_recipe_id] ?? $this->rekursiverKern((int) $z->referenced_recipe_id, $tiefe + 1, $besucht);
            } else {
                $anker = $this->ankerIdExakt($z->raw_text);
            }
            if ($anker === null || $anker === $neutralId || in_array($this->ankerSlugVonId((int) $anker), self::KEIN_KERN_SLUGS, true)) {
                continue;
            }
            $gewicht = $this->zeilenGramm($z) * (self::ROLLEN_GEWICHT[$z->role ?? ''] ?? 1.0);
            if ($gewicht > $besteGewicht) {
                [$bester, $besteGewicht] = [(int) $anker, $gewicht];
            }
        }

        return $bester;
    }

    /**
     * Spec 60 · P5: Kern-Anker je Grundprodukt (beste Zuordnung, wie die Anker-Auflösung).
     *
     * @param  array<int, int|string>  $gpIds
     * @return array<int|string, mixed> gp_id → anchor_id
     */
    public function gpKernAnker(array $gpIds): array
    {
        return $this->kernMappingsBatch('foodalchemist_gp_anchor_mappings', 'gp_id', array_values(array_unique($gpIds)));
    }

    /** Spec 60 · P5: ID des Ankers „neutral" (kein Aroma), sonst null. */
    public function neutralAnker(): ?int
    {
        $id = $this->neutralAnkerId();

        return $id !== null ? (int) $id : null;
    }

    private function ankerSlugVonId(int $id): ?string
    {
        return $this->ankerExaktListe()->first(fn ($a) => (int) $a->id === $id)?->slug;
    }

    /** Menge einer Zutatenzeile in Gramm (Mittelwert bei Bereich), Zähl-Einheiten genähert, „qs" = 0. */
    public function zeilenGramm(object $z): float
    {
        $menge = $z->quantity_max !== null ? ((float) $z->quantity + (float) $z->quantity_max) / 2 : (float) $z->quantity;
        if (($z->unit_slug ?? null) === 'qs') {
            return 0.0;
        }
        if ($z->default_in_g !== null && (float) $z->default_in_g > 0) {
            return $menge * (float) $z->default_in_g;
        }
        // Volumen (ml, l, EL): Dichte 1,0 wie die T1-Kaskade — vorher fiel „500 ml" auf den
        // Stück-Ersatz (500 × 50 g = 25 kg) und verdrängte jede andere Zutat (Spec 60, 06.10.).
        if (isset($z->default_in_ml) && $z->default_in_ml !== null && (float) $z->default_in_ml > 0) {
            return $menge * (float) $z->default_in_ml;
        }

        return $menge * self::STUECK_ERSATZ_G;
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
                        if ((int) $ka === (int) $kb) {
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
        return $this->flacheAnker($this->resolveRecipeAnchors($recipe));
    }

    /**
     * Dieselbe Abflachung wie {@see anchorsForRecipe}, aber auf schon aufgelösten Komponenten —
     * damit ein Batch-Aufrufer (Pool/Solver) nicht seine eigene Variante schreibt.
     *
     * @param  array<int, array{label: string, kern: ?int, prozess: array<int>, via: string}>  $komponenten
     * @return array<int, int|string>
     */
    public function flacheAnker(array $komponenten): array
    {
        $ids = [];
        foreach ($komponenten as $k) {
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
     * Spec 21 · S4b-2 — WIEDERHOLUNG über eine Menüfolge: welche Gerichte tragen
     * dieselbe Hauptzutat? Gegenstück zu {@see menuCohesion}, bewusst als eigener
     * Weg und nicht als weiterer Rückgabe-Schlüssel dort:
     *
     *  · `cohesionFor` bewertet einen geteilten Anker als **1,0 — das Maximum**
     *    (Paar-Typ `gleich`). Für den Teller ist das richtig (die Komponenten
     *    gehören zusammen), für die Menüfolge ist es die Diagnose auf dem Kopf:
     *    zweimal Lachs hebt dort den Score, obwohl es genau der Mangel ist.
     *    Denselben Wert einmal als Stärke und einmal als Befund zu lesen, wären
     *    zwei Wahrheiten in einer Zahl.
     *  · `menuCohesion` legt je Gericht die **Union aller Zutaten-Anker** an. Ein
     *    geteilter Anker heißt dort auch „beide enthalten Butter" — für die
     *    Dramaturgie-Frage zählt nur, worum es in dem Gang geht.
     *
     * Die Hauptzutat ist deshalb die **mengenmäßig dominierende Zutat** des Gerichts
     * (größte Roh-Einsatzmasse, gleiche Formel wie `RecipeRecomputeService`: Mittelwert
     * eines Mengen-Bereichs × `default_in_g`), und ihr Identitäts-Anker ist der `kern`
     * ihres GP bzw. — bei einer Sub-Rezept-Zeile — der des Sub-Rezepts; dieselbe Wahl,
     * die {@see resolveRecipeAnchors} je Zutaten-Zeile trifft (höchste `ai_confidence`,
     * dann `id`). Bewusst NICHT genommen: `recipe_anchor_mappings.role='kern'` am Gericht
     * selbst — das liest sich wie ein Identitäts-Feld, ist im Bestand aber ein *Beutel*
     * aller KI-erkannten Zutaten-Anker (bis zu 24 je Rezept, durchweg ohne Konfidenz).
     * Daraus einen zu wählen hieße raten, und „beide fangen mit Butter an" wäre dasselbe
     * Signal wie „beide sind Lachs".
     *
     * Zwei weitere Auslegungen:
     *  · Gruppiert wird über {@see ankerSlugMatches}, nicht über die Anker-ID:
     *    `lachs` und `lachs_wild` sind für den Gast dieselbe Hauptzutat. Der Match
     *    ist prefix-basiert und damit nicht transitiv — Repräsentant einer Gruppe
     *    ist deshalb das zuerst gesehene Gericht (deterministisch über `recipe_id`).
     *  · Was sich nicht in Masse vergleichen lässt (Stück-/Volumen-Einheit, `qs`,
     *    optionale Zeile) oder keinen Anker trägt, bleibt **unbewertet** — kein Befund.
     *    Fehlende Erdung ist keine Aussage über das Menü (T9). `neutral` ist kein
     *    Identitäts-Anker, so behandelt es auch `resolveRecipeAnchors`.
     *
     * @param  list<int>  $dishIds
     * @return list<array{slug: string, recipe_ids: list<int>}>
     */
    public function menuRepetitions(array $dishIds): array
    {
        $dishIds = array_values(array_unique(array_map('intval', $dishIds)));
        if (count($dishIds) < 2) {
            return [];
        }

        // Einheiten-Gate wie beim Ausbeute-Check (Spec 21 · S1b): ein Gericht wird nur
        // bewertet, wenn ALLE beitragenden Zeilen massen-vergleichbar sind. Sonst gewönne
        // die schwerste *messbare* Zeile — in einer Mayonnaise mit 0,25 l Sojamilch und
        // 0,5 l Öl wäre das das Salz, und der Befund behauptete eine Hauptzutat, die
        // keine ist. Lieber unbewertet als falsch.
        $unvergleichbar = DB::table('foodalchemist_recipe_ingredients AS ri')
            ->leftJoin('foodalchemist_vocab_units AS u', 'u.id', '=', 'ri.unit_vocab_id')
            ->whereIn('ri.recipe_id', $dishIds)
            ->whereNull('ri.deleted_at')
            ->where('ri.is_optional', false)
            ->where(fn ($w) => $w->whereNull('u.slug')->orWhere('u.slug', '!=', 'qs'))
            ->where(fn ($w) => $w->whereNull('u.dimension')->orWhere('u.dimension', '!=', 'mass')
                ->orWhereNull('u.default_in_g')->orWhere('u.default_in_g', '<=', 0))
            ->distinct()->pluck('ri.recipe_id')->map(fn ($i) => (int) $i)->all();
        $dishIds = array_values(array_diff($dishIds, $unvergleichbar));
        if (count($dishIds) < 2) {
            return [];
        }

        // Schwerste Zeile je Gericht (Bereich = Mittelwert, §6.4).
        $top = [];
        $rows = DB::table('foodalchemist_recipe_ingredients AS ri')
            ->join('foodalchemist_vocab_units AS u', 'u.id', '=', 'ri.unit_vocab_id')
            ->whereIn('ri.recipe_id', $dishIds)
            ->whereNull('ri.deleted_at')
            ->where('ri.is_optional', false)
            ->where('u.slug', '!=', 'qs')
            ->where('u.dimension', 'mass')
            ->where('u.default_in_g', '>', 0)
            ->where('ri.quantity', '>', 0)
            ->selectRaw('ri.recipe_id, ri.gp_id, ri.referenced_recipe_id, ri.position,'
                . ' ((ri.quantity + COALESCE(ri.quantity_max, ri.quantity)) / 2) * u.default_in_g AS masse_g')
            ->orderBy('ri.recipe_id')
            ->orderByDesc('masse_g')
            ->orderBy('ri.position')
            ->get();
        foreach ($rows as $r) {
            $top[(int) $r->recipe_id] ??= $r;
        }

        $kernJeGericht = [];
        $gpKerne = $this->kernSlugs('foodalchemist_gp_anchor_mappings', 'gp_id',
            array_map(fn ($z) => (int) $z->gp_id, array_filter($top, fn ($z) => $z->referenced_recipe_id === null && $z->gp_id !== null)));
        $subKerne = $this->kernSlugs('foodalchemist_recipe_anchor_mappings', 'recipe_id',
            array_map(fn ($z) => (int) $z->referenced_recipe_id, array_filter($top, fn ($z) => $z->referenced_recipe_id !== null)));
        foreach ($top as $recipeId => $z) {
            $slug = $z->referenced_recipe_id !== null
                ? ($subKerne[(int) $z->referenced_recipe_id] ?? null)
                : ($z->gp_id !== null ? ($gpKerne[(int) $z->gp_id] ?? null) : null);
            if ($slug !== null) {
                $kernJeGericht[$recipeId] = $slug;
            }
        }
        ksort($kernJeGericht);

        $gruppen = [];
        foreach ($kernJeGericht as $recipeId => $slug) {
            foreach ($gruppen as &$g) {
                if ($this->ankerSlugMatches($g['slug'], $slug)) {
                    $g['recipe_ids'][] = $recipeId;

                    continue 2;
                }
            }
            unset($g);
            $gruppen[] = ['slug' => $slug, 'recipe_ids' => [$recipeId]];
        }

        return array_values(array_filter($gruppen, fn ($g) => count($g['recipe_ids']) > 1));
    }

    /**
     * Kern-Anker-Slug je Besitzer (GP oder Sub-Rezept) — dieselbe Wahl wie in
     * {@see resolveRecipeAnchors}: höchste `ai_confidence`, dann `id`. `neutral`
     * gilt dort wie hier als „kein Kern".
     *
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    private function kernSlugs(string $tabelle, string $spalte, array $ids): array
    {
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return [];
        }

        $out = [];
        $rows = DB::table($tabelle . ' AS m')
            ->join('foodalchemist_vocab_pairing_anchors AS a', 'a.id', '=', 'm.anchor_id')
            ->whereIn('m.' . $spalte, $ids)
            ->where('m.role', 'kern')
            ->where('a.slug', '!=', 'neutral')
            ->whereNull('m.deleted_at')
            ->whereNull('a.deleted_at')
            ->orderBy('m.' . $spalte)
            ->orderByRaw('COALESCE(m.ai_confidence, 1.0) DESC')
            ->orderBy('m.id')
            ->get(['m.' . $spalte . ' AS owner_id', 'a.slug']);
        foreach ($rows as $r) {
            $out[(int) $r->owner_id] ??= (string) $r->slug;
        }

        return $out;
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

    /**
     * Roadmap Etappe 1 »LLM-Foodpairing als Assist«: das Aroma-Profil einer bereits
     * (teil)belegten Menüfolge — die Anker der schon gesetzten Gerichte plus die
     * harmonischen Partner-Anker aus dem Graphen (Expansion AUSSERHALB des getragenen
     * Sets). Speist die KI-Ideen-Erfindung ({@see IdeenService::kiDivergenzConcept}),
     * damit neue Gerichte auf der Aroma-Achse zur Folge PASSEN statt frei geraten zu
     * werden. Liefert `null`, wenn die Folge keine auflösbaren Anker trägt (leeres oder
     * ungemapptes Menü) — dann bleibt der Prompt byte-identisch, ohne Erdungs-Block.
     *
     * @param  iterable<FoodAlchemistRecipe>  $recipes  die bereits gesetzten Gerichte der Folge
     * @return array{anker: list<string>, partner: list<string>}|null
     */
    public function menueAromaProfil(iterable $recipes, int $partnerLimit = 12): ?array
    {
        $ankerIds = [];
        foreach ($this->resolveRecipeAnchorsMany($recipes) as $komponenten) {
            foreach ($this->flacheAnker($komponenten) as $id) {
                $ankerIds[(int) $id] = true;
            }
        }
        $ankerIds = array_keys($ankerIds);
        if ($ankerIds === []) {
            return null;
        }

        // Anker-Namen der Folge (display_de, sonst slug) — stabil alphabetisch.
        $anker = DB::table('foodalchemist_vocab_pairing_anchors')
            ->whereIn('id', $ankerIds)->whereNull('deleted_at')
            ->orderBy('display_de')->get(['slug', 'display_de'])
            ->map(fn ($a) => (string) ($a->display_de ?: $a->slug))
            ->filter()->unique()->values()->all();
        if ($anker === []) {
            return null;
        }

        // Harmonische Partner: gewichtsstärkste Expansion außerhalb der schon getragenen
        // Anker (Stern-Stufe → Gewicht → Abdeckung). Dubletten-frei, gekappt.
        [$kandidaten] = $this->kandidatenFuerAnker($ankerIds);
        usort($kandidaten, fn ($a, $b) => [$b['level'], $b['weight'], $b['cover']] <=> [$a['level'], $a['weight'], $a['cover']]);
        $partner = [];
        foreach (array_slice($kandidaten, 0, max(0, $partnerLimit)) as $k) {
            $lbl = (string) ($k['display_de'] ?: $k['slug']);
            if ($lbl !== '') {
                $partner[$lbl] = true;
            }
        }

        return ['anker' => $anker, 'partner' => array_keys($partner)];
    }

    /**
     * Roadmap Etappe 1 »Menü-Folge-Kohärenz-Gate«: macht aus einem rohen
     * {@see menuCohesion}-Ergebnis eine abgestufte, sichtbare Rückkopplung/Warnung.
     * Ergänzt die Erdung ({@see menueAromaProfil}) um die Gegenprobe: nachdem die
     * Erfindung am Graphen geerdet wurde, wird die entstandene Folge auch GEGEN die
     * Folge-Kohäsion geprüft, nicht nur blind erzeugt.
     *
     * Die Schwellen sind byte-genau aus dem Anzeige-Panel gespiegelt
     * (`kohaesion-panel.blade.php`: ≥60 emerald, ≥35 amber, sonst rose) — Gate und
     * Farb-Band sagen damit dasselbe, keine zweite Wahrheit. Liefert `null`, wenn
     * nichts zu beurteilen ist: zu wenige Gerichte ODER kein einziges bewertetes
     * Gericht-Paar. Fehlende Graph-Daten sind KEIN schlechtes Menü (T9) — nur keine
     * Aussage, und ein Nichts wird nicht als Warnung verkauft.
     *
     * @param  array  $kohaesion  Ergebnis aus {@see menuCohesion} / {@see cohesionFor}
     * @return array{stufe: 'gut'|'schwach'|'kritisch', score: int, text: string}|null
     */
    public function menuKohaesionWarnung(array $kohaesion): ?array
    {
        if (($kohaesion['zu_wenig'] ?? false) === true) {
            return null;
        }
        if ((int) ($kohaesion['rated_pairs'] ?? 0) < 1) {
            return null;   // kein bewertetes Paar → keine Aussage (T9), nicht „schlecht"
        }

        $score = (int) ($kohaesion['score'] ?? 0);
        $stufe = $score >= 60 ? 'gut' : ($score >= 35 ? 'schwach' : 'kritisch');

        $weakest = $kohaesion['weakest_pair'] ?? null;
        $paarHinweis = is_array($weakest)
            ? sprintf(' Schwächste Brücke: %s ↔ %s (%d).',
                (string) ($weakest['a'] ?? '?'), (string) ($weakest['b'] ?? '?'), (int) ($weakest['score'] ?? 0))
            : '';

        $text = match ($stufe) {
            'gut' => sprintf('Die Menüfolge trägt auf der Aroma-Achse (Score %d).', $score),
            'schwach' => sprintf('Die Menüfolge hängt nur lose zusammen (Score %d) — einzelne Gänge harmonieren schwach.%s', $score, $paarHinweis),
            'kritisch' => sprintf('Die Menüfolge wirkt aroma-fremd (Score %d) — die Gänge stehen kaum in Verbindung.%s', $score, $paarHinweis),
        };

        return ['stufe' => $stufe, 'score' => $score, 'text' => $text];
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
        // Spec 58 · Paket 4: nur 3★ (echtes Food Pairing) trägt einen Vorschlag — 2★ ist Rauschen.
        $diaet = $this->diaetFilter($recipe);
        foreach ($this->graph()->kanten($dishIds, null, AnkerGraph::HARMONIERT, $dishIds) as $kante) {
            $k = &$kandidaten[$kante->zu];
            $k['best'][$kante->von] = AnkerGraph::GEWICHT[AnkerGraph::HARMONIERT];
        }
        unset($k);

        $grade = $this->graph()->grad(array_keys($kandidaten));
        $namen = DB::table('foodalchemist_vocab_pairing_anchors')
            ->whereIn('id', array_merge(array_keys($kandidaten), $dishIds))   // + dish für »verbindet n/m: …«
            ->pluck('slug', 'id');

        $meta = $diaet !== null
            ? DB::table('foodalchemist_vocab_pairing_anchors')->whereIn('id', array_keys($kandidaten))
                ->get(['id', 'slug', 'category', 'subcategory'])->keyBy('id')
            : collect();

        $liste = [];
        foreach ($kandidaten as $id => $daten) {
            $cover = count($daten['best']);
            if ($cover < 2) {
                continue;                                           // Filter cover ≥ 2
            }
            if ($diaet !== null && ($m = $meta->get($id)) !== null && ! $this->passtZurDiaet($m, $diaet)) {
                continue;                                           // kein Hühnerfond fürs vegane Gericht
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

    /** Tierische Fonds/Brühen-Ausnahmen: diese Einträge in „Brühen und Fonds" sind pflanzlich. */
    private const PFLANZLICHE_FONDS = ['vegetable_bouillon', 'kombu_dashi', 'truffle_juice'];

    /**
     * Spec 58 · Paket 4: Ernährungsform des Gerichts → 'vegan' | 'vegetarisch' | null (keine Einschränkung).
     * Nur ein ausdrücklich gesetztes true schränkt ein — unbekannt ist keine Aussage.
     */
    private function diaetFilter(FoodAlchemistRecipe $recipe): ?string
    {
        if ($recipe->spec_is_vegan === true) {
            return 'vegan';
        }

        return $recipe->spec_is_vegetarian === true ? 'vegetarisch' : null;
    }

    /** Passt ein Anker (Kategorie/Unterkategorie aus dem Inspire-Vokabular) zur Ernährungsform? */
    public function passtZurDiaet(object $anker, string $diaet): bool
    {
        $kat = (string) ($anker->category ?? '');
        $sub = (string) ($anker->subcategory ?? '');
        if ($kat === 'Protein' && $sub !== 'Protein/Pflanzliche Proteine') {
            return false;                                           // Fleisch, Fisch, Meeresfrüchte, Charcuterie
        }
        if ($kat === 'Brühen und Fonds' && ! in_array($anker->slug, self::PFLANZLICHE_FONDS, true)) {
            return false;
        }
        if ($diaet === 'vegan') {
            if ($kat === 'Milchprodukte' && $sub !== 'Milchprodukte/Pflanzliche Milchprodukte') {
                return false;                                       // Milch, Käse, Butter, Sahne, Ei
            }
            if (str_contains((string) $anker->slug, 'honey')) {
                return false;
            }
        }

        return true;
    }

    // ── Bridge / verwandte Rezepte / Nachbarn (3.4 — T7) ────────────────

    public function pairingBridge(int $recipeA, int $recipeB): array
    {
        $ankerA = DB::table('foodalchemist_recipe_pairings')->where('recipe_id', $recipeA)->whereNull('deleted_at')->distinct()->pluck('anchor_id')->all();
        $ankerB = DB::table('foodalchemist_recipe_pairings')->where('recipe_id', $recipeB)->whereNull('deleted_at')->distinct()->pluck('anchor_id')->all();

        $direkte = array_values(array_intersect($ankerA, $ankerB));
        // LIMIT 30 deckelt die indirekte Zählung (Ist holt max 30 Zeilen) — COUNT ignoriert
        // LIMIT in SQL, daher explizit über die gedeckelte Ergebnisliste zählen
        $indirekte = min(30, $this->graph()->kanten($ankerA, $ankerB)->count());

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

        // Erst filtern/sortieren/deckeln — shared_slugs NUR fuer die finalen Top-N nachladen
        // (vorher: eine slug-Query je Treffer VOR ->take(), N+1 ueber alle Treffer).
        return $treffer->filter(fn ($t) => $rezepte->has($t->recipe_id))
            ->map(fn ($t) => [
                'recipe_id' => $t->recipe_id,
                'name' => $rezepte[$t->recipe_id],
                'shared' => (int) $t->shared,
                'eigene_gesamt' => (int) ($gesamt[$t->recipe_id] ?? 0),
            ])
            ->sortBy([fn ($a, $b) => [$b['shared'], $a['eigene_gesamt'], $a['recipe_id']] <=> [$a['shared'], $b['eigene_gesamt'], $b['recipe_id']]])
            ->take($limit)
            ->map(function (array $row) use ($eigene) {
                $row['shared_slugs'] = DB::table('foodalchemist_recipe_pairings AS rp')
                    ->join('foodalchemist_vocab_pairing_anchors AS a', 'a.id', '=', 'rp.anchor_id')
                    ->where('rp.recipe_id', $row['recipe_id'])->whereIn('rp.anchor_id', $eigene)->whereNull('rp.deleted_at')
                    ->distinct()->limit(5)->pluck('a.slug')->all();

                return $row;
            })
            ->values();
    }

    public function ankerNeighbors(string $slug, ?string $typ = null, int $limit = 30): Collection
    {
        $limit = max(1, min(200, $limit));
        $ankerId = DB::table('foodalchemist_vocab_pairing_anchors')->where('slug', $slug)->value('id');
        if ($ankerId === null) {
            return collect();
        }

        // Spec 60 · P2: Partner aus der Harmonie ({@see AnkerGraph}), stärkste Stufe zuerst. Es gibt
        // nur noch einen Kantentyp (`aroma`, Inspire); ein anderer `$typ` hat keine Partner. Die
        // Felder type/evidence/axis/level/weight bleiben für die Konsumenten (pairingBlock,
        // forGeneration, neighborsForName, pairings.GET) in derselben Form erhalten.
        if ($typ !== null && $typ !== 'aroma') {
            return collect();
        }

        return $this->graph()->partner((int) $ankerId, AnkerGraph::PASST, $limit)
            ->map(fn ($p) => (object) [
                'id' => $p->id, 'slug' => $p->slug, 'display_de' => $p->display_de,
                'type' => 'aroma',
                'evidence' => $p->stufe === AnkerGraph::HARMONIERT ? 'Foodpairing Inspire (best match)' : 'Foodpairing Inspire (good match)',
                'axis' => 'harmony',
                'level' => $p->stufe,
                'weight' => AnkerGraph::GEWICHT[$p->stufe],
            ]);
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

    /** KI-Inferenz fuer die Vollanreicherung; manuelle Mappings werden nie ersetzt. */
    public function setRecipeAnkerInference(Team $team, int $recipeId, int $ankerId, float $confidence): void
    {
        $recipe = FoodAlchemistRecipe::visibleToTeam($team)->findOrFail($recipeId);
        $manual = DB::table('foodalchemist_recipe_anchor_mappings')
            ->where('recipe_id', $recipe->id)->where('anchor_id', $ankerId)
            ->where('source', 'manual')->exists();
        if ($manual) {
            return;
        }
        DB::table('foodalchemist_recipe_anchor_mappings')->updateOrInsert(
            ['recipe_id' => $recipe->id, 'anchor_id' => $ankerId],
            ['uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(), 'team_id' => $team->id,
                'role' => 'kern', 'source' => 'ai_inferred', 'ai_confidence' => max(0, min(1, $confidence)),
                'ai_reasoning' => 'Vollanreicherung', 'deleted_at' => null,
                'updated_at' => now(), 'created_at' => now()],
        );
    }

    public function removeRecipeAnker(Team $team, int $recipeId, int $ankerId): void
    {
        FoodAlchemistRecipe::visibleToTeam($team)->findOrFail($recipeId);
        DB::table('foodalchemist_recipe_anchor_mappings')
            ->where('recipe_id', $recipeId)->where('anchor_id', $ankerId)->update(['deleted_at' => now()]);
    }

    /**
     * GP-Aroma-Anker setzen/aktualisieren (Gegenstück zu setRecipeAnker; Tabelle gp_anchor_mappings,
     * CAP_GP — mehrere Anker je GP erlaubt, s. Altdaten: 433 GPs mit >1 Anker, z. B. Ratatouille →
     * eggplant/tomato/zucchini). `role` unterscheidet Haupt- von Nebenträger (kern|neben, Default
     * kern); `source`/`ai_confidence`/`ai_reasoning` sind offen für den MCP-Import (Spec 53 Paket J:
     * `bridge_alt_neu`, `exact_name`, … — nicht nur `manual`).
     */
    /**
     * Spaltenbreite `foodalchemist_gp_anchor_mappings.source` (Migration
     * `2026_09_19_000001_widen_gp_anchor_mapping_source`). Hier statt eines rohen SQLSTATE
     * 22001 geprüft — 506 Zeilen des Vault-Bridge-Imports (`bridge_alt_neu+exact`, 20 Zeichen)
     * fielen genau darauf herein, bevor die Spalte 16 Zeichen hatte.
     */
    public const GP_ANKER_SOURCE_MAX = 32;

    public function setGpAnker(
        Team $team, int $gpId, int $ankerId, string $role = 'kern', string $source = 'manual',
        ?float $aiConfidence = null, ?string $aiReasoning = null,
    ): void {
        $role = in_array($role, ['kern', 'neben'], true) ? $role : 'kern';
        if (mb_strlen($source) > self::GP_ANKER_SOURCE_MAX) {
            throw new \RuntimeException(
                'source zu lang (max. ' . self::GP_ANKER_SOURCE_MAX . ' Zeichen): "' . $source . '" hat ' . mb_strlen($source) . '.'
            );
        }
        $gp = \Platform\FoodAlchemist\Models\FoodAlchemistGp::visibleToTeam($team)->findOrFail($gpId);
        $vorhanden = DB::table('foodalchemist_gp_anchor_mappings')
            ->where('gp_id', $gp->id)->where('anchor_id', $ankerId)->whereNull('deleted_at')->first();
        if ($vorhanden === null
            && DB::table('foodalchemist_gp_anchor_mappings')->where('gp_id', $gp->id)->whereNull('deleted_at')->count() >= self::CAP_GP) {
            throw new \RuntimeException('Limit erreicht: max ' . self::CAP_GP . ' Kern-Anker pro GP.');
        }
        DB::table('foodalchemist_gp_anchor_mappings')->updateOrInsert(
            ['gp_id' => $gp->id, 'anchor_id' => $ankerId],
            ['uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(), 'team_id' => $team->id, 'role' => $role,
                'source' => $source, 'ai_confidence' => $aiConfidence, 'ai_reasoning' => $aiReasoning,
                'deleted_at' => null, 'updated_at' => now(), 'created_at' => now()],
        );
    }

    /** Gegenstück zu removeRecipeAnker — löst einen einzelnen GP-Anker (soft-delete). */
    public function removeGpAnker(Team $team, int $gpId, int $ankerId): void
    {
        \Platform\FoodAlchemist\Models\FoodAlchemistGp::visibleToTeam($team)->findOrFail($gpId);
        DB::table('foodalchemist_gp_anchor_mappings')
            ->where('gp_id', $gpId)->where('anchor_id', $ankerId)->update(['deleted_at' => now()]);
    }

    /**
     * Alle Anker eines GP löschen (soft-delete) — für den MCP-Import mit `ersetze_alle=true`
     * (Spec 53 Paket J): die gelieferte Anker-Liste ersetzt den bisherigen Bestand vollständig.
     */
    public function clearGpAnker(Team $team, int $gpId): void
    {
        \Platform\FoodAlchemist\Models\FoodAlchemistGp::visibleToTeam($team)->findOrFail($gpId);
        DB::table('foodalchemist_gp_anchor_mappings')
            ->where('gp_id', $gpId)->whereNull('deleted_at')->update(['deleted_at' => now()]);
    }

    /**
     * Spec 50 · B-7: KI-Inferenz des GP-Kern-Ankers (Bulk-Schritt `anker`). Manuelle Mappings
     * werden nie ersetzt (Inv. 3); ältere `ai_inferred`-Mappings desselben GPs weichen, damit
     * der Bulk-Lauf EINEN Kern-Anker setzt und den CAP_GP nicht mit KI-Resten füllt.
     * `neutral` ist ein gültiger Anker („kein Aroma-Träger" ist eine Entscheidung).
     */
    public function setGpAnkerInference(Team $team, int $gpId, int $ankerId, float $confidence, string $reasoning = 'Bulk-Anreicherung'): void
    {
        $gp = \Platform\FoodAlchemist\Models\FoodAlchemistGp::visibleToTeam($team)->findOrFail($gpId);
        $manual = DB::table('foodalchemist_gp_anchor_mappings')
            ->where('gp_id', $gp->id)->where('anchor_id', $ankerId)->where('source', 'manual')->exists();
        if ($manual) {
            return;
        }
        DB::table('foodalchemist_gp_anchor_mappings')->where('gp_id', $gp->id)
            ->where('anchor_id', '!=', $ankerId)->where('source', 'ai_inferred')->whereNull('deleted_at')
            ->update(['deleted_at' => now(), 'updated_at' => now()]);
        DB::table('foodalchemist_gp_anchor_mappings')->updateOrInsert(
            ['gp_id' => $gp->id, 'anchor_id' => $ankerId],
            ['uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(), 'team_id' => $team->id,
                'role' => 'kern', 'source' => 'ai_inferred', 'ai_confidence' => max(0, min(1, $confidence)),
                'ai_reasoning' => mb_strimwidth($reasoning, 0, 500), 'deleted_at' => null,
                'updated_at' => now(), 'created_at' => now()],
        );
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
    public function setRecipePairing(Team $team, int $recipeId, int $ankerId, string $typ = 'aroma'): void
    {
        $recipe = FoodAlchemistRecipe::visibleToTeam($team)->findOrFail($recipeId);
        // erprobt ist gewipt — manuelle Pairings nur noch aroma/kontrast/verbund/trinitas.
        $typ = in_array($typ, ['aroma', 'kontrast', 'verbund', 'trinitas'], true) ? $typ : 'aroma';
        DB::table('foodalchemist_recipe_pairings')->updateOrInsert(
            ['recipe_id' => $recipe->id, 'anchor_id' => $ankerId, 'type' => $typ],
            ['uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(), 'team_id' => $team->id,
                'confidence' => 'hoch', 'created_via' => 'manual', 'note' => null,
                'deleted_at' => null, 'updated_at' => now(), 'created_at' => now()],
        );
    }

    /** Geerdetes KI-Pairing; manuelle Zeilen bleiben unangetastet und gewinnen im Panel. */
    public function setRecipePairingInference(Team $team, int $recipeId, int $ankerId, string $typ, string $confidence): void
    {
        $recipe = FoodAlchemistRecipe::visibleToTeam($team)->findOrFail($recipeId);
        $typ = in_array($typ, ['aroma', 'kontrast'], true) ? $typ : 'aroma';
        $manual = DB::table('foodalchemist_recipe_pairings')
            ->where('recipe_id', $recipe->id)->where('anchor_id', $ankerId)->where('type', $typ)
            ->where('created_via', 'manual')->exists();
        if ($manual) {
            return;
        }
        DB::table('foodalchemist_recipe_pairings')->updateOrInsert(
            ['recipe_id' => $recipe->id, 'anchor_id' => $ankerId, 'type' => $typ],
            ['uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(), 'team_id' => $team->id,
                'confidence' => in_array($confidence, ['hoch', 'mittel', 'niedrig'], true) ? $confidence : 'mittel',
                'created_via' => 'ai_gateway', 'note' => 'Geerdet durch Vollanreicherung',
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

    /** Kern-Aroma-Anker eines GP inkl. Slug/Quelle (GP-Pairing-Panel, Aroma-Ähnlichkeit/Ersatz-Logik). */
    public function gpAnkers(int $gpId): Collection
    {
        return DB::table('foodalchemist_gp_anchor_mappings AS m')
            ->join('foodalchemist_vocab_pairing_anchors AS a', 'a.id', '=', 'm.anchor_id')
            ->where('m.gp_id', $gpId)->where('m.role', 'kern')->whereNull('m.deleted_at')
            ->orderByRaw('COALESCE(m.ai_confidence, 1.0) DESC')->orderBy('m.id')
            ->get(['a.id', 'a.slug', 'a.display_de', 'm.source', 'm.ai_confidence']);
    }

    /**
     * ALLE Anker eines GP (kern UND neben), inkl. `role` — für den Editor-Block und das Detail-Panel
     * (Spec 53 Paket J). Anders als {@see gpAnkers}, das bewusst nur `kern` liefert (Aroma-Ähnlichkeit/
     * Ersatz-Logik nutzt nur den Haupt-Aromaträger) — hier soll der Kurator BEIDE Rollen sehen/pflegen.
     */
    public function gpAnkerAlle(int $gpId): Collection
    {
        return DB::table('foodalchemist_gp_anchor_mappings AS m')
            ->join('foodalchemist_vocab_pairing_anchors AS a', 'a.id', '=', 'm.anchor_id')
            ->where('m.gp_id', $gpId)->whereNull('m.deleted_at')
            ->orderByRaw("CASE m.role WHEN 'kern' THEN 1 ELSE 2 END")->orderBy('a.display_de')
            ->get(['a.id', 'a.slug', 'a.display_de', 'm.role', 'm.source', 'm.ai_confidence']);
    }

    /**
     * Spec 19 E9.2: Anker → GP Reverse-Lookup („welche echten GPs tragen Aroma X als Kern").
     * Gegenrichtung zu gpAnkers(); zuvor nur privat in aromaTrueSubstitutes eingebettet
     * (Doc-TODO D-7_pairing.md §GL-10 3.4). Team-scoped, ohne Derivate/Platzhalter (die kauft
     * man nicht als Aromaträger). Read-only.
     *
     * @param  list<int>  $ankerIds  Kern-Anker-IDs
     * @return \Illuminate\Support\Collection<int, object>  GP-Zeilen {id, name, lead_la_supplier_item_id, is_favorite, status, requires_la, anchor_id}
     */
    public function gpsForAnkerIds(Team $team, array $ankerIds): Collection
    {
        $ankerIds = array_values(array_unique(array_map('intval', $ankerIds)));
        if ($ankerIds === []) {
            return collect();
        }
        // Anker → gp_id-Zuordnung (kern), damit der Aufrufer weiß, welcher GP welches Aroma trägt.
        $mapping = DB::table('foodalchemist_gp_anchor_mappings')
            ->whereIn('anchor_id', $ankerIds)->where('role', 'kern')->whereNull('deleted_at')
            ->get(['gp_id', 'anchor_id']);
        $gpIds = $mapping->pluck('gp_id')->map(fn ($i) => (int) $i)->unique()->all();
        if ($gpIds === []) {
            return collect();
        }
        $gps = \Platform\FoodAlchemist\Models\FoodAlchemistGp::visibleToTeam($team)
            ->whereIn('id', $gpIds)
            ->where('is_derivat', false)->where('is_platzhalter', false)
            ->get(['id', 'name', 'lead_la_supplier_item_id', 'is_favorite', 'status', 'requires_la'])
            ->keyBy('id');

        // Eine Zeile pro (GP, Anker) — der Aufrufer gruppiert nach Anker (Inspiration) bzw. GP (Erdung).
        return $mapping
            ->filter(fn ($m) => $gps->has((int) $m->gp_id))
            ->map(function ($m) use ($gps) {
                $gp = $gps->get((int) $m->gp_id);

                return (object) [
                    'id' => (int) $gp->id, 'name' => (string) $gp->name,
                    'lead_la_supplier_item_id' => $gp->lead_la_supplier_item_id !== null ? (int) $gp->lead_la_supplier_item_id : null,
                    'is_favorite' => (bool) $gp->is_favorite,
                    'status' => is_object($gp->status) ? $gp->status->value : (string) $gp->status,
                    'requires_la' => (bool) $gp->requires_la,
                    'anchor_id' => (int) $m->anchor_id,
                ];
            })->values();
    }

    // ── Kompakt-Panels fürs »Sensorik & Pairing«-Tab (read-only) ─────────

    /** Prozess-/neutrale Anker sind keine Zutat-Vorschläge (»Fermentiert« kauft man nicht). */
    private const NICHT_ZUTAT_ANKER = ['neutral', 'roestaromen', 'ferment', 'karamell', 'rauch'];

    /**
     * Aroma-Nachbarn eines Kanten-Typs über mehrere Anker, dedupliziert, ohne die eigenen.
     * Quelle = dieselben Anker-Kanten wie der Pairing-Netz-Graph (klassisch | kontrast). Ranking
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
            // erprobt ist gewipt → vertrauenswürdige Harmonie-Nachbarn (Inspire+Molekül).
            $nachbarn = $this->ankerNachbarnAggregiert($slugs, $eigene, 'aroma');
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
            // erprobt ist gewipt → vertrauenswürdige Harmonie-Nachbarn.
            'nachbarn' => $this->ankerNachbarnAggregiert($slugs, $eigene, 'aroma'),
            'aroma' => $this->ankerNachbarnAggregiert($slugs, $eigene, 'aroma'),
            'kontrast' => $this->ankerNachbarnAggregiert($slugs, $eigene, 'kontrast'),
        ];
    }

    // ── M5-07: Pairing-Netz-Graph (D-7) ───────────────────────────────────
    // 2026-07-22 Empfehler-Redesign: statt »Anker-Netzwerk des Rezepts« zeigt das
    // Netz jetzt »was passt ZUM Gericht« — Kandidaten nach Typ (erprobt/aroma/
    // kontrast) in Sektoren + komplementäre Basisrezepte. Dish-zentrisch,
    // clientseitig nach Typ filterbar. Positionen serverseitig fix (keine Simulation).

    private const CANVAS_W = 1200.0;

    private const CANVAS_H = 980.0;

    // Dish-zentrisches Empfehler-Layout (2026-07-22 Redesign): Gericht in der
    // Mitte, eigene Kern-Anker auf einem kleinen Innenring, Pairing-Kandidaten
    // in TYP-SEKTOREN aussen (erprobt oben, aroma oben-rechts, kontrast oben-links),
    // komplementäre Basisrezepte unten. Beantwortet »was passt DAZU, nach Typ«.
    // Konzentrische Kreise (Foodpairing-Look): Gericht in der Mitte, Kern-Anker
    // auf einem kleinen Innenring, die ERPROBTEN Kandidaten als voller Kreis
    // darum (wie in der Vorschau), und aroma + kontrast + Basisrezepte gemeinsam
    // als äusserer Kreis drumherum (nach Typ in zusammenhängende Bögen sortiert).
    private const R_ANKER = 150.0;      // Innenring Kern-Anker

    private const R_BEST = 320.0;       // mittlerer Vollkreis: best-Kandidaten (Inspire L3, ★★★)

    private const R_OUTER = 470.0;      // Aussenkreis: harmonie (★★/★) + kontrast (⇄) + Basisrezepte

    private const KANDIDATEN_PRO_TYP = 14;   // Cap je Typ

    private const BASIS_MAX = 10;

    private const INNER_ANKER_MAX = 12;

    /**
     * Pairing-Empfehler fürs Netz (2026-07-22 Redesign): beantwortet »was passt
     * zum Gericht, getrennt nach erprobt / aroma / kontrast«.
     *
     * Zentrum = Gericht, Innenring = eigene Kern-Anker (Geschmacks-Identität),
     * aussen die PAIRING-KANDIDATEN in Typ-Sektoren (erprobt oben, aroma oben-
     * rechts, kontrast oben-links) — jeder Kandidat ist ein Aroma-Partner der
     * Kern-Anker, gerankt nach dish_cover (wie viele Kern-Anker er bedient) +
     * Gewicht. Unten die KOMPLEMENTÄREN Basisrezepte (Rezepte, die auf einem
     * passenden Partner aufbauen). Typ-Filterung passiert clientseitig (Chips) —
     * jeder Kandidat/jede Kante trägt ihren `typ`. Positionen serverseitig fix
     * (reine Formel, keine Simulation).
     *
     * @param  int  $vorschlaegeProAnker  Legacy-Parameter, ignoriert (Kandidaten
     *                                     sind jetzt immer Teil des Netzes).
     * @return array{nodes: list<array>, edges: list<array>, meta: array}
     */
    public function pairingNetz(Team $team, int $recipeId, int $vorschlaegeProAnker = 0): array
    {
        $recipe = FoodAlchemistRecipe::visibleToTeam($team)->find($recipeId);
        if ($recipe === null) {
            return ['nodes' => [], 'edges' => [], 'meta' => ['recipe_id' => $recipeId]];
        }

        // Spec 58 · Paket 3: Innenring = die Anker der BESTANDTEILE (dieselbe Auflösung wie Zusammenhalt
        // und Harmonie/Kontrast — eine Wahrheit). Vorher kam er aus dem Mapping-Beutel am Gericht (bis 24
        // KI-Anker) und zeigte andere Anker als der Score. Beutel und Pairing-Anker bleiben Rückfall für
        // Gerichte ohne auflösbare Zutaten.
        $kernIds = collect($this->resolveRecipeAnchors($recipe))->pluck('kern')->filter()->map(fn ($v) => (int) $v)->unique()->values();
        $inner = $kernIds->isEmpty() ? collect() : DB::table('foodalchemist_vocab_pairing_anchors')
            ->whereIn('id', $kernIds->all())->whereNull('deleted_at')->get(['id', 'slug', 'display_de'])
            ->sortBy(fn ($a) => $kernIds->search((int) $a->id))->values()
            ->map(fn ($a) => ['id' => (int) $a->id, 'slug' => $a->slug, 'display_de' => $a->display_de]);
        if ($inner->isEmpty()) {
            $inner = $this->recipeAnkers($recipeId)
                ->map(fn ($a) => ['id' => (int) $a->id, 'slug' => $a->slug, 'display_de' => $a->display_de]);
        }
        if ($inner->isEmpty()) {
            $inner = DB::table('foodalchemist_recipe_pairings AS rp')
                ->join('foodalchemist_vocab_pairing_anchors AS a', 'a.id', '=', 'rp.anchor_id')
                ->where('rp.recipe_id', $recipeId)->whereNull('rp.deleted_at')
                ->distinct()->get(['a.id', 'a.slug', 'a.display_de'])
                ->map(fn ($a) => ['id' => (int) $a->id, 'slug' => $a->slug, 'display_de' => $a->display_de]);
        }

        return $this->baueNetz($team, $inner, (string) $recipe->name, $recipeId);
    }

    /**
     * Ad-hoc-Netz aus einer freien Anker-Menge (Planungs-Composer): kein Rezept,
     * Zentrum = frei benennbare „Komposition". Gleiche Downstream-Logik wie
     * {@see pairingNetz} — nur der Innenring kommt direkt als Anker-ID-Liste
     * (Auswahl-Reihenfolge bleibt erhalten).
     *
     * @param  array<int>  $ankerIds
     */
    public function pairingNetzForAnkers(Team $team, array $ankerIds, string $centerLabel = 'Komposition'): array
    {
        $ids = array_values(array_unique(array_map('intval', $ankerIds)));
        if ($ids === []) {
            return ['nodes' => [], 'edges' => [], 'meta' => ['recipe_id' => 0]];
        }
        $inner = DB::table('foodalchemist_vocab_pairing_anchors')
            ->whereIn('id', $ids)
            ->get(['id', 'slug', 'display_de'])
            ->map(fn ($a) => ['id' => (int) $a->id, 'slug' => $a->slug, 'display_de' => $a->display_de])
            ->sortBy(fn ($a) => array_search($a['id'], $ids, true))->values();

        return $this->baueNetz($team, $inner, $centerLabel, 0, true);
    }

    /**
     * Gemeinsamer Netz-Aufbau (rezept- ODER anker-getrieben). $inner = roher Innenring
     * (wird auf INNER_ANKER_MAX gekappt). $recipeId nur für meta + Selbst-Ausschluss der
     * komplementären Basisrezepte (0 = keiner). $centerLabel = Label des Zentrum-Knotens.
     *
     * @param  \Illuminate\Support\Collection<int,array{id:int,slug:string,display_de:?string}>  $inner
     */
    private function baueNetz(Team $team, \Illuminate\Support\Collection $inner, string $centerLabel, int $recipeId, bool $withBridges = false): array
    {
        $cx = self::CANVAS_W / 2;
        $cy = self::CANVAS_H / 2;

        $inner = $inner->unique('id')->take(self::INNER_ANKER_MAX)->values();
        $innerIds = $inner->pluck('id')->all();

        $edges = [];
        $ankerNodes = [];
        $nInner = max(1, $inner->count());
        foreach ($inner as $i => $a) {
            $w = 2 * M_PI * $i / $nInner - M_PI / 2;
            $x = round($cx + self::R_ANKER * cos($w), 1);
            $y = round($cy + self::R_ANKER * sin($w), 1);
            $ankerNodes[] = [
                'id' => 'a:'.$a['id'], 'kind' => 'anker', 'label' => $a['display_de'], 'slug' => $a['slug'],
                'kern' => true, 'x' => $x, 'y' => $y,
            ];
            $edges[] = ['source' => 'z', 'target' => 'a:'.$a['id'], 'kind' => 'zentrum_anker', 'visible' => true];
        }

        // ── Innere Ebene: Kanten ZWISCHEN den Kern-Ankern ───────────────────
        // Zeigt, wie die ausgewählten Anker laut Foodpairing-Matrix zusammenhängen
        // (die eigentliche Beweisführung): Best-/Good-Match zwischen den violetten
        // Ankern selbst, nicht nur Anker→Zutat. Kein Match → keine Linie.
        $ankerKanten = $this->innerAnkerKanten($innerIds);
        $edges = array_merge($edges, $ankerKanten);

        // ── Kandidaten: Aroma-Partner der Kern-Anker (ausserhalb), typisiert ──
        [$kandidaten, $candMeta] = $this->kandidatenFuerAnker($innerIds);

        // ── Brücken-Ebene (nur Composer): wie hängen die Anker über GETEILTE Partner
        // zusammen? Direkte Anker↔Anker-Kanten sind in Inspire fast immer leer — die
        // Verbindung läuft über gemeinsame Partner (cover ≥ 2). Macht das im Graphen
        // sichtbar (Linie zwischen Ankern, Dicke = #geteilte Partner) + ehrliche Kohäsion.
        $bridgeMeta = null;
        if ($withBridges) {
            $ankerLabel = [];
            foreach ($inner as $a) {
                $ankerLabel[(int) $a['id']] = $a['display_de'] ?: $a['slug'];
            }
            $pairPartners = []; // "a:b" (a<b) => [partnerLabel, …]
            $touched = array_fill_keys($innerIds, false);
            // Externe Partner-Zahl je Anker (im Kandidat-Raum = „Grad"). Basis für die
            // NORMALISIERTE Brücken-Stärke: nicht die rohe Anzahl geteilter Partner (hub-
            // verzerrt → alles wirkt „Best"), sondern ihr ANTEIL am kleineren Grad
            // (Overlap-Koeffizient). Zählt ALLE Kandidaten, die den Anker bedienen — auch
            // cover-1 — also vor dem <2-served-Skip.
            $degCand = array_fill_keys($innerIds, 0);
            foreach ($kandidaten as $c) {
                $served = array_values(array_unique(array_map(static fn ($p) => (int) $p['anker_id'], $c['partner'])));
                foreach ($served as $s) {
                    if (isset($degCand[$s])) {
                        $degCand[$s]++;
                    }
                }
                if (count($served) < 2) {
                    continue;
                }
                sort($served);
                $pl = $c['display_de'] ?: $c['slug'];
                $n = count($served);
                for ($i = 0; $i < $n; $i++) {
                    for ($j = $i + 1; $j < $n; $j++) {
                        $pairPartners[$served[$i].':'.$served[$j]][] = $pl;
                        $touched[$served[$i]] = true;
                        $touched[$served[$j]] = true;
                    }
                }
            }
            $tierCount = ['best' => 0, 'good' => 0, 'match' => 0];
            foreach ($pairPartners as $key => $partners) {
                [$a, $b] = array_map('intval', explode(':', $key));
                $shared = count($partners);
                // Overlap-Koeffizient: geteilte Partner / kleinerer Grad. Entzerrt Hubs —
                // 6 geteilte bei zwei 8er-Ankern (0,75 = Best) ≠ 6 bei einem 65er (0,09 = Match).
                $minDeg = max(1, min($degCand[$a] ?? 1, $degCand[$b] ?? 1));
                $overlap = $shared / $minDeg;
                $tier = $overlap >= 0.4 ? 'best' : ($overlap >= 0.2 ? 'good' : 'match');
                $tierCount[$tier]++;
                $edges[] = ['source' => 'a:'.$a, 'target' => 'a:'.$b, 'kind' => 'bridge',
                    'shared' => $shared,
                    'overlap' => (int) round($overlap * 100),
                    'tier' => $tier,
                    'partners' => array_slice(array_values(array_unique($partners)), 0, 6),
                    'visible' => true];
            }
            // Direkte Anker-Kanten (selten) zählen ebenfalls als „verbunden".
            $directTouched = [];
            foreach ($ankerKanten as $e) {
                $directTouched[(int) substr($e['source'], 2)] = true;
                $directTouched[(int) substr($e['target'], 2)] = true;
            }
            // Orphan = weder geteilter Partner noch direkte Kante → Flag am Anker-Knoten.
            $orphanLabels = [];
            foreach ($ankerNodes as &$an) {
                $aid = (int) substr($an['id'], 2);
                $isOrphan = ! ($touched[$aid] ?? false) && ! ($directTouched[$aid] ?? false);
                $an['orphan'] = $isOrphan;
                if ($isOrphan) {
                    $orphanLabels[] = $ankerLabel[$aid] ?? (string) $aid;
                }
            }
            unset($an);
            $topCount = [];
            foreach ($pairPartners as $partners) {
                foreach (array_unique($partners) as $pl) {
                    $topCount[$pl] = ($topCount[$pl] ?? 0) + 1;
                }
            }
            arsort($topCount);
            $nReal = count($innerIds);
            $bridgeMeta = [
                'pairs_connected' => count($pairPartners),
                'pairs_total' => $nReal >= 2 ? (int) ($nReal * ($nReal - 1) / 2) : 0,
                'top' => array_slice(array_keys($topCount), 0, 5),
                'orphans' => $orphanLabels,
                // Verteilung der Verbindungs-Stärke (normalisierter Overlap-Tier) — trägt den
                // „davon N stark"-Zusatz in der Kohäsions-Lesung, damit die Stärke auch im Text steht.
                'tiers' => $tierCount,
            ];
        }

        $jeTyp = fn ($typ) => array_slice(
            (function () use ($kandidaten, $typ) {
                $l = array_values(array_filter($kandidaten, fn ($c) => $c['typ'] === $typ));
                usort($l, fn ($x, $y) => [$y['cover'], $y['weight'], $x['slug']] <=> [$x['cover'], $x['weight'], $y['slug']]);

                return $l;
            })(),
            0, self::KANDIDATEN_PRO_TYP
        );
        // Zweistufiges Inspire-Modell: nur ★★★ (L3) + ★★ (L2). stern1 (★) ist
        // strukturell leer (Inspire kennt kein L1) und aus dem UI entfernt.
        $stern3 = $jeTyp('stern3');
        $stern2 = $jeTyp('stern2');

        $basis = $this->komplementaerBasisrezepte($team, $recipeId, $candMeta);

        $kandidatNodes = [];
        $basisNodes = [];

        // Mittlerer Vollkreis: ★★★-Kandidaten (Inspire L3, Best-Match) gleichmässig rundum.
        $mB = max(1, count($stern3));
        foreach ($stern3 as $idx => $c) {
            [$x, $y] = $this->positionAufKreis($idx, $mB, self::R_BEST, $cx, $cy);
            $kandidatNodes[] = [
                'id' => 'k:'.$c['id'], 'kind' => 'kandidat', 'typ' => 'stern3', 'level' => 3,
                'label' => $c['display_de'], 'slug' => $c['slug'], 'cover' => $c['cover'], 'x' => $x, 'y' => $y,
            ];
            foreach ($c['partner'] as $p) {
                $edges[] = ['source' => 'k:'.$c['id'], 'target' => 'a:'.$p['anker_id'], 'kind' => 'kandidat',
                    'typ' => $p['typ'], 'level' => $p['level'] ?? 1, 'weight' => $p['weight'],
                    'visible' => true];
            }
        }

        // Äusserer Vollkreis: ★★ (Inspire L2) + Basisrezepte,
        // in zusammenhängenden Bögen rund um den ★★★-Kreis.
        $outerTotal = max(1, count($stern2) + count($basis));
        $oi = 0;
        foreach ([['stern2', $stern2]] as [$typ, $liste]) {
            foreach ($liste as $c) {
                [$x, $y] = $this->positionAufKreis($oi++, $outerTotal, self::R_OUTER, $cx, $cy);
                $kandidatNodes[] = [
                    'id' => 'k:'.$c['id'], 'kind' => 'kandidat', 'typ' => $typ, 'level' => $c['level'] ?? (int) substr($typ, -1),
                    'label' => $c['display_de'], 'slug' => $c['slug'], 'cover' => $c['cover'], 'x' => $x, 'y' => $y,
                ];
                foreach ($c['partner'] as $p) {
                    $edges[] = ['source' => 'k:'.$c['id'], 'target' => 'a:'.$p['anker_id'], 'kind' => 'kandidat',
                        'typ' => $p['typ'], 'level' => $p['level'] ?? 1, 'weight' => $p['weight'],
                        'visible' => true];
                }
            }
        }
        foreach ($basis as $b) {
            [$x, $y] = $this->positionAufKreis($oi++, $outerTotal, self::R_OUTER, $cx, $cy);
            $basisNodes[] = [
                'id' => 'b:'.$b['recipe_id'], 'kind' => 'basisrezept', 'typ' => $b['typ'], 'label' => $b['name'],
                'recipe_id' => $b['recipe_id'], 'via' => $b['via_slug'], 'x' => $x, 'y' => $y,
            ];
            $edges[] = ['source' => 'b:'.$b['recipe_id'], 'target' => 'a:'.$b['anker_id'], 'kind' => 'basis',
                'typ' => $b['typ'], 'visible' => true];
        }

        $nodes = array_merge(
            [['id' => 'z', 'kind' => 'zentrum', 'label' => $centerLabel, 'x' => $cx, 'y' => $cy]],
            $ankerNodes, $kandidatNodes, $basisNodes
        );

        return [
            'nodes' => $nodes,
            'edges' => $edges,
            'meta' => [
                'recipe_id' => $recipeId,
                'canvas_w' => self::CANVAS_W,
                'canvas_h' => self::CANVAS_H,
                // Inhalts-Signatur (Knoten-IDs in Reihenfolge): keyt die wire:ignore-D3-Insel
                // in Vorschau + Modal. Ändert sich der Ankersatz (o. die Kandidaten), ändert
                // sich sig → wire:key wechselt → Livewire ersetzt die Insel → D3 re-initialisiert
                // mit frischen Daten. Ohne das friert das Modal auf dem Erst-Öffnungsstand ein.
                'sig' => substr(md5(implode('|', array_map(static fn ($n) => $n['id'], $nodes))), 0, 10),
                // Filter-Defaults: beide Stern-Stufen an (zweistufige Inspire-Harmonie).
                'typ_default' => ['stern3' => true, 'stern2' => false],   // Spec 58: 2★ = Rauschen, nur auf Wunsch
                'counts' => [
                    'stern3' => count(array_filter($kandidatNodes, fn ($n) => $n['typ'] === 'stern3')),
                    'stern2' => count(array_filter($kandidatNodes, fn ($n) => $n['typ'] === 'stern2')),
                    'basis' => count($basisNodes),
                    // Kanten zwischen den Kern-Ankern (innere Ebene).
                    'anker_anker' => count($ankerKanten),
                ],
                // Brücken-Zusammenfassung (nur Composer/withBridges, sonst null).
                'bridge' => $bridgeMeta,
            ],
        ];
    }

    /**
     * Composer: Kohäsion einer freien Anker-Menge — „passen die gewählten Anker zusammen?".
     * Baut die komponenten-Struktur aus den Ankern und delegiert an {@see cohesionFor}
     * (liefert score/min_score/weakest_pair + je Anker fit/is_orphan = der „passt-nicht"-Flag).
     *
     * @param  array<int>  $ankerIds
     */
    public function composerCohesion(array $ankerIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $ankerIds)));
        $leer = ['score' => 0, 'min_score' => 0, 'rated_pairs' => 0, 'total_pairs' => 0,
            'coverage_pct' => 0, 'weakest_pair' => null, 'unrated_pairs' => [], 'komponenten' => []];
        if (count($ids) < 2) {
            return $leer;
        }
        $rows = DB::table('foodalchemist_vocab_pairing_anchors')
            ->whereIn('id', $ids)->get(['id', 'slug', 'display_de'])->keyBy('id');

        $komponenten = [];
        foreach ($ids as $id) {
            $a = $rows->get($id);
            if ($a === null) {
                continue;
            }
            $komponenten[] = [
                'kern' => (int) $a->id,
                'prozess' => [],
                'label' => $a->display_de ?: $a->slug,
                'via' => $a->slug,
            ];
        }

        return $this->cohesionFor($komponenten);
    }

    /**
     * Composer-Picker: browsebare Anker-Liste (wie der GP-Picker `IngredientEditor::browseKatalog`).
     * Gefiltert nach Kategorie + Suche, aktuelle Auswahl ausgeschlossen; je Anker ein Best/Good-Badge
     * relativ zur Auswahl (aus {@see kandidatenFuerAnker}) + das Kategorie-Vokabular für den Dropdown.
     *
     * @param  array<int>  $selectedIds
     * @return array{items: list<array>, total: int, kategorien: list<string>}
     */
    public function composerAnkerBrowse(Team $team, string $q, ?string $category, array $selectedIds, int $limit = 200, ?int $focusId = null): array
    {
        $selected = array_values(array_unique(array_map('intval', $selectedIds)));
        $q = trim($q);

        $query = DB::table('foodalchemist_vocab_pairing_anchors')
            ->whereNull('deleted_at')
            ->when($q !== '', function ($w) use ($q) {
                $like = '%'.$q.'%';
                $w->where(fn ($x) => $x->where('slug', 'like', $like)->orWhere('display_de', 'like', $like));
            })
            ->when($category !== null && $category !== '', fn ($w) => $w->where('category', $category))
            ->when($selected !== [], fn ($w) => $w->whereNotIn('id', $selected));

        $total = (clone $query)->count();
        $rows = $query->orderBy('display_de')->limit($limit)->get(['id', 'slug', 'display_de', 'category']);

        // Best/Good-Badge: bei Fokus relativ zum EINEN fokussierten Anker („was passt zu X"),
        // sonst relativ zur ganzen Auswahl.
        $badgeBasis = $focusId !== null ? [$focusId] : $selected;
        $badge = [];
        if ($badgeBasis !== []) {
            [$kand] = $this->kandidatenFuerAnker($badgeBasis);
            foreach ($kand as $c) {
                $badge[(int) $c['id']] = $c['typ']; // stern3 | stern2
            }
        }

        $kategorien = DB::table('foodalchemist_vocab_pairing_anchors')
            ->whereNull('deleted_at')->whereNotNull('category')
            ->distinct()->orderBy('category')->pluck('category')->all();

        return [
            'items' => $rows->map(fn ($a) => [
                'id' => (int) $a->id,
                'slug' => $a->slug,
                'label' => $a->display_de ?: $a->slug,
                'category' => $a->category,
                'typ' => $badge[(int) $a->id] ?? null,
            ])->all(),
            'total' => $total,
            'kategorien' => $kategorien,
        ];
    }

    /**
     * Kanten ZWISCHEN den Kern-Ankern (innere Ebene des Netzes). Beantwortet
     * „wie hängen die ausgewählten Anker untereinander zusammen" — die eigentliche
     * Beweisführung des Foodpairing-Modells, die im Zutat→Anker-Netz fehlt.
     *
     * Signal = die gemessene Foodpairing-Harmonie ({@see AnkerGraph}): je ungeordnetem Paar
     * eine Linie mit Stufe ★★/★★★. Existiert keine Kante (Stufe 1), entsteht keine Linie.
     *
     * @param  array<int>  $innerIds
     * @return list<array{source:string,target:string,kind:string,typ:string,level:int,weight:float,visible:bool}>
     */
    private function innerAnkerKanten(array $innerIds): array
    {
        if (count($innerIds) < 2) {
            return [];
        }

        $out = [];
        foreach ($this->graph()->kanten($innerIds, $innerIds) as $k) {
            if ($k->von > $k->zu) {
                continue;                                   // jedes Paar steht in beiden Richtungen — einmal zeigen
            }
            $out[] = [
                'source' => 'a:'.$k->von,
                'target' => 'a:'.$k->zu,
                'kind' => 'anker_anker',
                'typ' => 'stern'.$k->stufe,
                'level' => $k->stufe,
                'weight' => AnkerGraph::GEWICHT[$k->stufe],
                'visible' => true,
            ];
        }

        return $out;
    }

    /**
     * Pairing-Kandidaten für die Kern-Anker: alle Aroma-Partner AUSSERHALB des
     * Ankersets, aggregiert je Kandidat (dish_cover = Anzahl bedienter Kern-Anker,
     * primärer Typ = stärkste Kante).
     *
     * @return array{0: list<array>, 1: array<int,array>}  [kandidaten, candMeta je candId]
     */
    private function kandidatenFuerAnker(array $innerIds): array
    {
        if ($innerIds === []) {
            return [[], []];
        }

        $kanten = $this->graph()->kanten($innerIds, null, AnkerGraph::PASST, $innerIds);
        $meta = DB::table('foodalchemist_vocab_pairing_anchors')
            ->whereIn('id', $kanten->pluck('zu')->unique()->all())
            ->get(['id', 'slug', 'display_de'])->keyBy('id');

        $agg = [];
        foreach ($kanten as $k) {
            // Bucket = Stern-Stufe: stern3 = Inspire best match, stern2 = good match.
            $cid = $k->zu;
            $m = $meta[$cid] ?? null;
            if ($m === null) {
                continue;
            }
            $bucket = 'stern'.$k->stufe;
            $w = AnkerGraph::GEWICHT[$k->stufe];
            if (! isset($agg[$cid])) {
                $agg[$cid] = ['id' => $cid, 'slug' => $m->slug, 'display_de' => $m->display_de,
                    'partner' => [], 'ankerSet' => [], 'best' => ['typ' => $bucket, 'weight' => -1.0, 'level' => $k->stufe]];
            }
            $agg[$cid]['partner'][] = ['anker_id' => $k->von, 'typ' => $bucket, 'level' => $k->stufe, 'weight' => $w];
            $agg[$cid]['ankerSet'][$k->von] = true;
            if ($w > $agg[$cid]['best']['weight']) {
                $agg[$cid]['best'] = ['typ' => $bucket, 'weight' => $w, 'level' => $k->stufe];
            }
        }

        $kandidaten = [];
        $candMeta = [];
        foreach ($agg as $cid => $c) {
            $cover = count($c['ankerSet']);
            $primaerAnker = $c['partner'][0]['anker_id'];
            foreach ($c['partner'] as $p) {
                if ($p['typ'] === $c['best']['typ']) {
                    $primaerAnker = $p['anker_id'];
                    break;
                }
            }
            $kandidaten[] = [
                'id' => $cid, 'slug' => $c['slug'], 'display_de' => $c['display_de'],
                'typ' => $c['best']['typ'], 'level' => $c['best']['level'], 'weight' => $c['best']['weight'],
                'cover' => $cover, 'partner' => $c['partner'],
            ];
            $candMeta[$cid] = ['typ' => $c['best']['typ'], 'anker_id' => $primaerAnker, 'slug' => $c['slug']];
        }

        return [$kandidaten, $candMeta];
    }

    /**
     * Komplementäre Basisrezepte: Basisrezepte (is_sales_recipe=0), deren Kern-
     * Anker ein Pairing-Partner der Gericht-Anker ist — also Rezepte, die auf
     * einer passenden Zutat AUFBAUEN (ergänzend, nicht bloss »ähnlich«). Typ +
     * Andock-Anker aus der stärksten Partner-Beziehung.
     *
     * @param  array<int,array>  $candMeta  candId → [typ, anker_id, slug]
     * @return list<array>
     */
    private function komplementaerBasisrezepte(Team $team, int $recipeId, array $candMeta): array
    {
        if ($candMeta === []) {
            return [];
        }
        $candIds = array_keys($candMeta);

        $rows = DB::table('foodalchemist_recipe_anchor_mappings AS m')
            ->join('foodalchemist_recipes AS r', 'r.id', '=', 'm.recipe_id')
            ->whereIn('m.anchor_id', $candIds)
            ->where('m.recipe_id', '!=', $recipeId)
            ->where('r.is_sales_recipe', 0)
            ->whereNull('m.deleted_at')
            ->get(['m.recipe_id', 'm.anchor_id', 'r.name']);

        // Nur team-sichtbare Rezepte (Tenancy).
        $sichtbar = FoodAlchemistRecipe::visibleToTeam($team)
            ->whereIn('id', $rows->pluck('recipe_id')->unique()->all())->pluck('id')->flip();

        $perRecipe = [];
        foreach ($rows as $r) {
            $rid = (int) $r->recipe_id;
            if (! $sichtbar->has($rid)) {
                continue;
            }
            $meta = $candMeta[(int) $r->anchor_id] ?? null;
            if ($meta === null) {
                continue;
            }
            if (! isset($perRecipe[$rid])) {
                $perRecipe[$rid] = ['recipe_id' => $rid, 'name' => $r->name, 'treffer' => 0,
                    'typ' => $meta['typ'], 'anker_id' => $meta['anker_id'], 'via_slug' => $meta['slug']];
            }
            $perRecipe[$rid]['treffer']++;
        }

        $out = array_values($perRecipe);
        usort($out, fn ($x, $y) => [$y['treffer'], $x['name']] <=> [$x['treffer'], $y['name']]);

        return array_slice($out, 0, self::BASIS_MAX);
    }

    /** Gleichmässige Position auf einem Vollkreis: Slot idx von n, Start oben (270°/-90°). */
    private function positionAufKreis(int $idx, int $n, float $radius, float $cx, float $cy): array
    {
        $rad = 2 * M_PI * $idx / max(1, $n) - M_PI / 2;

        return [round($cx + $radius * cos($rad), 1), round($cy + $radius * sin($rad), 1)];
    }

    // ── R6.8: Aroma-treue Substitution (read-only, 2026-07-19) ───────────
    // Ersatz, der den GESCHMACK erhält — nicht nur den Preis senkt. Zwei vorhandene
    // Basen kombiniert (kein Neubau der Mathematik): (1) Anker-Kanten-Überlappung —
    // welche der Aroma-Brücken des Quell-GP trägt/erreicht der Kandidat (edgeBest über
    // die gpAnkers beider Seiten). Der frühere Aroma-Vektor-Cosinus (Moleküle) ist mit
    // Spec 60 · P3 entfallen. Manuell kuratierte Äquivalente (ComponentEquivalentService) werden geboostet (Inv. 3: manual
    // gewinnt). Der eigentliche Tausch bleibt tauscheZutat (Allergen-/swap_locked-Guards dort).

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

        // Kanten über die Anker-Union (ein Query).
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

            // Spec 60 · P3: Aroma-Vektoren (Moleküle) sind raus — der Geschmack wird allein über
            // die gemessenen Anker-Kanten erhalten.
            $flavorScore = $edgeOverlap;

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
                'erhaltene_bruecken' => $c['erhaltene_bruecken'],
                'verlorene_bruecken' => $c['verlorene_bruecken'],
                'kohaesions_delta' => $kohaesionsDelta,
                'is_manual_equiv' => $c['is_manual_equiv'],
                'allergen_warnungen' => $allergenWarn,
                'cost' => $cost,
                'evidenz' => [
                    'tier' => $c['is_manual_equiv'] ? 'kuratiert' : 'abgeleitet',
                    'basis' => $c['is_manual_equiv'] ? 'manuelles Äquivalent' : 'Anker-Kanten',
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

    /**
     * Kante je Anker-Paar: [a][b] => [gewicht, typ]. Übergang bis P6: Gewicht aus der Stufe
     * (3 → 1,0 · 2 → 0,9), Typ immer `aroma`.
     */
    private function edgeBest(array $ankerIds): array
    {
        if ($ankerIds === []) {
            return [];
        }
        $out = [];
        foreach ($this->graph()->kanten($ankerIds, $ankerIds) as $k) {
            $out[$k->von][$k->zu] = [AnkerGraph::GEWICHT[$k->stufe], 'aroma'];
        }

        return $out;
    }

    /**
     * Spec 53/H Aufgabe B1 → verschärft (Orchestrierung, 2026-09-18, Live-PREVIEW nach Deploy 17):
     * für das Zutaten-Grounding EXAKTE Gleichheit von `display_de` oder `slug` (beide normalisiert),
     * NIE Nearest-Neighbor. `neighborsForName()`/`resolveByName()` sind für die INTERAKTIVE
     * Pairing-Suche gebaut (Composer, Seed-Anker) und akzeptieren bewusst Wort-Fragmente ≥4 Zeichen
     * aus `display_de` als Treffer (`anchorIndex()` prio 2) plus einen semantischen Fallback — genau
     * das ist hier die Gefahr: `leitTokens()` liefert aus "Passionsfrucht-Gelee" auch das Token
     * "gelee", und `resolveByName('gelee')` traf einen Anker, dessen `display_de` NUR zufällig das
     * Wort "Gelee" enthält (z. B. "Apfel Gelee") — nicht die gemeinte Zutat. Grounding lädt
     * deterministisch GENAU EIN Dossier und darf sich diese Verwechslung nicht erlauben: lieber
     * `ohne_anker` (ehrlich sichtbar in der Herkunft) als ein falsches Dossier.
     *
     * ★ Konservativer Singular-Fallback (Orchestrierung, 2026-09-18, Live-PREVIEW nach Deploy 21):
     * `leitTokens()` liefert aus einem Brief oft den Plural ("aprikosen", "tomaten", "kartoffeln"),
     * das Anker-Label steht im Singular ("Aprikose", "Tomate", "Kartoffel") — exakte Gleichheit trifft
     * dann nie. Deutsche Pluralbildung ist nicht eindeutig rückführbar (Aprikose→Aprikosen braucht
     * `-n` weg, nicht `-en`) — deshalb EINE Stufe abschneiden, aber ALLE plausiblen Endungen
     * gleichzeitig prüfen (`-n`, `-en`, `-e`, `-s`, `-er`) und nur akzeptieren, wenn GENAU EIN Anker
     * über den gesamten Versuch matcht. Mehrdeutigkeit (zwei Endungen treffen zwei verschiedene
     * Anker) bleibt bewusst `ohne_anker` statt zu raten — dieselbe Regel wie beim exakten Treffer.
     *
     * @return ?array{slug: string, via: 'exakt'|'singular'}
     */
    public function ankerSlugExakt(string $token): ?array
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }
        if (($anker = $this->ankerExaktGleich($token)) !== null) {
            return ['slug' => $anker->slug, 'via' => 'exakt'];
        }

        $treffer = [];
        foreach (['n', 'en', 'e', 's', 'er'] as $endung) {
            if (! str_ends_with($token, $endung)) {
                continue;
            }
            $kandidat = mb_substr($token, 0, mb_strlen($token) - mb_strlen($endung));
            if (mb_strlen($kandidat) < 3) {
                continue;   // zu kurz, um noch verlässlich zu sein
            }
            if (($singularAnker = $this->ankerExaktGleich($kandidat)) !== null) {
                $treffer[$singularAnker->slug] = $singularAnker;
            }
        }
        if (count($treffer) === 1) {
            return ['slug' => reset($treffer)->slug, 'via' => 'singular'];
        }

        return null;
    }

    /** Exakte Gleichheit von `slug` (normalisiert) ODER `display_de` (gefaltet) — Kern von {@see ankerSlugExakt()}. */
    private function ankerExaktGleich(string $token): ?object
    {
        $slugNorm = $this->normalizeAnkerSlug($token);
        $displayNorm = trim($this->fold($token));

        return $this->ankerExaktListe()->first(fn ($a) => $this->normalizeAnkerSlug($a->slug) === $slugNorm
            || trim($this->fold($a->display_de)) === $displayNorm);
    }

    /** @return \Illuminate\Support\Collection<int, object{slug: string, display_de: string}> */
    private function ankerExaktListe(): \Illuminate\Support\Collection
    {
        return $this->ankerExaktListe ??= DB::table('foodalchemist_vocab_pairing_anchors')->whereNull('deleted_at')
            ->where('slug', '!=', 'neutral')->get(['id', 'slug', 'display_de']);
    }

    /**
     * Spec 58 · Paket 1: Anker-ID nur bei EXAKTER Gleichheit (inkl. eindeutigem Singular) — für
     * Zutaten ohne Mapping. Grundprodukt-Namen tragen den Zustand nach dem Doppelpunkt
     * („Möhre: frisch, Stifte") → nur der Grundname davor zählt. Kein Wortteil-Treffer: lieber
     * eine sichtbare Lücke als „Sauce: Chimichurri" → A1-Sauce.
     */
    public function ankerIdExakt(?string $name): ?int
    {
        $basis = trim((string) strtok((string) $name, ':'));
        if ($basis === '') {
            return null;
        }
        $treffer = $this->ankerSlugExakt($basis);
        // „Petersilie, glatt" → „Petersilie": Teil vor dem Komma, ebenfalls nur exakt.
        if ($treffer === null && str_contains($basis, ',')) {
            $treffer = $this->ankerSlugExakt(trim((string) strtok($basis, ',')));
        }
        if ($treffer === null) {
            return null;
        }
        $anker = $this->ankerExaktListe()->first(fn ($a) => $a->slug === $treffer['slug']);

        return $anker !== null ? (int) $anker->id : null;
    }

    /**
     * MCP-Discovery (Phase K): Pairing-Partner für einen Zutat-NAMEN oder
     * Anker-Slug. Auflösung ist HYBRID (analog gps.SEARCH): exakter/
     * normalisierter Slug → lexikalischer Anker-Index (resolveByName) →
     * semantischer Fallback (opt-in, Konfidenz-Floor), der deutsche Begriffe
     * gegen den englischen Inspire-Graph auflöst. Der Match-Weg steht in
     * anker.resolution.via (slug|lexical|semantic; semantic zusätzlich mit
     * score), damit der Client sieht, worauf UND WIE gematcht wurde.
     *
     * @return array{anker: ?array{id: int, slug: string, display_de: ?string, resolution: array{via: string, score?: float}}, partner: list<object>}
     */
    public function neighborsForName(string $name, ?string $typ = null, int $limit = 30): array
    {
        $via = 'slug';
        $score = null;

        $anker = DB::table('foodalchemist_vocab_pairing_anchors')
            ->whereIn('slug', array_unique([trim($name), $this->normalizeAnkerSlug($name)]))
            ->first(['id', 'slug', 'display_de']);

        // Lexikalischer Anker-Index — aber nur akzeptieren, wenn der Treffer ein
        // Wortgrenzen-Match ist (lexicalAnkerIsSolid). Ein Substring-Zufall
        // („auberGINe" → Gin) wird verworfen, damit der semantische Fallback
        // greifen kann statt still den falschen Anker zurückzugeben.
        if ($anker === null && ($ankerId = $this->resolveByName($name)) !== null) {
            $kandidat = DB::table('foodalchemist_vocab_pairing_anchors')
                ->where('id', $ankerId)->first(['id', 'slug', 'display_de']);
            if ($kandidat !== null && $this->lexicalAnkerIsSolid($name, $kandidat)) {
                $via = 'lexical';
                $anker = $kandidat;
            }
        }

        // B (Hybrid): semantischer Fallback, wenn die Lexik nichts findet. Fängt
        // deutsche Gastro-Begriffe gegen den englischen Inspire-Graph ab
        // („Aubergine" → „eggplant"). Opt-in (semantic_search.enabled) + Konfidenz-
        // Floor (anker_min_score); ohne Provider / aus ⇒ reine Lexik wie zuvor.
        if ($anker === null && ($hit = $this->resolveAnkerSemanticallyScored($name)) !== null) {
            $treffer = DB::table('foodalchemist_vocab_pairing_anchors')
                ->where('id', $hit['id'])->first(['id', 'slug', 'display_de']);
            if ($treffer !== null) {
                $anker = $treffer;
                $via = 'semantic';
                $score = round($hit['score'], 3);
            }
        }

        if ($anker === null) {
            return ['anker' => null, 'partner' => []];
        }

        $resolution = ['via' => $via];
        if ($score !== null) {
            $resolution['score'] = $score;
        }

        return [
            'anker' => [
                'id' => (int) $anker->id,
                'slug' => $anker->slug,
                'display_de' => $anker->display_de,
                'resolution' => $resolution,
            ],
            'partner' => $this->ankerNeighbors($anker->slug, $typ, $limit)->all(),
        ];
    }
}
