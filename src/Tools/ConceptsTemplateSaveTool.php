<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Services\ConceptService;

/** MCP-Steuerbarkeit · D5: Konzept als Vorlage speichern (team-eigen). */
class ConceptsTemplateSaveTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.concepts.TEMPLATE_SAVE';
    }

    public function getDescription(): string
    {
        return 'Speichert ein team-eigenes Konzept als wiederverwendbare Vorlage (optional unter neuem Namen).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'Konzept-Id (team-eigen).'],
                'name' => ['type' => 'string', 'description' => 'Optionaler Vorlagen-Name.'],
            ],
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
        if (($guard = $this->guardOwned($team, FoodAlchemistConcept::class, $id, 'Konzept')) !== null) {
            return $guard;
        }

        try {
            $c = app(ConceptService::class)->alsVorlageSpeichern($team, $id, isset($arguments['name']) ? (string) $arguments['name'] : null);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['id' => (int) $c->id, 'name' => $c->name]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'concept', 'vorlage', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['creates'],
            'related_tools' => ['foodalchemist.concepts.TEMPLATE_FORK'],
            'examples' => ['Speichere Konzept 7 als Vorlage „Sommer-Buffet".'],
        ];
    }
}
