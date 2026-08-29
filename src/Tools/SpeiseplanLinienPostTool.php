<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplan;
use Platform\FoodAlchemist\Services\SpeiseplanService;

/** MCP-Steuerbarkeit · D9: Ausgabe-Linie an einem Speiseplan anlegen. */
class SpeiseplanLinienPostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.speiseplan_linien.POST';
    }

    public function getDescription(): string
    {
        return 'Legt eine Ausgabe-Linie an einem team-eigenen Speiseplan an (name; optional color, is_vegetarian).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'plan_id' => ['type' => 'integer', 'description' => 'Speiseplan-Id.'],
                'name' => ['type' => 'string', 'description' => 'Linien-Name.'],
                'color' => ['type' => 'string', 'description' => 'Optionale Farbe.'],
                'is_vegetarian' => ['type' => 'boolean', 'description' => 'Vegetarische Linie.'],
            ],
            'required' => ['plan_id', 'name'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $name = trim((string) ($arguments['name'] ?? ''));
        if ($name === '') {
            return ToolResult::error('name ist Pflicht.', 'VALIDATION_ERROR');
        }
        $planId = (int) ($arguments['plan_id'] ?? 0);
        if (($guard = $this->guardOwned($team, FoodAlchemistSpeiseplan::class, $planId, 'Speiseplan')) !== null) {
            return $guard;
        }

        try {
            $linie = app(SpeiseplanService::class)->addLinie($team, $planId, [
                'name' => $name,
                'color' => $arguments['color'] ?? null,
                'is_vegetarian' => (bool) ($arguments['is_vegetarian'] ?? false),
            ]);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['plan_id' => $planId, 'linie_id' => (int) $linie->id, 'name' => $linie->name]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'speiseplan', 'linie', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['creates'],
            'related_tools' => ['foodalchemist.speiseplan_linien.PUT', 'foodalchemist.speiseplan_linien.DELETE'],
            'examples' => ['Lege am Speiseplan 3 die Linie „Vegetarisch" an.'],
        ];
    }
}
