<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Collection;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\Matching\MatchHeuristics;
use Platform\FoodAlchemist\Services\Matching\TokenEngine;

/**
 * Spec 16·S2 — WG-Lead-gescopter LA-Kandidaten-Finder.
 *
 * Antwort auf den Use Case Dominique 2026-07-20: „wenn kein GP existiert, aber
 * das Rezept die Zutat braucht, den passenden Artikel unter den WG-Leads finden".
 *
 * Bewusst KEIN Vektor-Store/Qdrant (Spec 15 entkoppelt): der WG-Lead-Scope
 * (foodalchemist_preferred_suppliers, ~109 Einträge/15 WG) verengt den 264k-LA-
 * Katalog auf wenige tausend — klein genug für deterministisches Lexik-Matching.
 * Der Namensmatch spiegelt den #507-Weg-2-Stack (Terminologie S1/S2 + TokenEngine),
 * genau wie IngredientMatchService::candidatesFor, nur gegen Lieferantenartikel
 * statt gegen GPs. Rein deterministisch, kein LLM im Pfad (schnell, wiederholbar).
 *
 * Ranking (E4, Dominique bestätigt 2026-07-20):
 *   Namensscore (Best-über-Alias-Varianten) DESC
 *   → Lead-Priorität (Lieferant ist WG-/Stamm-Lead) DESC
 *   → hat aktiven Preis DESC → Bezeichnung ASC → id ASC.
 *
 * Fallback-Kaskade (E2): WG-Lead-Scope leer/ohne Treffer → ohne Scope (global)
 * weitersuchen, damit ein Treffer nicht am fehlenden Lead scheitert.
 */
class LaCandidateFinder
{
    /** Pool-Größe vor dem Re-Ranking (breit genug für verbose LA-Designations). */
    private const POOL = 40;

    public function __construct(
        private TokenEngine $engine,
        private MatchHeuristics $heuristik,
        private TerminologyService $terminology,
        private StammLieferantService $stamm,
        private SupplierItemService $items,
    ) {
    }

    /**
     * @param  string       $ingredientName  Zutaten-Bezeichnung (roh, ohne Mengen-Präfix)
     * @param  string|null  $wgCode          Warengruppen-Hint → verengt auf die WG-Leads (E1)
     * @param  int          $k               Top-k
     * @return Collection<int, FoodAlchemistSupplierItem>  gerankt, mit Attributen score/ist_lead
     */
    public function find(Team $team, string $ingredientName, ?string $wgCode = null, int $k = 3): Collection
    {
        $ingredientName = trim($ingredientName);
        if ($ingredientName === '') {
            return collect();
        }

        $queryTokens = $this->engine->tokenize($ingredientName);
        if ($queryTokens === []) {
            return collect();
        }

        // #507-Weg-2: Alias-/Decompound-Phrasen (Paradeiser→Tomate, Kürbispüree→„kürbis püree").
        // Sie erweitern SOWOHL den Such-Prefilter (fetchen den Alias-Kandidaten — searchGlobal
        // AND-tokenisiert, deshalb je Phrase EIN Query, nicht konkateniert) ALS AUCH die
        // Score-Sicht (jede Variante bekommt vollen Score statt im Token-Bag zu verwässern).
        $aliasPhrases = array_merge(
            $this->terminology->aliasPhrasesFor($ingredientName),
            $this->terminology->decompoundPhrasesFor($ingredientName),
        );
        $searchPhrases = array_values(array_unique(array_filter(
            array_merge([$ingredientName], $aliasPhrases),
            static fn ($p) => trim((string) $p) !== '',
        )));
        $aliasVariants = array_values(array_filter(
            array_map(fn ($p) => $this->engine->tokenize($p), $aliasPhrases),
            static fn ($v) => $v !== [],
        ));

        // S1-Reuse: WG-Lead-Lieferanten (WG-Code + global-NULL-Merge). Existiert bereits.
        $leadIds = $wgCode !== null && $wgCode !== ''
            ? $this->stamm->stammSupplierIdsFor($team, $wgCode)
            : [];

        // Erst im WG-Lead-Scope suchen; leer/ohne Treffer → global (E2-Fallback).
        $pool = $leadIds !== []
            ? $this->pool($team, $searchPhrases, $leadIds)
            : collect();
        if ($pool->isEmpty()) {
            $pool = $this->pool($team, $searchPhrases, []);
        }
        if ($pool->isEmpty()) {
            return collect();
        }

        $leadSet = array_flip($leadIds);

        $ranked = $pool
            // S2 Anti-Marker (Weg-2): bekannte Verwechslungs-Fallen raus (Brie↛Bries).
            ->reject(fn ($la) => $this->terminology->isAntiMarker($ingredientName, (string) $la->designation))
            ->map(function ($la) use ($queryTokens, $aliasVariants, $leadSet) {
                $slug = $la->structure?->main_ingredient_slug;
                $la->setAttribute('score', $this->bestScore($queryTokens, $aliasVariants, (string) $la->designation, $slug));
                $la->setAttribute('ist_lead', isset($leadSet[(int) $la->supplier_id]));

                return $la;
            })
            ->filter(fn ($la) => $la->score > 0.0)
            ->sortBy(fn ($la) => [
                -$la->score,                                   // Namensscore DESC
                $la->ist_lead ? 0 : 1,                         // Lead-Priorität (E4)
                $la->aktiver_preis !== null ? 0 : 1,           // Vollständigkeit/Preis
                mb_strtolower((string) $la->designation),      // stabil
                (int) $la->id,
            ])
            ->values();

        return $ranked->take($k);
    }

    /**
     * Bequemer Einzeltreffer für den Mint-Pfad (S3): bester Kandidat oder null.
     */
    public function best(Team $team, string $ingredientName, ?string $wgCode = null): ?FoodAlchemistSupplierItem
    {
        return $this->find($team, $ingredientName, $wgCode, 1)->first();
    }

    /**
     * Lexikalischer Kandidaten-Pool: searchGlobal je Such-Phrase (Query + Alias/Decompound),
     * Union über die item-id (searchGlobal AND-tokenisiert → je Phrase ein eigener Query).
     * Scope via whereIn-Filter aus der S1-baseQuery-Ergänzung.
     *
     * @param  list<string>  $phrases      ≥ 1 Phrase (Roh-Query zuerst)
     * @param  list<int>     $supplierIds  leer = kein Scope (global)
     * @return Collection<int, FoodAlchemistSupplierItem>
     */
    private function pool(Team $team, array $phrases, array $supplierIds): Collection
    {
        $filters = $supplierIds !== [] ? ['supplier_ids' => $supplierIds] : [];
        $byId = [];
        foreach ($phrases as $phrase) {
            foreach ($this->items->searchGlobal($team, $phrase, $filters, self::POOL)->items() as $la) {
                $byId[(int) $la->id] = $la;   // Union, Dubletten über die id entfernt
            }
        }

        return collect(array_values($byId));
    }

    /**
     * Best-über-Varianten-Score (Spiegel IngredientMatchService::bestLexScore, LA-Variante):
     * max(matchScore-mit-Floor der Query, substringOverlap, matchScore jeder Alias-Variante).
     * Alias-Varianten token-strikt (kein substringOverlap) — generische Alias-Token dürfen
     * nicht dutzende LAs per Substring auf 1.0 fluten.
     *
     * @param  list<string>              $queryTokens
     * @param  list<array<int,string>>   $aliasVariants
     */
    private function bestScore(array $queryTokens, array $aliasVariants, string $candText, ?string $candSlug): float
    {
        $best = max(
            $this->scoreMitFloor($queryTokens, null, $candText, $candSlug),
            $this->heuristik->substringOverlap($queryTokens, $candText),
        );
        foreach ($aliasVariants as $variant) {
            $s = $this->scoreMitFloor($variant, null, $candText, $candSlug);
            if ($s > $best) {
                $best = $s;
            }
        }

        return $best;
    }

    /** match_score + Name-Containment-Floor (4.4o), wie IngredientMatchService::scoreMitFloor. */
    private function scoreMitFloor(array $queryTokens, ?string $querySlug, string $candText, ?string $candSlug): float
    {
        $score = $this->engine->matchScore(
            $queryTokens,
            $querySlug,
            $this->engine->tokenize($candText),
            $candSlug !== null && $candSlug !== '' ? $candSlug : null,
        );
        if ($score < MatchHeuristics::NAME_CONTAINMENT_FLOOR && $this->engine->headMatchesQuery($candText, $queryTokens)) {
            $score = MatchHeuristics::NAME_CONTAINMENT_FLOOR;
        }

        return $score;
    }
}
