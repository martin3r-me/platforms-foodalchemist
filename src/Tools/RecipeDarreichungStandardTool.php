<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\DarreichungService;

/** MCP-Steuerbarkeit · D3: eine Darreichungsform als Standard des Gerichts setzen (Preis-Spiegel). */
class RecipeDarreichungStandardTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.recipe_darreichung.STANDARD';
    }

    public function getDescription(): string
    {
        return 'Setzt eine Darreichungsform als Standard-Form des Gerichts (die den sales_net-Spiegel trägt).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['presentation_id' => ['type' => 'integer', 'description' => 'Darreichungs-Id.']],
            'required' => ['presentation_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $id = (int) ($arguments['presentation_id'] ?? 0);
        if (($guard = $this->guardDarreichungOwned($team, $id)) !== null) {
            return $guard;
        }

        try {
            app(DarreichungService::class)->setzeStandard($team, $id);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['presentation_id' => $id, 'is_standard' => true]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'darreichung', 'standard', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.recipe_darreichung.PUT'],
            'examples' => ['Mach Darreichung 88 zur Standard-Form.'],
        ];
    }
}
