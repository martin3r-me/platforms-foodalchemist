<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistPaket;
use Platform\FoodAlchemist\Models\FoodAlchemistPaketGericht;
use Platform\FoodAlchemist\Services\PaketService;

/** MCP-Steuerbarkeit · D5d: Geschirr (haupt|alt) einer Paket-Position setzen/lösen. */
class PaketGerichteGeschirrTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.paket_gerichte.GESCHIRR';
    }

    public function getDescription(): string
    {
        return 'Setzt/löst den Geschirr-Artikel (role haupt|alt) an einer Paket-Position (row_id). item_id weglassen = lösen.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'row_id' => ['type' => 'integer', 'description' => 'Positions-Zeilen-Id (paket_gerichte).'],
                'role' => ['type' => 'string', 'enum' => ['haupt', 'alt'], 'description' => 'Haupt- oder Alternativ-Geschirr.'],
                'item_id' => ['type' => 'integer', 'description' => 'Geschirr-Artikel-Id (weglassen = lösen).'],
            ],
            'required' => ['row_id', 'role'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $role = (string) ($arguments['role'] ?? '');
        if (! in_array($role, ['haupt', 'alt'], true)) {
            return ToolResult::error('role muss haupt oder alt sein.', 'VALIDATION_ERROR');
        }
        $rowId = (int) ($arguments['row_id'] ?? 0);
        $row = FoodAlchemistPaketGericht::whereKey($rowId)->first();
        if ($row === null) {
            return ToolResult::error('Paket-Position nicht vorhanden.', 'NOT_FOUND');
        }
        if (($guard = $this->guardOwned($team, FoodAlchemistPaket::class, (int) $row->package_id, 'Paket')) !== null) {
            return $guard;
        }

        try {
            app(PaketService::class)->setGerichtGeschirr(
                $team,
                $rowId,
                $role,
                isset($arguments['item_id']) ? (int) $arguments['item_id'] : null
            );
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['row_id' => $rowId, 'role' => $role, 'item_id' => isset($arguments['item_id']) ? (int) $arguments['item_id'] : null]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'paket', 'gericht', 'geschirr', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.paket_gerichte.SET'],
            'examples' => ['Weise Paket-Position 5 als Haupt-Geschirr den Artikel 3 zu.'],
        ];
    }
}
