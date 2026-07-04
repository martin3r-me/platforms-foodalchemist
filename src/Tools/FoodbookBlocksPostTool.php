<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Services\VocabularyService;

/**
 * Phase B: Block in einem Kapitel anlegen — das inhaltliche Atom des Foodbooks
 * (Gericht via sales_recipe_id, Konzept-Paket via concept_id, oder Text/Header/
 * Spacer). Nur solange das Foodbook draft ist. Optional mit Preis-Staffel.
 */
class FoodbookBlocksPostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.foodbook_blocks.POST';
    }

    public function getDescription(): string
    {
        return 'Legt einen Block in einem Kapitel eines draft-Foodbooks an (Position ans Ende). '
            . 'type: text (mit sales_recipe_id = Gericht; vorher foodalchemist.verkaufsrezepte.SEARCH) | '
            . 'concept_ref (concept_id = Konzept/Paket) | header_neutral | header_frei | header_frei_preis | spacer. '
            . 'Optional staffel: [{min_personen, preis}] für Pax-abhängige Preise.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'chapter_id' => ['type' => 'integer'],
                'type' => ['type' => 'string', 'enum' => ['text', 'concept_ref', 'header_neutral', 'header_frei', 'header_frei_preis', 'spacer'], 'default' => 'text'],
                'label' => ['type' => 'string', 'description' => 'Interner Titel des Blocks'],
                'kundentext' => ['type' => 'string', 'description' => 'Kundenseitiger Angebotstext'],
                'sales_recipe_id' => ['type' => 'integer', 'description' => 'Verkaufsrezept (Gericht) — via verkaufsrezepte.SEARCH ermitteln'],
                'concept_id' => ['type' => 'integer', 'description' => 'Konzept/Paket bei type=concept_ref'],
                'quantity' => ['type' => 'number'],
                'unit' => ['type' => 'string', 'description' => 'Einheiten-Slug, z. B. stk, portion'],
                'price_value' => ['type' => 'number'],
                'preis_basis' => ['type' => 'string', 'enum' => ['pro_person', 'pro_stueck', 'pauschal'], 'description' => 'Basis für price_value'],
                'sichtbar' => ['type' => 'boolean', 'default' => true],
                'interne_bemerkung' => ['type' => 'string'],
                'staffel' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'min_personen' => ['type' => 'integer', 'minimum' => 1],
                            'preis' => ['type' => 'number'],
                        ],
                        'required' => ['min_personen', 'preis'],
                    ],
                ],
            ],
            'required' => ['chapter_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $kapitel = FoodAlchemistFoodbookKapitel::whereKey((int) $arguments['chapter_id'])->first();
        $fb = $kapitel !== null
            ? FoodAlchemistFoodbook::visibleToTeam($team)->whereKey($kapitel->foodbook_id)->first()
            : null;
        if ($fb === null) {
            return ToolResult::error('Kapitel nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }
        if ((string) $fb->status !== 'draft') {
            return ToolResult::error("Foodbook hat Status \"{$fb->status}\" — via MCP ist nur draft editierbar.", 'ACCESS_DENIED');
        }
        if (isset($arguments['sales_recipe_id'])
            && ! FoodAlchemistRecipe::visibleToTeam($team)->whereKey((int) $arguments['sales_recipe_id'])->exists()) {
            return ToolResult::error('sales_recipe_id nicht sichtbar/vorhanden — via foodalchemist.verkaufsrezepte.SEARCH ermitteln.', 'NOT_FOUND');
        }

        $daten = array_intersect_key($arguments, array_flip([
            'type', 'label', 'kundentext', 'interne_bemerkung', 'sales_recipe_id',
            'concept_id', 'quantity', 'price_value', 'preis_basis', 'sichtbar',
        ]));
        if (($arguments['unit'] ?? '') !== '') {
            $unit = app(VocabularyService::class)->findEinheit($team, (string) $arguments['unit']);
            if ($unit === null) {
                return ToolResult::error('Unbekannte Einheit "' . $arguments['unit'] . '".', 'VALIDATION_ERROR');
            }
            $daten['unit_vocab_id'] = $unit->id;
        }
        $svc = app(FoodbookService::class);

        try {
            $block = $svc->addBlock($team, $kapitel->id, $daten);
            if (! empty($arguments['staffel'])) {
                $svc->setStaffel($team, $block->id, $arguments['staffel']);
            }
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['block' => [
            'id' => $block->id, 'type' => $block->type, 'position' => $block->position,
            'label' => $block->label, 'sales_recipe_id' => $block->sales_recipe_id,
            'staffel_zeilen' => count($arguments['staffel'] ?? []),
        ]]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'foodbook', 'block', 'gericht', 'staffel', 'anlegen', 'draft'],
            'read_only' => false,
            'idempotent' => false,
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'side_effects' => ['creates'],
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.verkaufsrezepte.SEARCH', 'foodalchemist.foodbook_kapitel.POST', 'foodalchemist.recipes.POST'],
            'examples' => ['Füge Kapitel 34 das Verkaufsrezept 812 als Gericht mit Staffelpreis hinzu'],
        ];
    }
}
