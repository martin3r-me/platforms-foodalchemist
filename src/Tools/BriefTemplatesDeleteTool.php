<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\BriefTemplateService;
use RuntimeException;

/**
 * Löscht eine team-eigene Schnellstart-Vorlage (Brief-Template) endgültig (Soft-Delete). Kuratierte
 * Globals sind read-only → Owns-Guard wirft. Zum bloßen Ausblenden ohne Löschen: PUT active=false.
 */
class BriefTemplatesDeleteTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.brief_templates.DELETE';
    }

    public function getDescription(): string
    {
        return 'Löscht eine team-eigene Schnellstart-Vorlage (Brief-Template). Kuratierte Globals sind read-only. '
            . 'Zum vorübergehenden Ausblenden ohne Löschen stattdessen foodalchemist.brief_templates.PUT mit active=false.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'ID der eigenen Vorlage (aus brief_templates.LIST).'],
            ],
            'required' => ['id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        try {
            app(BriefTemplateService::class)->loeschen($team, (int) ($arguments['id'] ?? 0));
        } catch (RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'ACCESS_DENIED');
        }

        return ToolResult::success(['deleted' => true, 'id' => (int) ($arguments['id'] ?? 0)]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'command',
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'destructive',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'tags' => ['foodalchemist', 'planung', 'vorlage', 'template', 'schnellstart', 'brief', 'delete'],
            'related_tools' => ['foodalchemist.brief_templates.LIST', 'foodalchemist.brief_templates.PUT'],
            'examples' => ['Lösche Vorlage 12'],
        ];
    }
}
