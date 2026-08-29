<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\SpeiseplanService;

/** MCP-Steuerbarkeit · D9: Ausgabe-Linie um eine Position verschieben (up/down). */
class SpeiseplanLinienMoveTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.speiseplan_linien.MOVE';
    }

    public function getDescription(): string
    {
        return 'Verschiebt eine Ausgabe-Linie um eine Position (direction up|down).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'linie_id' => ['type' => 'integer', 'description' => 'Linien-Id.'],
                'direction' => ['type' => 'string', 'enum' => ['up', 'down'], 'description' => 'Richtung.'],
            ],
            'required' => ['linie_id', 'direction'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $dir = (string) ($arguments['direction'] ?? '');
        if (! in_array($dir, ['up', 'down'], true)) {
            return ToolResult::error('direction muss up oder down sein.', 'VALIDATION_ERROR');
        }
        $linieId = (int) ($arguments['linie_id'] ?? 0);
        if (($guard = $this->guardSpeiseplanLinieOwned($team, $linieId)) !== null) {
            return $guard;
        }

        try {
            app(SpeiseplanService::class)->reorderLinie($team, $linieId, $dir === 'up' ? -1 : 1);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['linie_id' => $linieId, 'direction' => $dir]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'speiseplan', 'linie', 'reorder', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.speiseplan_linien.PUT'],
            'examples' => ['Schiebe Linie 5 nach oben.'],
        ];
    }
}
