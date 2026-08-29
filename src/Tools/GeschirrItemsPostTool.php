<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistGeschirrSupplier;
use Platform\FoodAlchemist\Services\GeschirrService;

/** MCP-Steuerbarkeit · D4: Geschirr-Artikel an einem team-eigenen Geschirr-Lieferanten anlegen. */
class GeschirrItemsPostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.geschirr_items.POST';
    }

    public function getDescription(): string
    {
        return 'Legt einen Geschirr-Artikel an einem team-eigenen Geschirr-Lieferanten an (input mit Name/Bezeichnung).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'supplier_id' => ['type' => 'integer', 'description' => 'Geschirr-Lieferanten-Id (team-eigen).'],
                'input' => ['type' => 'object', 'description' => 'Artikel-Felder.'],
            ],
            'required' => ['supplier_id', 'input'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $input = $arguments['input'] ?? null;
        if (! is_array($input) || $input === []) {
            return ToolResult::error('input muss ein nicht-leeres Objekt sein.', 'VALIDATION_ERROR');
        }
        $supplierId = (int) ($arguments['supplier_id'] ?? 0);
        if (($guard = $this->guardOwned($team, FoodAlchemistGeschirrSupplier::class, $supplierId, 'Geschirr-Lieferant')) !== null) {
            return $guard;
        }

        try {
            $i = app(GeschirrService::class)->createItem($team, $supplierId, $input);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['id' => (int) $i->id, 'supplier_id' => $supplierId, 'name' => $i->label ?? $i->name ?? null]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'geschirr', 'artikel', 'anlegen', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['creates'],
            'related_tools' => ['foodalchemist.geschirr_items.PUT'],
            'examples' => ['Lege bei Geschirr-Lieferant 3 den Artikel „Teller flach 28cm" an.'],
        ];
    }
}
