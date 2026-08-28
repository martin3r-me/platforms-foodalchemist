<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\GpService;

/**
 * MCP-Steuerbarkeit · D1: Platzhalter-GP anlegen (neutrales Abstraktum für Grundrezept-Templates,
 * ohne §3-WG/§8/LA). Team-eigen, idempotent (existierender Key wird wiederverwendet).
 */
class PlatzhalterPostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.platzhalter.POST';
    }

    public function getDescription(): string
    {
        return 'Legt ein Platzhalter-GP an (neutrales Abstraktum, z.B. „Mehl (neutral)"), team-eigen. '
            . 'Idempotent — ein vorhandener Platzhalter mit gleichem Namen wird wiederverwendet.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['name' => ['type' => 'string', 'description' => 'Basis-Name des Platzhalters.']],
            'required' => ['name'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        if (trim((string) ($arguments['name'] ?? '')) === '') {
            return ToolResult::error('name ist Pflicht.', 'VALIDATION_ERROR');
        }

        try {
            $gp = app(GpService::class)->createPlatzhalter($team, (string) $arguments['name']);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['id' => (int) $gp->id, 'name' => $gp->name, 'is_platzhalter' => true]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'gp', 'platzhalter', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['creates'],
            'related_tools' => ['foodalchemist.platzhalter.PUT', 'foodalchemist.platzhalter.DELETE'],
            'examples' => ['Lege einen Platzhalter „Mehl" an.'],
        ];
    }
}
