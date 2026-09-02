<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Collection;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\Ai\PoolEmbeddingService;
use Platform\FoodAlchemist\Services\Ai\SemanticRetrievalService;
use Platform\FoodAlchemist\Services\Matching\TokenEngine;
use Throwable;

/**
 * DB-grounded Ersatzvorschlaege fuer ein GP. Die KI darf ausschliesslich den
 * vorselektierten Pool aus bestehenden GPs, Basisrezepten und ungemappten LAs
 * ranken; unbekannte IDs werden beim Rueckweg verworfen.
 */
class ReplacementSuggestionService
{
    private const PER_KIND = 12;

    private const RESULT_LIMIT = 8;

    public function __construct(
        private TokenEngine $tokens,
        private AiGatewayService $ai,
        private LaCandidateFinder $laFinder,
        private SemanticRetrievalService $semantic,
    ) {}

    /** @return list<array{kind:string,id:int,name:string,score:float,reason:string,supplier:?string,context:string}> */
    public function forGp(Team $team, FoodAlchemistGp $source): array
    {
        $pool = $this->pool($team, $source);
        if ($pool->isEmpty()) {
            return [];
        }

        $byKey = $pool->keyBy(fn (array $c) => $c['kind'].':'.$c['id']);
        try {
            $proposal = $this->ai->propose('component.replacement_suggest', [
                'quelle' => $this->sourceContext($source),
                'kandidaten' => $pool->map(fn (array $c) => [
                    'kind' => $c['kind'],
                    'id' => $c['id'],
                    'name' => $c['name'],
                    'supplier' => $c['supplier'],
                    'datenbank_score' => $c['score'],
                    'merkmale' => $c['context'],
                ])->values()->all(),
            ], ['target_table' => 'foodalchemist_gps', 'target_id' => $source->id]);
            $ranked = collect($proposal->werte['vorschlaege'] ?? [])
                ->map(function ($row) use ($byKey) {
                    if (! is_array($row)) {
                        return null;
                    }
                    $key = (string) ($row['kind'] ?? '').':'.(int) ($row['id'] ?? 0);
                    $candidate = $byKey->get($key);
                    if ($candidate === null) {
                        return null; // Halluzinations-Guard: nur IDs aus dem DB-Pool
                    }
                    $candidate['score'] = round(min(1, max(0, (float) ($row['score'] ?? $candidate['score']))), 3);
                    $candidate['reason'] = trim((string) ($row['reason'] ?? '')) ?: $candidate['reason'];

                    return $candidate;
                })
                ->filter()->unique(fn (array $c) => $c['kind'].':'.$c['id'])
                ->sortByDesc('score')->take(self::RESULT_LIMIT)->values();
            if ($ranked->isNotEmpty()) {
                return $ranked->all();
            }
        } catch (Throwable) {
            // Provider aus/Timeout: der vorgerankte DB-Pool bleibt weiterhin nutzbar.
        }

        return $pool->sortByDesc('score')->take(self::RESULT_LIMIT)->values()->all();
    }

    private function pool(Team $team, FoodAlchemistGp $source): Collection
    {
        $queryTokens = array_values(array_filter(
            $this->tokens->tokenize($source->name),
            fn (string $t) => mb_strlen($t) >= 3 && ! $this->tokens->isQualifierToken($t),
        ));
        $existing = app(ComponentEquivalentService::class)->fuer($team, 'gp', $source->id)
            ->mapWithKeys(fn ($e) => [($e->gegen_kind ?? '').':'.(int) ($e->gegen_id ?? 0) => true]);

        $pairing = collect();
        try {
            $pairing = collect(app(PairingService::class)->aromaTrueSubstitutes($team, $source->id, self::PER_KIND)['candidates'] ?? [])
                ->keyBy(fn (array $c) => (int) $c['gp_id']);
        } catch (Throwable) {
        }

        $semantic = collect($this->semantic->candidates($team, $source->name, [
            PoolEmbeddingService::ENTITY_TYPE_GP,
            PoolEmbeddingService::ENTITY_TYPE_RECIPE,
        ], self::PER_KIND * 3));
        $semanticGp = $semantic->where('entity_type', PoolEmbeddingService::ENTITY_TYPE_GP)
            ->mapWithKeys(fn (array $h) => [(int) $h['entity_id'] => (float) $h['score']]);
        $semanticRecipe = $semantic->where('entity_type', PoolEmbeddingService::ENTITY_TYPE_RECIPE)
            ->mapWithKeys(fn (array $h) => [(int) $h['entity_id'] => (float) $h['score']]);

        $gps = FoodAlchemistGp::visibleToTeam($team)
            ->whereKeyNot($source->id)
            ->whereNotIn('status', ['merged', 'rejected'])
            ->where('is_platzhalter', false)
            ->when($source->commodity_group_code || $semanticGp->isNotEmpty(), function ($q) use ($source, $semanticGp) {
                $q->where(function ($w) use ($source, $semanticGp) {
                    if ($source->commodity_group_code) {
                        $w->where('commodity_group_code', $source->commodity_group_code);
                    }
                    if ($semanticGp->isNotEmpty()) {
                        $w->orWhereIn('id', $semanticGp->keys());
                    }
                });
            })
            ->orderBy('name')->limit(80)->get([
                'id', 'name', 'condition', 'commodity_group_code', 'sub_category',
                'main_ingredient_slug', 'processing', 'form', 'tag_is_convenience',
            ])
            ->map(function ($gp) use ($queryTokens, $pairing, $semanticGp, $source) {
                $lex = $this->lexicalScore($queryTokens, (string) $gp->name);
                $aroma = (float) ($pairing->get((int) $gp->id)['flavor_score'] ?? 0);
                $sem = (float) ($semanticGp->get((int) $gp->id) ?? 0);
                $score = max($lex, $aroma, $sem, $gp->commodity_group_code === $source->commodity_group_code ? 0.2 : 0);

                return $this->candidate('gp', (int) $gp->id, (string) $gp->name, $score,
                    $aroma > max($lex, $sem) ? 'Aroma- und Ankerbezug aus der Datenbank' : ($sem > $lex
                        ? 'Semantischer Bezug aus dem Datenbankindex' : 'Namens- und Warengruppenbezug aus der Datenbank'),
                    context: $this->compactContext([
                        $gp->main_ingredient_slug, $gp->condition, $gp->processing, $gp->form,
                        $gp->commodity_group_code, $gp->sub_category,
                        $gp->tag_is_convenience ? 'Convenience' : null,
                    ]));
            })->sortByDesc('score')->take(self::PER_KIND);

        $recipeQuery = FoodAlchemistRecipe::visibleToTeam($team)->basis()
            ->whereNotIn('status', ['archived', 'rejected']);
        if ($queryTokens !== [] || $semanticRecipe->isNotEmpty()) {
            $recipeQuery->where(function ($q) use ($queryTokens, $semanticRecipe) {
                foreach (array_slice($queryTokens, 0, 4) as $token) {
                    $q->orWhereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($token).'%']);
                }
                if ($semanticRecipe->isNotEmpty()) {
                    $q->orWhereIn('id', $semanticRecipe->keys());
                }
            });
        }
        $recipes = $recipeQuery->orderBy('name')->limit(80)->get(['id', 'name'])
            ->map(fn ($r) => $this->candidate('recipe', (int) $r->id, (string) $r->name,
                max($this->lexicalScore($queryTokens, (string) $r->name), (float) ($semanticRecipe->get((int) $r->id) ?? 0)),
                $semanticRecipe->has((int) $r->id) ? 'Semantisch passendes Basisrezept aus dem Datenbankindex' : 'Passendes Basisrezept aus der Datenbank',
                context: 'Basisrezept / make-or-buy'))
            ->filter(fn (array $c) => $c['score'] > 0)
            ->sortByDesc('score')->take(self::PER_KIND);

        $las = $this->laFinder->find($team, $source->name, $source->commodity_group_code, self::PER_KIND * 2)
            ->filter(fn ($la) => $la->structure?->gp_id === null)
            ->map(fn ($la) => $this->candidate('supplier_item', (int) $la->id, (string) $la->designation,
                (float) ($la->score ?? 0), 'Noch ungemappter Lieferantenartikel aus dem Katalog',
                $la->supplier?->name ?? $la->supplier_name ?? null,
                $this->compactContext([
                    $la->structure?->main_ingredient_slug, $la->structure?->processing,
                    $la->structure?->form, $la->structure?->commodity_group_suggestion,
                ])))
            ->take(self::PER_KIND);

        return $gps->concat($recipes)->concat($las)
            ->reject(fn (array $c) => $existing->has($c['kind'].':'.$c['id']))
            ->unique(fn (array $c) => $c['kind'].':'.$c['id'])->values();
    }

    private function lexicalScore(array $queryTokens, string $name): float
    {
        $candidate = $this->tokens->tokenize($name);
        if ($queryTokens === [] || $candidate === []) {
            return 0;
        }
        $matches = 0;
        foreach ($queryTokens as $q) {
            foreach ($candidate as $c) {
                if ($this->tokens->tokenMatches($q, $c)) {
                    $matches++;
                    break;
                }
            }
        }

        return round($matches / max(count($queryTokens), count($candidate)), 3);
    }

    private function candidate(
        string $kind,
        int $id,
        string $name,
        float $score,
        string $reason,
        ?string $supplier = null,
        string $context = '',
    ): array {
        $score = round(min(1, max(0, $score)), 3);

        return compact('kind', 'id', 'name', 'score', 'reason', 'supplier', 'context');
    }

    private function compactContext(array $values): string
    {
        return collect($values)->filter(fn ($v) => $v !== null && $v !== '')
            ->unique()->take(7)->implode(' · ');
    }

    private function sourceContext(FoodAlchemistGp $source): array
    {
        $source->loadMissing(['leadLa.supplier', 'structures.item']);

        return [
            'kind' => 'gp',
            'id' => (int) $source->id,
            'name' => $source->name,
            'zustand' => $source->condition,
            'warengruppe' => $source->commodity_group_code,
            'subkategorie' => $source->sub_category,
            'hauptzutat' => $source->main_ingredient_slug,
            'verarbeitung' => $source->processing,
            'form' => $source->form,
            'convenience' => (bool) $source->tag_is_convenience,
            'lead_artikel' => $source->leadLa?->designation,
            'lieferantenartikel' => $source->structures->pluck('item.designation')->filter()->take(8)->values()->all(),
        ];
    }
}
