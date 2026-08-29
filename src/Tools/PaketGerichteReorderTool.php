<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistPaket;
use Platform\FoodAlchemist\Services\PaketService;

/** MCP-Steuerbarkeit · D5d: Gericht-Positionen eines Pakets neu ordnen. */
class PaketGerichteReorderTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.paket_gerichte.REORDER';
    }

    public function getDescription(): string
    {
        return 'Ordnet die Gericht-Positionen eines team-eigenen Pakets neu (row_ids in Zielreihenfolge).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'paket_id' => ['type' => 'integer', 'description' => 'Paket-Id.'],
                'ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Positions-Zeilen-Ids in Zielreihenfolge.'],
            ],
            'required' => ['paket_id', 'ids'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $ids = $arguments['ids'] ?? null;
        if (! is_array($ids) || $ids === []) {
            return ToolResult::error('ids muss ein nicht-leeres Array sein.', 'VALIDATION_ERROR');
        }
        $paketId = (int) ($arguments['paket_id'] ?? 0);
        if (($guard = $this->guardOwned($team, FoodAlchemistPaket::class, $paketId, 'Paket')) !== null) {
            return $guard;
        }

        try {
            app(PaketService::class)->reorderGerichte($team, $paketId, array_map('intval', $ids));
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['paket_id' => $paketId, 'ids' => array_map('intval', $ids)]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'paket', 'gericht', 'reorder', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.paket_gerichte.SET'],
            'examples' => ['Ordne die Positionen von Paket 12 als [7,3,9].'],
        ];
    }
}
