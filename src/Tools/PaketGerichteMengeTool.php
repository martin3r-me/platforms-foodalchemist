<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Services\ConceptPaketService;
use Platform\FoodAlchemist\Services\ConceptService;

/** Menge/Person einer Paket-Position setzen. */
class PaketGerichteMengeTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.paket_gerichte.MENGE';
    }

    public function getDescription(): string
    {
        return 'Pakete = Concepter-Pakete (concepts mit kind=paket), dieselben, die der Concepter im Reiter „Pakete“ zeigt. Setzt die Menge/Person einer Gericht-Position (row_id aus pakete.GET) im Paket-Entwurf. quantity weglassen = leeren.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'paket_id' => ['type' => 'integer', 'description' => 'Paket-Id.'],
                'row_id' => ['type' => 'integer', 'description' => 'Positions-Id (pakete.GET → dishes[].row_id).'],
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
        $paket = $this->paketConcept($team, (int) ($arguments['paket_id'] ?? 0));
        if ($paket instanceof ToolResult) {
            return $paket;
        }
        $rowId = (int) ($arguments['row_id'] ?? 0);

        try {
            app(ConceptPaketService::class)->setMenge($team, $paket->id, $rowId, isset($arguments['quantity']) ? (float) $arguments['quantity'] : null);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['paket_id' => (int) $paket->id, 'row_id' => $rowId]);
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
