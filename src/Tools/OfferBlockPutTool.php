<?php

namespace Platform\FoodAlchemist\Tools;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistOfferBlock;
use Platform\FoodAlchemist\Services\OfferCompositionService;

/**
 * #380 Composer · MCP-Lockstep: einen Angebot-Block bearbeiten (Label/Wording/Text/Menge/Preis/
 * Sichtbarkeit/Ebene). Spiegelt FoodbookBlocksPutTool (felder-Allow-List); Struktur-/Preis-Wahrheit
 * bleibt im {@see OfferCompositionService::updateBlock} (recipe_ref-Prüfung inklusive).
 */
class OfferBlockPutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    /** Allow-List (Teilmenge von OfferCompositionService::BLOCK_FELDER; der Service intersect't final). */
    private const FELDER = ['type', 'level', 'visible', 'label', 'wording', 'customer_text', 'interne_bemerkung',
        'concept_id', 'sales_recipe_id', 'quantity', 'price_value', 'price_basis', 'height'];

    public function getName(): string
    {
        return 'foodalchemist.offer_block.PUT';
    }

    public function getDescription(): string
    {
        return 'Bearbeitet einen Block eines team-eigenen Angebots (felder: label, wording, customer_text, quantity, '
            . 'price_value, price_basis, visible, level, sales_recipe_id). Nur bekannte Felder werden übernommen.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'block_id' => ['type' => 'integer', 'description' => 'Block-Id.'],
                'felder' => ['type' => 'object', 'description' => 'Zu ändernde Felder (Allow-List).'],
            ],
            'required' => ['block_id', 'felder'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $felder = $arguments['felder'] ?? null;
        if (! is_array($felder) || $felder === []) {
            return ToolResult::error('felder muss ein nicht-leeres Objekt sein.', 'VALIDATION_ERROR');
        }
        $in = array_intersect_key($felder, array_flip(self::FELDER));
        if ($in === []) {
            return ToolResult::error('Keine bekannten Felder in felder.', 'VALIDATION_ERROR');
        }
        $blockId = (int) ($arguments['block_id'] ?? 0);
        if (($guard = $this->guardOwned($team, FoodAlchemistOfferBlock::class, $blockId, 'Block')) !== null) {
            return $guard;
        }

        try {
            app(OfferCompositionService::class)->updateBlock($team, $blockId, $in);
        } catch (ModelNotFoundException) {
            return ToolResult::error('Block nicht sichtbar/vorhanden.', 'NOT_FOUND');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['block_id' => $blockId, 'updated' => array_keys($in)]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'angebot', 'composer', 'block', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.offer_block.POST', 'foodalchemist.offer_block.DELETE'],
            'examples' => ['Ändere bei Block 30 das Kundenwording.'],
        ];
    }
}
