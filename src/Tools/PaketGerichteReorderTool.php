<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Services\ConceptPaketService;
use Platform\FoodAlchemist\Services\ConceptService;

/** Positionen eines Concepter-Pakets neu ordnen. */
class PaketGerichteReorderTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.paket_gerichte.REORDER';
    }

    public function getDescription(): string
    {
        return 'Pakete = Concepter-Pakete (concepts mit kind=paket), dieselben, die der Concepter im Reiter „Pakete“ zeigt. Ordnet die Positionen eines Paket-Entwurfs neu (ids = row_ids in Zielreihenfolge; nicht genannte rücken dahinter).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'paket_id' => ['type' => 'integer', 'description' => 'Paket-Id.'],
                'ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Positions-Ids in Zielreihenfolge.'],
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
        $paket = $this->paketConcept($team, (int) ($arguments['paket_id'] ?? 0));
        if ($paket instanceof ToolResult) {
            return $paket;
        }
        app(ConceptPaketService::class)->reorder($team, $paket->id, array_map('intval', $ids));

        return ToolResult::success(['paket_id' => (int) $paket->id, 'ids' => array_map('intval', $ids)]);
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
