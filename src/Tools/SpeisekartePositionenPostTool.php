<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\SpeisekarteService;

/** Position (Gericht/Fix-Menü/Header/Text) in eine Rubrik einer Speisekarte einfügen. */
class SpeisekartePositionenPostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.speisekarte_positionen.POST';
    }

    public function getDescription(): string
    {
        return 'Fügt eine Position in eine Rubrik ein. type=gericht_ref braucht sales_recipe_id (VK-Gericht/Getränk), '
            . 'type=menue_ref braucht concept_id (Fix-Menü). Preis kommt automatisch aus Darreichung/Concept; '
            . 'price_mode=manuell + price_value übersteuert. header/text/spacer für Layout.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'rubrik_id' => ['type' => 'integer'],
                'type' => ['type' => 'string', 'enum' => ['gericht_ref', 'menue_ref', 'header', 'text', 'spacer'], 'default' => 'gericht_ref'],
                'sales_recipe_id' => ['type' => 'integer', 'description' => 'Pflicht bei gericht_ref'],
                'concept_id' => ['type' => 'integer', 'description' => 'Pflicht bei menue_ref'],
                'wording' => ['type' => 'string', 'description' => 'Anzeige-Name-Override (optional)'],
                'consumer_text' => ['type' => 'string'],
                'price_mode' => ['type' => 'string', 'enum' => ['auto', 'manuell'], 'default' => 'auto'],
                'price_value' => ['type' => 'number', 'description' => 'Netto-VK bei price_mode=manuell'],
            ],
            'required' => ['rubrik_id', 'type'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        try {
            $svc = app(SpeisekarteService::class);
            $pos = $svc->addPosition($team, (int) $arguments['rubrik_id'], [
                'type' => (string) $arguments['type'],
                'sales_recipe_id' => isset($arguments['sales_recipe_id']) ? (int) $arguments['sales_recipe_id'] : null,
                'concept_id' => isset($arguments['concept_id']) ? (int) $arguments['concept_id'] : null,
                'wording' => $arguments['wording'] ?? null,
                'consumer_text' => $arguments['consumer_text'] ?? null,
                'price_mode' => $arguments['price_mode'] ?? 'auto',
                'price_value' => $arguments['price_value'] ?? null,
            ]);
            $preis = $svc->positionPreis($pos);
        } catch (\Throwable $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success([
            'position' => [
                'id' => $pos->id, 'type' => $pos->type, 'position' => $pos->position,
                'vk_netto' => $preis['vk'], 'preis_quelle' => $preis['quelle'],
            ],
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'speisekarte', 'position', 'gericht', 'menue', 'anlegen'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true,
            'side_effects' => ['creates'], 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.speisekarte_rubrik.POST', 'foodalchemist.speisekarte_positionen.DELETE'],
            'examples' => ['Füge Gericht 42 in Rubrik 7 ein'],
        ];
    }
}
