<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistGeschirrSupplier;
use Platform\FoodAlchemist\Services\GeschirrService;

/** MCP-Steuerbarkeit · D4: Geschirr-Lieferant bearbeiten (team-eigen). */
class GeschirrSuppliersPutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.geschirr_suppliers.PUT';
    }

    public function getDescription(): string
    {
        return 'Bearbeitet einen team-eigenen Geschirr-Lieferanten.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'Geschirr-Lieferanten-Id (team-eigen).'],
                'input' => ['type' => 'object', 'description' => 'Zu schreibende Stammdaten.'],
            ],
            'required' => ['id', 'input'],
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
        $id = (int) ($arguments['id'] ?? 0);
        if (($guard = $this->guardOwned($team, FoodAlchemistGeschirrSupplier::class, $id, 'Geschirr-Lieferant')) !== null) {
            return $guard;
        }

        try {
            $s = app(GeschirrService::class)->updateSupplier($team, $id, $input);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['id' => (int) $s->id, 'name' => $s->name ?? null]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'geschirr', 'lieferant', 'bearbeiten', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.geschirr_suppliers.DEACTIVATE'],
            'examples' => ['Ändere den Namen von Geschirr-Lieferant 3.'],
        ];
    }
}
