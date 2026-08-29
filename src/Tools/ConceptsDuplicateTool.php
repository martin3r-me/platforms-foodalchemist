<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Services\ConceptService;

/** MCP-Steuerbarkeit · D5: Konzept duplizieren (Kopie gehört dem eigenen Team). */
class ConceptsDuplicateTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.concepts.DUPLICATE';
    }

    public function getDescription(): string
    {
        return 'Dupliziert ein sichtbares Konzept; die Kopie gehört dem eigenen Team.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['id' => ['type' => 'integer', 'description' => 'Konzept-Id (sichtbar).']],
            'required' => ['id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $id = (int) ($arguments['id'] ?? 0);
        if (! FoodAlchemistConcept::visibleToTeam($team)->whereKey($id)->exists()) {
            return ToolResult::error('Konzept nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }

        try {
            $c = app(ConceptService::class)->duplicate($team, $id);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['id' => (int) $c->id, 'name' => $c->name]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'concept', 'duplizieren', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['creates'],
            'related_tools' => ['foodalchemist.concepts.TEMPLATE_FORK'],
            'examples' => ['Dupliziere Konzept 7.'],
        ];
    }
}
