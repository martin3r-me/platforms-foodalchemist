<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplan;
use Platform\FoodAlchemist\Services\SpeiseplanService;

/**
 * MCP-Steuerbarkeit · D9: Status eines Speiseplans setzen (entwurf/aktiv/inaktiv/archiviert).
 * Kein Top-Level-Delete via MCP — Archivieren/Inaktiv ist der reversible Ersatz. Confirm-Marker.
 */
class SpeiseplaeneStatusTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.speiseplaene.STATUS';
    }

    public function getDescription(): string
    {
        return 'Setzt den Status eines team-eigenen Speiseplans (entwurf/aktiv/inaktiv/archiviert). '
            . 'archiviert/inaktiv = reversibler Ersatz fürs Löschen (kein MCP-Delete für Kundendokumente).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'Speiseplan-Id.'],
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
        if (($guard = $this->guardOwned($team, FoodAlchemistSpeiseplan::class, $id, 'Speiseplan')) !== null) {
            return $guard;
        }

        try {
            app(SpeiseplanService::class)->update($team, $id, ['status' => $status]);
        } catch (\RuntimeException | \ValueError $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['id' => $id, 'status' => $status]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'speiseplan', 'status', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'confirmation_required' => true,
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.speiseplaene.PUT'],
            'examples' => ['Archiviere Speiseplan 3.'],
        ];
    }
}
