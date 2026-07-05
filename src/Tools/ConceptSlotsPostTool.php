<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\VocabularyService;

/**
 * Phase C: Slot in einem draft-Konzept anlegen und optional direkt befüllen
 * (Gericht via sales_recipe_id ODER Paket via package_id) + Wording setzen.
 */
class ConceptSlotsPostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.concept_slots.POST';
    }

    public function getDescription(): string
    {
        return 'Legt einen Slot in einem draft-Konzept an (Position ans Ende) und befüllt ihn optional: '
            . 'sales_recipe_id = Gericht (via verkaufsrezepte.SEARCH), package_id = Paket (XOR). role z. B. '
            . 'vorspeise/hauptgang/dessert, wording = kundenseitiger Anzeigename.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'concept_id' => ['type' => 'integer'],
                'role' => ['type' => 'string', 'description' => 'z. B. vorspeise, hauptgang, dessert, snack'],
                'title' => ['type' => 'string'],
                'is_pflicht' => ['type' => 'boolean', 'default' => true],
                'sales_recipe_id' => ['type' => 'integer'],
                'package_id' => ['type' => 'integer'],
                'quantity' => ['type' => 'number'],
                'unit' => ['type' => 'string', 'description' => 'Einheiten-Slug, z. B. stk, portion'],
                'wording' => ['type' => 'string', 'description' => 'Kundenseitiger Anzeigename der Position'],
            ],
            'required' => ['concept_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $concept = FoodAlchemistConcept::visibleToTeam($team)->whereKey((int) $arguments['concept_id'])->first();
        if ($concept === null) {
            return ToolResult::error('Konzept nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }
        if ((string) $concept->status !== 'draft') {
            return ToolResult::error("Konzept hat Status \"{$concept->status}\" — via MCP ist nur draft editierbar.", 'ACCESS_DENIED');
        }
        if (isset($arguments['sales_recipe_id'], $arguments['package_id'])) {
            return ToolResult::error('sales_recipe_id und package_id sind XOR — nur eines angeben.', 'VALIDATION_ERROR');
        }
        if (isset($arguments['sales_recipe_id'])
            && ! FoodAlchemistRecipe::visibleToTeam($team)->whereKey((int) $arguments['sales_recipe_id'])->exists()) {
            return ToolResult::error('sales_recipe_id nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }
        $svc = app(ConceptService::class);

        try {
            $slot = $svc->addSlot($team, $concept->id, [
                'role' => $arguments['role'] ?? null,
                'title' => $arguments['title'] ?? null,
                'is_pflicht' => (bool) ($arguments['is_pflicht'] ?? true),
            ]);
            if (isset($arguments['sales_recipe_id']) || isset($arguments['package_id'])) {
                $fill = array_intersect_key($arguments, array_flip(['sales_recipe_id', 'package_id', 'quantity']));
                if (($arguments['unit'] ?? '') !== '') {
                    $unit = app(VocabularyService::class)->findEinheit($team, (string) $arguments['unit']);
                    if ($unit === null) {
                        return ToolResult::error('Unbekannte Einheit "' . $arguments['unit'] . '".', 'VALIDATION_ERROR');
                    }
                    $fill['unit_vocab_id'] = $unit->id;
                }
                $slot = $svc->fillSlot($team, $slot->id, $fill);
            }
            if (($arguments['wording'] ?? '') !== '') {
                $slot = $svc->setSlotWording($team, $slot->id, (string) $arguments['wording']);
            }
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['slot' => [
            'id' => $slot->id, 'position' => $slot->position, 'type' => $slot->type,
            'role' => $slot->role, 'sales_recipe_id' => $slot->sales_recipe_id, 'package_id' => $slot->package_id,
        ]]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'concept', 'slot', 'gericht', 'paket', 'anlegen', 'draft'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true,
            'side_effects' => ['creates'], 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.concepts.POST', 'foodalchemist.verkaufsrezepte.SEARCH'],
            'examples' => ['Füge Konzept 42 das Verkaufsrezept 1373 als Hauptgang hinzu'],
        ];
    }
}
