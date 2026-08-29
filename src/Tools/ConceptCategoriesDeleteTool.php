<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistConceptCategory;
use Platform\FoodAlchemist\Services\ConceptService;

/**
 * MCP-Steuerbarkeit · D5c: Konzept-Kategorie löschen. Kinder + zugeordnete Konzepte werden an die
 * Eltern-Kategorie umgehängt (kein Konzept-Verlust), die Kategorie-Zeile dann entfernt. `confirm:true` Pflicht.
 */
class ConceptCategoriesDeleteTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.concept_categories.DELETE';
    }

    public function getDescription(): string
    {
        return 'Löscht eine team-eigene Konzept-Kategorie (Kinder/Konzepte wandern an die Eltern). Erfordert confirm=true.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'Kategorie-Id.'],
                'confirm' => ['type' => 'boolean', 'description' => 'Muss true sein (destruktive Aktion).'],
            ],
            'required' => ['id', 'confirm'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        if (($arguments['confirm'] ?? false) !== true) {
            return ToolResult::error('Löschen erfordert confirm=true (destruktive Aktion).', 'CONFIRM_REQUIRED');
        }
        $id = (int) ($arguments['id'] ?? 0);
        if (($guard = $this->guardOwned($team, FoodAlchemistConceptCategory::class, $id, 'Kategorie')) !== null) {
            return $guard;
        }

        try {
            app(ConceptService::class)->deleteCategory($team, $id);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['id' => $id, 'deleted' => true]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'concept', 'category', 'delete'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'destructive',
            'confirmation_required' => true,
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['deletes', 'updates'],
            'related_tools' => ['foodalchemist.concept_categories.POST', 'foodalchemist.concept_categories.PUT'],
            'examples' => ['Lösche Kategorie 4 (confirm=true).'],
        ];
    }
}
