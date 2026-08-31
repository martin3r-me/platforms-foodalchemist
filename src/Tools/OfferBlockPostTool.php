<?php

namespace Platform\FoodAlchemist\Tools;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistOfferBlock;
use Platform\FoodAlchemist\Models\FoodAlchemistOfferChapter;
use Platform\FoodAlchemist\Services\OfferCompositionService;

/**
 * #380 Composer · MCP-Lockstep: einen Block in einem Angebot-Kapitel anlegen (Position ans Ende).
 * concept_ref (concept_id = Konzept/Paket, live) oder recipe_ref (sales_recipe_id = echtes VK-Gericht),
 * dazu header/header_preis/text/spacer/image. Format-Kapitel tragen KEINE eigenen Blöcke (Inhalt
 * kommt live aus dem Format). Spiegelt FoodbookBlocksPostTool, offer-scoped über
 * {@see OfferCompositionService::addBlock}. Owner-Guard (D1) über das Kapitel.
 */
class OfferBlockPostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.offer_block.POST';
    }

    public function getDescription(): string
    {
        return 'Legt einen Block in einem Angebot-Kapitel an (Position ans Ende). '
            . 'concept_ref (concept_id = Konzept/Paket, via foodalchemist.concepts.SEARCH) oder '
            . 'recipe_ref (sales_recipe_id = echtes VK-Gericht, via foodalchemist.verkaufsrezepte.SEARCH). '
            . 'Weitere Typen: header | header_preis | text | spacer | image. '
            . 'price_basis (nur header_preis): person | pauschal. Format-Kapitel tragen keine eigenen Blöcke.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'chapter_id' => ['type' => 'integer', 'description' => 'Kapitel-Id.'],
                'type' => ['type' => 'string', 'enum' => FoodAlchemistOfferBlock::BLOCK_TYPES, 'default' => 'text'],
                'label' => ['type' => 'string', 'description' => 'Interner Titel des Blocks.'],
                'wording' => ['type' => 'string', 'description' => 'Kundenseitiges Wording (Override).'],
                'customer_text' => ['type' => 'string', 'description' => 'Kundenseitiger Angebotstext.'],
                'interne_bemerkung' => ['type' => 'string'],
                'concept_id' => ['type' => 'integer', 'description' => 'Konzept/Paket bei type=concept_ref.'],
                'sales_recipe_id' => ['type' => 'integer', 'description' => 'Echtes VK-Gericht bei type=recipe_ref (keine Slot-Variante).'],
                'quantity' => ['type' => 'number', 'description' => 'Mengenfaktor bei recipe_ref.'],
                'price_value' => ['type' => 'number', 'description' => 'Preis bei type=header_preis.'],
                'price_basis' => ['type' => 'string', 'enum' => FoodAlchemistOfferBlock::PRICE_BASES, 'description' => 'Basis für price_value (header_preis).'],
                'visible' => ['type' => 'boolean', 'default' => true],
                'level' => ['type' => 'integer', 'description' => 'Einrück-Ebene 0..2.'],
                'height' => ['type' => 'integer', 'description' => 'Höhe bei type=spacer.'],
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
        $chapterId = (int) ($arguments['chapter_id'] ?? 0);
        if (($guard = $this->guardOwned($team, FoodAlchemistOfferChapter::class, $chapterId, 'Kapitel')) !== null) {
            return $guard;
        }

        $daten = array_intersect_key($arguments, array_flip([
            'type', 'label', 'wording', 'customer_text', 'interne_bemerkung',
            'concept_id', 'sales_recipe_id', 'quantity', 'price_value', 'price_basis', 'visible', 'level', 'height',
        ]));

        try {
            $block = app(OfferCompositionService::class)->addBlock($team, $chapterId, $daten);
        } catch (ModelNotFoundException) {
            return ToolResult::error('Kapitel nicht sichtbar/vorhanden.', 'NOT_FOUND');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['block' => [
            'id' => (int) $block->id,
            'type' => $block->type,
            'position' => (int) $block->position,
            'label' => $block->label,
            'concept_id' => $block->concept_id !== null ? (int) $block->concept_id : null,
            'sales_recipe_id' => $block->sales_recipe_id !== null ? (int) $block->sales_recipe_id : null,
        ]]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'angebot', 'composer', 'block', 'gericht', 'konzept', 'anlegen'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true,
            'side_effects' => ['creates'], 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.concepts.SEARCH', 'foodalchemist.verkaufsrezepte.SEARCH', 'foodalchemist.offer_chapter.POST'],
            'examples' => ['Füge Kapitel 12 das Konzept 7 als concept_ref-Block hinzu'],
        ];
    }
}
