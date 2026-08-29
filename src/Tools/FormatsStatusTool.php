<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistFormat;
use Platform\FoodAlchemist\Services\FormatService;

/** MCP-Steuerbarkeit · D6: Status einer team-eigenen Format-Edition setzen (draft/active/archiviert). */
class FormatsStatusTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.formats.STATUS';
    }

    public function getDescription(): string
    {
        return 'Setzt den Status eines team-eigenen Formats (draft/active/archiviert). '
            . 'active macht das Format in Pickern nutzbar — bewusst setzen (reversibel).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'Format-Id.'],
                'status' => ['type' => 'string', 'enum' => ['draft', 'active', 'archiviert'], 'description' => 'Neuer Status.'],
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
        if (($guard = $this->guardOwned($team, FoodAlchemistFormat::class, $id, 'Format')) !== null) {
            return $guard;
        }

        try {
            app(FormatService::class)->setStatus($team, $id, $status);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['id' => $id, 'status' => $status]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'format', 'status', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'confirmation_required' => true,
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.formats.PUT'],
            'examples' => ['Aktiviere Format 3.'],
        ];
    }
}
