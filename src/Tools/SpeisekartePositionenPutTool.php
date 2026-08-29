<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\SpeisekarteService;

/** MCP-Steuerbarkeit · D8: eine Speisekarten-Position bearbeiten (Wording/Preis/Sichtbarkeit/Gericht). */
class SpeisekartePositionenPutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    private const FELDER = ['level', 'visible', 'label', 'consumer_text', 'interne_bemerkung', 'variant_group_id',
        'sales_recipe_id', 'concept_id', 'wording', 'price_mode', 'price_value'];

    public function getName(): string
    {
        return 'foodalchemist.speisekarte_positionen.PUT';
    }

    public function getDescription(): string
    {
        return 'Bearbeitet eine Position einer team-eigenen Speisekarte (felder: label, consumer_text, wording, '
            . 'price_mode, price_value, visible, level, sales_recipe_id, concept_id). Preis-Wahrheit bleibt im Service.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'position_id' => ['type' => 'integer', 'description' => 'Positions-Id.'],
                'felder' => ['type' => 'object', 'description' => 'Zu ändernde Felder (Allow-List).'],
            ],
            'required' => ['position_id', 'felder'],
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
        $positionId = (int) ($arguments['position_id'] ?? 0);
        if (($guard = $this->guardSpeisekartePositionOwned($team, $positionId)) !== null) {
            return $guard;
        }

        try {
            app(SpeisekarteService::class)->updatePosition($team, $positionId, $in);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['position_id' => $positionId, 'updated' => array_keys($in)]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'speisekarte', 'position', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.speisekarte_positionen.POST', 'foodalchemist.speisekarte_positionen.DELETE'],
            'examples' => ['Ändere bei Position 30 den Kundentext.'],
        ];
    }
}
