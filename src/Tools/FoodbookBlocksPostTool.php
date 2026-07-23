<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Services\VocabularyService;

/**
 * Phase B: Block in einem Kapitel anlegen — das inhaltliche Atom des Foodbooks.
 * Ein Kapitel trägt Konzepte (Paket, €/Gast) UND direkte Einzel-Gerichte (€/Position):
 * Paket-Block via concept_id (type=concept_ref) · Einzel-Gericht via sales_recipe_id
 * (type=recipe_ref, Spec 19 Entscheidung 5) · dazu Text/Header/Spacer.
 * Nur solange das Foodbook draft ist. Optional mit Preis-Staffel.
 */
class FoodbookBlocksPostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    /**
     * MCP-Ergonomie-Labels → kanonische Code-Werte (FoodAlchemistFoodbookBlock::PRICE_BASES).
     * Behebt die „pro_stueck-Falle": das Tool schrieb bislang pro_person/pro_stueck ROH in
     * die Spalte, blockPreis() kennt aber nur person|pauschal|staffel → pro_stueck fiel
     * still auf Per-Person (×Pax) zurück. Kanonische Werte werden idempotent durchgereicht.
     */
    private const PRICE_BASIS_MAP = [
        'pro_person' => 'person',
        'pro_stueck' => 'pauschal',   // €/Position, flach (kein ×Pax)
        'pauschal' => 'pauschal',
        'person' => 'person',
        'staffel' => 'staffel',
    ];
    public function getName(): string
    {
        return 'foodalchemist.foodbook_blocks.POST';
    }

    public function getDescription(): string
    {
        return 'Legt einen Block in einem Kapitel eines draft-Foodbooks an (Position ans Ende). '
            . 'Ein Kapitel trägt Paket-Blöcke UND direkte Einzel-Gerichte: '
            . 'concept_ref (concept_id = Konzept/Paket, €/Gast, via foodalchemist.concepts.SEARCH) '
            . 'oder recipe_ref (sales_recipe_id = echtes VK-Gericht, €/Position, via foodalchemist.verkaufsrezepte.SEARCH). '
            . 'Weitere Typen: text (freier Text/Notiz) | header_neutral | header_frei | header_frei_preis | spacer. '
            . 'price_basis: pro_person (€/Gast, ×Pax) | pro_stueck (€/Position, flach) | pauschal (flach). '
            . 'Optional staffel: [{min_persons, price}] für Pax-abhängige Preise.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'chapter_id' => ['type' => 'integer'],
                'type' => ['type' => 'string', 'enum' => ['text', 'concept_ref', 'recipe_ref', 'header_neutral', 'header_frei', 'header_frei_preis', 'spacer'], 'default' => 'text'],
                'label' => ['type' => 'string', 'description' => 'Interner Titel des Blocks'],
                'customer_text' => ['type' => 'string', 'description' => 'Kundenseitiger Angebotstext'],
                'concept_id' => ['type' => 'integer', 'description' => 'Konzept/Paket bei type=concept_ref'],
                'sales_recipe_id' => ['type' => 'integer', 'description' => 'Echtes VK-Gericht bei type=recipe_ref (verkauf()-Scope, keine Slot-Variante)'],
                'quantity' => ['type' => 'number'],
                'unit' => ['type' => 'string', 'description' => 'Einheiten-Slug, z. B. stk, portion'],
                'price_value' => ['type' => 'number'],
                'price_basis' => ['type' => 'string', 'enum' => ['pro_person', 'pro_stueck', 'pauschal'], 'description' => 'Basis für price_value'],
                'visible' => ['type' => 'boolean', 'default' => true],
                'interne_bemerkung' => ['type' => 'string'],
                'staffel' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'min_persons' => ['type' => 'integer', 'minimum' => 1],
                            'price' => ['type' => 'number'],
                        ],
                        'required' => ['min_persons', 'price'],
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
        if (isset($arguments['concept_id'])
            && ! FoodAlchemistConcept::visibleToTeam($team)->whereKey((int) $arguments['concept_id'])->exists()) {
            return ToolResult::error('concept_id nicht sichtbar/vorhanden — via foodalchemist.concepts.SEARCH ermitteln.', 'NOT_FOUND');
        }
        // recipe_ref: sichtbares, echtes VK-Gericht (keine Slot-Variante) — spiegelt
        // pruefeRecipeRef() im Service, aber mit MCP-typisiertem NOT_FOUND vor dem Write.
        if (isset($arguments['sales_recipe_id'])
            && ! FoodAlchemistRecipe::visibleToTeam($team)->verkauf()
                ->whereNull('variant_source_recipe_id')
                ->whereKey((int) $arguments['sales_recipe_id'])->exists()) {
            return ToolResult::error('sales_recipe_id nicht sichtbar/kein VK-Gericht — via foodalchemist.verkaufsrezepte.SEARCH ermitteln.', 'NOT_FOUND');
        }

        $daten = array_intersect_key($arguments, array_flip([
            'type', 'label', 'customer_text', 'interne_bemerkung',
            'concept_id', 'sales_recipe_id', 'quantity', 'price_value', 'price_basis', 'visible',
        ]));
        // price_basis-Angleich: MCP-Ergonomie-Label → kanonischer Code-Wert (pro_stueck-Falle).
        if (($daten['price_basis'] ?? '') !== '') {
            $kanon = self::PRICE_BASIS_MAP[$daten['price_basis']] ?? null;
            if ($kanon === null) {
                return ToolResult::error('Unbekannte price_basis "' . $daten['price_basis'] . '" — erlaubt: pro_person, pro_stueck, pauschal.', 'VALIDATION_ERROR');
            }
            $daten['price_basis'] = $kanon;
        }
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
