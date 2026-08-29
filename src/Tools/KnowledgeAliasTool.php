<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\KnowledgeService;

/** MCP-Steuerbarkeit · D12: Alias eines team-eigenen Wissensdokuments hinzufügen/entfernen (action-enum). */
class KnowledgeAliasTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.knowledge.ALIAS';
    }

    public function getDescription(): string
    {
        return 'Pflegt Aliasse eines team-eigenen Wissensdokuments. action=add: slug + alias (Text). '
            . 'action=remove: alias_id. Aliasse verbessern die deterministische Wissens-Auflösung.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string', 'enum' => ['add', 'remove'], 'description' => 'Hinzufügen oder entfernen.'],
                'slug' => ['type' => 'string', 'description' => 'Doc-Slug (bei action=add).'],
                'alias' => ['type' => 'string', 'description' => 'Alias-Text (bei action=add; wird zu Slug normalisiert).'],
                'alias_id' => ['type' => 'integer', 'description' => 'Alias-Id (bei action=remove).'],
            ],
            'required' => ['action'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $action = (string) ($arguments['action'] ?? '');
        $svc = app(KnowledgeService::class);

        try {
            if ($action === 'add') {
                $slug = trim((string) ($arguments['slug'] ?? ''));
                if ($slug === '') {
                    return ToolResult::error('slug ist Pflicht bei action=add.', 'VALIDATION_ERROR');
                }
                $aliasSlug = $svc->addAlias($team, $slug, (string) ($arguments['alias'] ?? ''));

                return ToolResult::success(['action' => 'add', 'slug' => $slug, 'alias_slug' => $aliasSlug]);
            }
            if ($action === 'remove') {
                $aliasId = (int) ($arguments['alias_id'] ?? 0);
                if ($aliasId <= 0) {
                    return ToolResult::error('alias_id ist Pflicht bei action=remove.', 'VALIDATION_ERROR');
                }
                $svc->removeAlias($team, $aliasId);

                return ToolResult::success(['action' => 'remove', 'alias_id' => $aliasId, 'removed' => true]);
            }
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::error('action muss add oder remove sein.', 'VALIDATION_ERROR');
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'knowledge', 'alias', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['creates', 'deletes'],
            'related_tools' => ['foodalchemist.knowledge.DELETE'],
            'examples' => ['Füge dem Doc "cross_cutting.mengen" den Alias "portionen" hinzu.'],
        ];
    }
}
