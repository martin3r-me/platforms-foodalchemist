<?php

namespace Platform\FoodAlchemist\Services\Matching;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;

/**
 * Pool-Abfragen für {@see \Platform\FoodAlchemist\Services\IngredientMatchService}: GP- und Basisrezept-Pool mit LIKE-Vorfilter
 * (ORDER BY id, Inv. 7) und engen Sonden gegen das Abschneiden exakter Treffer mit hoher ID. Ausgelagert wegen der Dateigröße
 * (Spec 85 §1) — unverändert verschoben, nutzt `$this->engine` des Services.
 */
trait PoolAbfragen
{
    /**
     * Breiter Pool ∪ enge Sonden. Siehe Begründung an der Aufrufstelle: `gpPool()` kappt
     * bei 300 nach `id`, wodurch breite Token-Mengen exakte Treffer mit hoher ID abschneiden.
     *
     * Die Sonden laufen NUR, wenn die Erweiterung den Token-Satz tatsächlich verbreitert hat
     * bzw. ein längstes Token existiert — sonst wäre es dieselbe Abfrage zweimal.
     */
    private function poolMitEngenSonden(Team $team, array $poolTokens, array $queryTokens, ?string $querySlug)
    {
        $treffer = $this->gpPool($team, $poolTokens, $querySlug);
        foreach ($this->engeSonden($poolTokens, $queryTokens) as $sonde) {
            $treffer = $treffer->concat($this->gpPool($team, $sonde, $querySlug));
        }

        return $treffer->unique('id')->values();
    }

    /** Dasselbe für die Basisrezepte (subPool kappt bei 200, gleiches Muster). */
    private function subPoolMitEngenSonden(Team $team, array $poolTokens, array $queryTokens, ?string $querySlug)
    {
        $treffer = $this->subPool($team, $poolTokens, $querySlug);
        foreach ($this->engeSonden($poolTokens, $queryTokens) as $sonde) {
            $treffer = $treffer->concat($this->subPool($team, $sonde, $querySlug));
        }

        return $treffer->unique('id')->values();
    }

    /**
     * Die engen Sonden: Original-Tokens (falls die Erweiterung verbreitert hat) und das
     * längste Einzel-Token (bei deutschen Komposita der unterscheidende Teil —
     * „Sherryessig" gegenüber „sherry"+„essig").
     *
     * @return list<list<string>>
     */
    private function engeSonden(array $poolTokens, array $queryTokens): array
    {
        $sonden = [];
        if ($queryTokens !== [] && count($poolTokens) > count($queryTokens)) {
            $sonden[] = $queryTokens;
        }
        $laengstes = null;
        foreach ($queryTokens as $t) {
            if ($laengstes === null || mb_strlen($t) > mb_strlen($laengstes)) {
                $laengstes = $t;
            }
        }
        if ($laengstes !== null && $queryTokens !== [$laengstes]) {
            $sonden[] = [$laengstes];
        }

        return $sonden;
    }

    /** LIKE-Vorfilter (build_like_clauses) — GP-Pool, ORDER BY id (Inv. 7), LIMIT 300. */
    private function gpPool(Team $team, array $queryTokens, ?string $querySlug)
    {
        $query = FoodAlchemistGp::visibleToTeam($team)->lebensmittel()   // Non-Food ist keine Zutat
            ->whereIn('status', ['approved', 'tentative'])
            ->where('is_platzhalter', false);
        $this->likeVorfilter($query, $queryTokens, $querySlug, ['name', 'main_ingredient_slug', 'main_ingredient_display']);

        return $query->orderBy('id')->limit(300)
            ->get(['id', 'name', 'main_ingredient_slug', 'main_ingredient_display', 'condition', 'bio', 'team_id', 'is_derivat', 'status',
                'zusatz', 'lead_la_supplier_item_id', 'commodity_group_code', 'sub_category']);   // Marken-Varianten: Score ohne Klammer, Tiebreak Stamm-Lieferant; sub_category: Zustands-Gate
    }

    /**
     * Sub-Pool: Basisrezepte, ORDER BY id, LIMIT 200. Für Vorschläge alle Workflow-Stadien inkl. stub; für die
     * automatische Entscheidung (`nurFreigegeben`) nur freigegebene (Bestand-Regel, Spec 80 B4).
     */
    private function subPool(Team $team, array $queryTokens, ?string $querySlug, bool $nurFreigegeben = false)
    {
        $query = FoodAlchemistRecipe::visibleToTeam($team)->basis()
            ->whereIn('status', $nurFreigegeben ? ['approved'] : ['stub', 'draft', 'review', 'approved']);
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
        // Lauf 88 (demo, 10.10.): „Lauch: frisch, ganz" LIKEte auch „frisch"/„ganz" — das trifft tausende GPs, nach
        // orderBy(id)->limit(300) kamen die Lauch-GPs (id ≥ 11857) nie in den Pool, die Zeile blieb offen (ebenso
        // „Pilzmischung: frisch, ganz"). Reine Zustands-/Schnittwörter tragen seit #233/#240 keinen Treffer — also
        // filtern sie auch nicht vor, solange ein Produktwort übrig bleibt. Gescort wird weiter mit allen Tokens.
        $produkt = array_values(array_filter($tokens, fn ($t) => ! $this->engine->istReinesMerkmal($t)));
        if ($produkt !== []) {
            $tokens = $produkt;
        }
        // M6-07 (Hebel-1-Rest »umlaut-blindes Reuse-Inventar«): Tokens sind
        // umlaut-expandiert (kuerbis), DB-Namen tragen Umlaute (Kürbis) — je
        // Token zusätzlich die Umlaut-Rückform LIKEn. Rein additiv: erweitert
        // NUR das Kandidaten-Set, Scores bleiben token-normalisiert.
        $varianten = [];
        // Neuabgleich 09.11.: GP-Namen stehen im Singular (§6.1) — „Schalotten“ fand „Schalotte“, „Äpfel“ fand „Apfel“, „Oliven“ fand
        // „Olive“ nicht im Vorfilter. Stamm und Lemma (≥ 4 Zeichen) zusätzlich LIKEn; Farbwörter filtern nicht vor, solange ein Produktwort bleibt.
        $ohneFarbe = array_values(array_filter($tokens, fn ($t) => \Platform\FoodAlchemist\Support\ZutatFreitext::farbe($t) === null));
        if ($ohneFarbe !== []) {
            $tokens = $ohneFarbe;
        }
        foreach ($tokens as $t) {
            foreach ([$this->engine->stemGerman($t), \Platform\FoodAlchemist\Support\ZutatFreitext::lemma($t)] as $stamm) {
                if ($stamm !== $t && mb_strlen($stamm) >= 4) {
                    $tokens[] = $stamm;
                }
            }
        }
        foreach (array_unique($tokens) as $t) {
            $varianten[$t] = true;
            // 11.11.: Umlaut und ß getrennt zurück — „maisstaerke“ wurde sonst nur zu „maißtärke“ (MySQL verzeiht ß = ss, SQLite nicht)
            foreach ([str_replace(['ae', 'oe', 'ue'], ['ä', 'ö', 'ü'], $t), str_replace(['ae', 'oe', 'ue', 'ss'], ['ä', 'ö', 'ü', 'ß'], $t)] as $u) {
                if ($u !== $t) {
                    $varianten[$u] = true;
                }
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
}
