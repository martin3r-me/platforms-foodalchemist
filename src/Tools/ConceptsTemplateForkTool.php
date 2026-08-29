<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Services\ConceptService;

/** MCP-Steuerbarkeit · D5: Neues Konzept aus einer (sichtbaren) Vorlage forken. */
class ConceptsTemplateForkTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.concepts.TEMPLATE_FORK';
    }

    public function getDescription(): string
    {
        return 'Erzeugt ein neues team-eigenes Konzept aus einer sichtbaren Vorlage.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'template_id' => ['type' => 'integer', 'description' => 'Vorlagen-Konzept-Id (sichtbar).'],
                'name' => ['type' => 'string', 'description' => 'Name des neuen Konzepts.'],
            ],
            'required' => ['template_id', 'name'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $name = trim((string) ($arguments['name'] ?? ''));
        if ($name === '') {
            return ToolResult::error('name ist Pflicht.', 'VALIDATION_ERROR');
        }
        $templateId = (int) ($arguments['template_id'] ?? 0);
        if (! FoodAlchemistConcept::visibleToTeam($team)->whereKey($templateId)->exists()) {
            return ToolResult::error('Vorlage nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }

        try {
            $c = app(ConceptService::class)->forkVonVorlage($team, $templateId, $name);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['id' => (int) $c->id, 'name' => $c->name]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'concept', 'vorlage', 'fork', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['creates'],
            'related_tools' => ['foodalchemist.concepts.TEMPLATE_SAVE'],
            'examples' => ['Erzeuge aus Vorlage 5 ein Konzept „Herbst-Menü".'],
        ];
    }
}
