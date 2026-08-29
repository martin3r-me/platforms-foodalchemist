<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\SpeiseplanService;

/** MCP-Steuerbarkeit · D9: Ausgabe-Linie bearbeiten (name/color/is_vegetarian). */
class SpeiseplanLinienPutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.speiseplan_linien.PUT';
    }

    public function getDescription(): string
    {
        return 'Bearbeitet eine Ausgabe-Linie eines team-eigenen Speiseplans (felder: name, color, is_vegetarian).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'linie_id' => ['type' => 'integer', 'description' => 'Linien-Id.'],
                'felder' => ['type' => 'object', 'description' => 'name, color, is_vegetarian.'],
            ],
            'required' => ['linie_id', 'felder'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $felder = $arguments['felder'] ?? null;
        if (! is_array($felder) || $felder === []) {
            return ToolResult::error('felder muss ein nicht-leeres Objekt sein.', 'VALIDATION_ERROR');
        }
        $in = array_intersect_key($felder, array_flip(['name', 'color', 'is_vegetarian']));
        if ($in === []) {
            return ToolResult::error('Keine bekannten Felder in felder.', 'VALIDATION_ERROR');
        }
        $linieId = (int) ($arguments['linie_id'] ?? 0);
        if (($guard = $this->guardSpeiseplanLinieOwned($team, $linieId)) !== null) {
            return $guard;
        }

        try {
            app(SpeiseplanService::class)->updateLinie($team, $linieId, $in);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['linie_id' => $linieId, 'updated' => array_keys($in)]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'speiseplan', 'linie', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.speiseplan_linien.MOVE'],
            'examples' => ['Benenne Linie 5 in „Vegan" um.'],
        ];
    }
}
