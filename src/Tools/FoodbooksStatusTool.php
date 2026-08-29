<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Services\FoodbookService;

/**
 * MCP-Steuerbarkeit · D7: Status eines Foodbooks setzen (entwurf/aktiv/inaktiv/archiviert).
 * Kein Top-Level-Delete via MCP — Archivieren/Inaktiv ist der reversible Ersatz. Confirm-Marker.
 */
class FoodbooksStatusTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.foodbooks.STATUS';
    }

    public function getDescription(): string
    {
        return 'Setzt den Status eines team-eigenen Foodbooks (entwurf/aktiv/inaktiv/archiviert). '
            . 'archiviert/inaktiv = reversibler Ersatz fürs Löschen (kein MCP-Delete für Kundendokumente). aktiv = kundensichtbar.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'Foodbook-Id.'],
                'status' => ['type' => 'string', 'enum' => ['entwurf', 'aktiv', 'inaktiv', 'archiviert'], 'description' => 'Neuer Status.'],
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
        $status = trim((string) ($arguments['status'] ?? ''));
        if ($status === '') {
            return ToolResult::error('status ist Pflicht.', 'VALIDATION_ERROR');
        }
        $id = (int) ($arguments['id'] ?? 0);
        if (($guard = $this->guardOwned($team, FoodAlchemistFoodbook::class, $id, 'Foodbook')) !== null) {
            return $guard;
        }

        try {
            app(FoodbookService::class)->update($team, $id, ['status' => $status]);
        } catch (\RuntimeException | \ValueError $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['id' => $id, 'status' => $status]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'foodbook', 'status', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'confirmation_required' => true,
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.foodbooks.PUT', 'foodalchemist.foodbook_presentation.PUBLISH'],
            'examples' => ['Archiviere Foodbook 5.'],
        ];
    }
}
