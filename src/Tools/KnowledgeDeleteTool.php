<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\KnowledgeService;

/**
 * MCP-Steuerbarkeit · D12: ein team-eigenes Wissensdokument löschen (inkl. Recall-Index-Bereinigung).
 * Globales Master-/Seed-Wissen bleibt read-only. Destruktiv → confirm=true Pflicht.
 */
class KnowledgeDeleteTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.knowledge.DELETE';
    }

    public function getDescription(): string
    {
        return 'Löscht ein team-eigenes Wissensdokument (per slug). Globales Master-/Seed-Wissen ist read-only. Erfordert confirm=true.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'slug' => ['type' => 'string', 'description' => 'Slug des Wissensdokuments.'],
                'confirm' => ['type' => 'boolean', 'description' => 'Muss true sein (destruktive Aktion).'],
            ],
            'required' => ['slug', 'confirm'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        if (($arguments['confirm'] ?? false) !== true) {
            return ToolResult::error('Löschen erfordert confirm=true (destruktive Aktion).', 'CONFIRM_REQUIRED');
        }
        $slug = trim((string) ($arguments['slug'] ?? ''));
        if ($slug === '') {
            return ToolResult::error('slug ist Pflicht.', 'VALIDATION_ERROR');
        }

        try {
            app(KnowledgeService::class)->delete($team, $slug);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['slug' => $slug, 'deleted' => true]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'knowledge', 'delete'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'destructive',
            'confirmation_required' => true,
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['deletes'],
            'related_tools' => ['foodalchemist.knowledge.ALIAS', 'foodalchemist.knowledge.UPDATE'],
            'examples' => ['Lösche das Wissensdokument "cross_cutting.altbestand" (confirm=true).'],
        ];
    }
}
