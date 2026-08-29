<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistAngebot;
use Platform\FoodAlchemist\Services\AngebotService;

/**
 * MCP-Steuerbarkeit · D10: Angebot-Status setzen (anfrage/in_arbeit/angebot/versendet/angenommen/abgelehnt).
 * Kundensichtbare Stände → Confirm-Marker.
 */
class AngeboteStatusTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.angebote.STATUS';
    }

    public function getDescription(): string
    {
        return 'Setzt den Status eines team-eigenen Angebots (anfrage/in_arbeit/angebot/versendet/angenommen/abgelehnt).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'Angebot-Id.'],
                'status' => ['type' => 'string', 'enum' => ['anfrage', 'in_arbeit', 'angebot', 'versendet', 'angenommen', 'abgelehnt'], 'description' => 'Neuer Status.'],
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
        if (($guard = $this->guardOwned($team, FoodAlchemistAngebot::class, $id, 'Angebot')) !== null) {
            return $guard;
        }

        try {
            app(AngebotService::class)->setStatus($team, $id, $status);
        } catch (\RuntimeException | \ValueError $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['id' => $id, 'status' => $status]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'angebot', 'status', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'confirmation_required' => true,
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.angebote.PUT'],
            'examples' => ['Setze Angebot 5 auf versendet.'],
        ];
    }
}
