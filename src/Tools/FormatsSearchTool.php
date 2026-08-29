<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistFormat;
use Platform\FoodAlchemist\Services\Ai\PoolEmbeddingService;
use Platform\FoodAlchemist\Services\FormatService;

/** Format-Modul: Formate nach Name/Consumer-Name/Claim suchen. */
class FormatsSearchTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.formats.SEARCH';
    }

    public function getDescription(): string
    {
        return 'Sucht Formate (Marken-/Themen-Container) über Name, Consumer-Name und Claim.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['query' => ['type' => 'string']],
            'required' => ['query'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        $q = trim((string) ($arguments['query'] ?? ''));
        $limit = 50;
        $page = app(FormatService::class)->paginateBrowser(['search' => $q], $team, $limit);
        $out = collect($page->items())->map(fn ($f) => [
            'id' => $f->id, 'name' => $f->name, 'consumer_name' => $f->consumer_name,
            'status' => $f->status instanceof \BackedEnum ? $f->status->value : $f->status,
            'origin' => $f->origin, 'editions_count' => (int) $f->editions_count, 'via' => 'lexical',
        ])->all();

        // Hybrid (Ausbau b): semantischer Pass über den Format-Pool — ergänzt nur NEUES.
        // editions_count bleibt bei semantischen Zeilen null (Treffer zählt; Detail via formats.GET).
        $sem = $this->semanticPoolIds($team, $q, PoolEmbeddingService::ENTITY_TYPE_FORMAT, array_column($out, 'id'), $limit);
        if ($sem !== []) {
            arsort($sem);
            $rows = FoodAlchemistFormat::visibleToTeam($team)->whereIn('id', array_keys($sem))->get()->keyBy('id');
            foreach ($sem as $id => $score) {
                $f = $rows->get($id);
                if ($f === null || count($out) >= $limit) {
                    continue;
                }
                $out[] = [
                    'id' => $f->id, 'name' => $f->name, 'consumer_name' => $f->consumer_name,
                    'status' => $f->status instanceof \BackedEnum ? $f->status->value : $f->status,
                    'origin' => $f->origin, 'editions_count' => null,
                    'via' => 'semantic', 'semantic_score' => round($score, 3),
                ];
            }
        }

        return ToolResult::success(['formats' => $out, 'total' => count($out)]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'format', 'foodkonzept', 'suche'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.formats.GET', 'foodalchemist.formats.LIST'],
            'examples' => ['Suche das Format CHEFS.CORNER'],
        ];
    }
}
