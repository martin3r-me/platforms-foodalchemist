<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\RecipeService;

/**
 * MCP-Steuerbarkeit · D3: Status eines team-eigenen Gerichts setzen (draft|review|approved|deprecated).
 */
class VerkaufsrezepteStatusTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    private const ERLAUBT = ['draft', 'review', 'approved', 'deprecated'];

    public function getName(): string
    {
        return 'foodalchemist.verkaufsrezepte.STATUS';
    }

    public function getDescription(): string
    {
        return 'Setzt den Status eines team-eigenen Gerichts: draft|review|approved|deprecated.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'Gericht-Id (team-eigen).'],
                'status' => ['type' => 'string', 'enum' => self::ERLAUBT, 'description' => 'Neuer Status.'],
            ],
            'required' => ['id', 'status'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $status = (string) ($arguments['status'] ?? '');
        if (! in_array($status, self::ERLAUBT, true)) {
            return ToolResult::error('status muss einer von: ' . implode(', ', self::ERLAUBT) . ' sein.', 'VALIDATION_ERROR');
        }

        $id = (int) ($arguments['id'] ?? 0);
        $r = FoodAlchemistRecipe::visibleToTeam($team)->where('is_sales_recipe', true)->whereKey($id)->first();
        if ($r === null) {
            return ToolResult::error('Gericht nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }
        if (! $r->isOwnedBy($team)) {
            return ToolResult::error('Geerbtes Gericht — Status nur durchs Besitzer-Team.', 'ACCESS_DENIED');
        }

        app(RecipeService::class)->setStatus($team, $id, $status);

        return ToolResult::success(['id' => $id, 'status' => $status]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'verkaufsrezept', 'gericht', 'status', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.verkaufsrezepte.GET'],
            'examples' => ['Setze Gericht 501 auf approved.'],
        ];
    }
}
