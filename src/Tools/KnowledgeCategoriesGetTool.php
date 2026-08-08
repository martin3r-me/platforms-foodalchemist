<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\KnowledgeService;

/**
 * MCP: sichtbare Wissens-Kategorien listen (globales Master-Vokabular + eigenes Team).
 * Gegenstück zu knowledge_categories.POST — zum Prüfen, welche category-Slugs es gibt,
 * bevor man ein Doc anlegt (knowledge.POST).
 */
class KnowledgeCategoriesGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.knowledge_categories.GET';
    }

    public function getDescription(): string
    {
        return 'Listet die sichtbaren Wissens-Kategorien (globales Master-Vokabular + eigenes Team), '
            . 'sortiert. Liefert slug/label/description/scope/active. Standard nur aktive; mit '
            . 'include_inactive=true auch deaktivierte (Selbst-Kontrolle). Neue Kategorie anlegen: '
            . 'knowledge_categories.POST.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'include_inactive' => ['type' => 'boolean', 'description' => 'true → auch deaktivierte Kategorien zeigen (Default: nur aktive)'],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        $kategorien = app(KnowledgeService::class)->listCategories(
            $team,
            ($arguments['include_inactive'] ?? false) === true,
        );

        return ToolResult::success([
            'categories' => $kategorien,
            'count' => count($kategorien),
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'knowledge', 'wissen', 'kategorie', 'vokabular'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.knowledge_categories.POST'],
            'examples' => ['Zeig die Wissens-Kategorien', 'Alle Kategorien inkl. deaktivierte'],
        ];
    }
}
