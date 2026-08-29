<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistPaket;
use Platform\FoodAlchemist\Services\PaketService;

/** MCP-Steuerbarkeit · D5d: Menge/Person einer Paket-Position setzen (danach Preis-Recompute). */
class PaketGerichteMengeTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.paket_gerichte.MENGE';
    }

    public function getDescription(): string
    {
        return 'Setzt die Menge/Person einer Gericht-Position im Paket (row_id). quantity weglassen = leeren.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'paket_id' => ['type' => 'integer', 'description' => 'Paket-Id.'],
                'row_id' => ['type' => 'integer', 'description' => 'Positions-Zeilen-Id (paket_gerichte).'],
                'quantity' => ['type' => 'number', 'description' => 'Menge/Person (weglassen = leeren).'],
            ],
            'required' => ['paket_id', 'row_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $paketId = (int) ($arguments['paket_id'] ?? 0);
        if (($guard = $this->guardOwned($team, FoodAlchemistPaket::class, $paketId, 'Paket')) !== null) {
            return $guard;
        }

        try {
            app(PaketService::class)->setGerichtMenge(
                $team,
                $paketId,
                (int) ($arguments['row_id'] ?? 0),
                isset($arguments['quantity']) ? (float) $arguments['quantity'] : null
            );
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['paket_id' => $paketId, 'row_id' => (int) ($arguments['row_id'] ?? 0)]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'paket', 'gericht', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.paket_gerichte.SET'],
            'examples' => ['Setze bei Paket 12, Position 3 die Menge 80 g.'],
        ];
    }
}
