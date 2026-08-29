<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\Ai\PoolEmbeddingService;
use Platform\FoodAlchemist\Services\SalesRecipeService;

/** M8-01: Verkaufsrezepte durchsuchen (D-6, verkauf()-Scope inkl. Marge-Kopf). */
class VerkaufsrezepteSearchTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.verkaufsrezepte.SEARCH';
    }

    public function getDescription(): string
    {
        return 'Durchsucht die Verkaufsrezepte des Teams (auch über Marketing-Namen und Kunden-Wordings). '
            . 'Hybrid: lexikalisch plus — sofern der Embedding-Provider aktiv ist — ein semantischer Pass über '
            . 'den Rezept-Pool (gefiltert auf VK-Gerichte; via: lexical|semantic). Liefert id, name, sales_net, '
            . 'ek_total_eur, speisen_klasse.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'q' => ['type' => 'string'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 10],
            ],
            'required' => ['q'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $q = trim((string) ($arguments['q'] ?? ''));
        $limit = min(50, max(1, (int) ($arguments['limit'] ?? 10)));
        $treffer = app(SalesRecipeService::class)->paginateBrowser(['search' => $q], $team, $limit);
        $row = fn ($r, string $via) => [
            'id' => $r->id, 'name' => $r->name, 'sales_net' => $r->sales_net,
            'ek_total_eur' => $r->ek_total_eur,
            'speisen_klasse' => $r->dishClass?->label,
            'dish_main_group_id' => $r->dish_main_group_id !== null ? (int) $r->dish_main_group_id : null,   // Taxonomie-Neutralisierung
            'presentations' => $this->darreichungenSummary($r),   // M1: Formen je Gericht
            'via' => $via,
        ];
        $gerichte = collect($treffer->items())->map(fn ($r) => $row($r, 'lexical'))->all();

        // Hybrid (Spec 15 §5a): semantischer Pass über den Rezept-Pool, gefiltert auf VK-Gerichte
        // (is_sales_recipe) — der Pool umfasst auch Basisrezepte, die hier nicht hergehören.
        $sem = $this->semanticPoolIds($team, $q, PoolEmbeddingService::ENTITY_TYPE_RECIPE, array_column($gerichte, 'id'), $limit);
        if ($sem !== []) {
            arsort($sem);
            $rows = FoodAlchemistRecipe::visibleToTeam($team)->where('is_sales_recipe', true)
                ->whereNull('variant_source_recipe_id')->whereIn('id', array_keys($sem))->get()->keyBy('id');
            foreach ($sem as $id => $score) {
                $r = $rows->get($id);
                if ($r === null || count($gerichte) >= $limit) {
                    continue;
                }
                $gerichte[] = $row($r, 'semantic') + ['semantic_score' => round($score, 3)];
            }
        }

        return ToolResult::success(['total' => count($gerichte), 'verkaufsrezepte' => $gerichte]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'read_only' => true,
            'idempotent' => true,
            'risk_level' => 'safe',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'tags' => ['foodalchemist', 'verkaufsrezept', 'rezept', 'verkauf', 'search'],
            'examples' => ['Suche Verkaufsrezepte mit Lachs'],
        ];
    }
}
