<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistConceptCategory;
use Platform\FoodAlchemist\Services\ConceptService;

/** MCP-Steuerbarkeit · D5c: Konzept-Kategorie (Ordnungs-Taxonomie) anlegen. */
class ConceptCategoriesPostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.concept_categories.POST';
    }

    public function getDescription(): string
    {
        return 'Legt eine team-eigene Konzept-Kategorie an (name; optional parent_id für Unterkategorie).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'Kategorie-Name.'],
                'parent_id' => ['type' => 'integer', 'description' => 'Optionale Eltern-Kategorie (team-eigen).'],
            ],
            'required' => ['name'],
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
        $parentId = isset($arguments['parent_id']) ? (int) $arguments['parent_id'] : null;
        if ($parentId) {
            if (($guard = $this->guardOwned($team, FoodAlchemistConceptCategory::class, $parentId, 'Eltern-Kategorie')) !== null) {
                return $guard;
            }
        }

        try {
            $cat = app(ConceptService::class)->createCategory($team, $name, $parentId);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['id' => (int) $cat->id, 'name' => $cat->name, 'parent_id' => $cat->parent_id !== null ? (int) $cat->parent_id : null]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'concept', 'category', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['creates'],
            'related_tools' => ['foodalchemist.concept_categories.PUT', 'foodalchemist.concept_categories.DELETE'],
            'examples' => ['Lege die Konzept-Kategorie „Sommer-Menüs" an.'],
        ];
    }
}
