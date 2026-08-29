<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Services\ConceptService;

/** MCP-Steuerbarkeit · D5: Status eines team-eigenen Konzepts setzen (Wert vom Service validiert). */
class ConceptsStatusTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.concepts.STATUS';
    }

    public function getDescription(): string
    {
        return 'Setzt den Status eines team-eigenen Konzepts (z.B. draft/active/archiviert; Wert vom Service geprüft). '
            . 'Aktivierung macht das Konzept sichtbar/nutzbar — bewusst setzen.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'Konzept-Id (team-eigen).'],
                'status' => ['type' => 'string', 'description' => 'Neuer Status.'],
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
        if (($guard = $this->guardOwned($team, FoodAlchemistConcept::class, $id, 'Konzept')) !== null) {
            return $guard;
        }

        try {
            app(ConceptService::class)->setStatus($team, $id, $status);
        } catch (\RuntimeException | \ValueError $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['id' => $id, 'status' => $status]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'concept', 'status', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'confirmation_required' => true,
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.concepts.PUT'],
            'examples' => ['Aktiviere Konzept 7.'],
        ];
    }
}
