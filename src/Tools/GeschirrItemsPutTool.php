<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistGeschirrItem;
use Platform\FoodAlchemist\Services\GeschirrService;

/** MCP-Steuerbarkeit · D4: Geschirr-Artikel bearbeiten (team-eigen). */
class GeschirrItemsPutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.geschirr_items.PUT';
    }

    public function getDescription(): string
    {
        return 'Bearbeitet einen team-eigenen Geschirr-Artikel.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'Geschirr-Artikel-Id (team-eigen).'],
                'input' => ['type' => 'object', 'description' => 'Zu schreibende Felder.'],
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
        if (($guard = $this->guardOwned($team, FoodAlchemistGeschirrItem::class, $id, 'Geschirr-Artikel')) !== null) {
            return $guard;
        }

        try {
            $i = app(GeschirrService::class)->updateItem($team, $id, $input);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['id' => (int) $i->id, 'name' => $i->label ?? $i->name ?? null]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'geschirr', 'artikel', 'bearbeiten', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.geschirr_items.DEACTIVATE'],
            'examples' => ['Ändere den Namen von Geschirr-Artikel 21.'],
        ];
    }
}
