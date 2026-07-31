<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\RebateService;

/**
 * Einkauf E1 (write): Rückvergütungs-Staffel + Konfiguration eines Teams für einen
 * Lieferanten setzen. `tiers` ersetzt die komplette Staffel (Replace-Set); die
 * Konfig-Keys (active, selected_tier_id, assumed_annual_revenue, excluded_commodity_groups)
 * werden geupsertet. team-scopes Overlay über dem globalen Lieferanten (nur team-sichtbar
 * beschreibbar). Kein Kunden-Scoping (eigene Session).
 */
class SupplierRebatePutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.supplier_rebate.PUT';
    }

    public function getDescription(): string
    {
        return 'Setzt/aktualisiert die Rückvergütung eines Lieferanten (supplier_id) für das aktuelle Team. '
            . '`tiers` (Liste {threshold_eur, percent}) ERSETZT die komplette Staffel. Konfig-Keys optional: '
            . 'active (bool), selected_tier_id (manuell gewählte Stufe), assumed_annual_revenue (€ → Auto-Stufe), '
            . 'applies_to_all (Vollsortiment ja/nein), commodity_groups (§3-Warengruppen-Codes, für die der '
            . 'Bonus gilt, wenn NICHT Vollsortiment). Gibt die resultierende Staffel-Info zurück. Nur übergebene Felder wirken.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'supplier_id' => ['type' => 'integer'],
                'tiers' => [
                    'type' => 'array',
                    'description' => 'ERSETZT die Staffel. Leeres Array = Staffel leeren.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'threshold_eur' => ['type' => 'number'],
                            'percent' => ['type' => 'number'],
                        ],
                        'required' => ['threshold_eur', 'percent'],
                    ],
                ],
                'active' => ['type' => 'boolean'],
                'selected_tier_id' => ['type' => 'integer', 'description' => 'manuell gewählte Stufe (id einer Stufe dieses Lieferanten)'],
                'assumed_annual_revenue' => ['type' => 'number', 'description' => 'angenommener Jahresumsatz € → Auto-Stufe'],
                'applies_to_all' => ['type' => 'boolean', 'description' => 'Vollsortiment: Bonus gilt für alle Warengruppen (Default true)'],
                'commodity_groups' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => '§3-WG-Codes, für die der Bonus gilt (nur wenn applies_to_all=false)'],
            ],
            'required' => ['supplier_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $rebate = app(RebateService::class);
        $supplierId = (int) ($arguments['supplier_id'] ?? 0);

        try {
            if (array_key_exists('tiers', $arguments) && is_array($arguments['tiers'])) {
                $rebate->saveTiers($team, $supplierId, $arguments['tiers']);
            }

            $configKeys = ['active', 'selected_tier_id', 'assumed_annual_revenue', 'applies_to_all', 'commodity_groups'];
            $configInput = array_intersect_key($arguments, array_flip($configKeys));
            if ($configInput !== []) {
                $rebate->saveConfig($team, $supplierId, $configInput);
            }
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success($rebate->stufenInfo($team, $supplierId));
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'command',
            'tags' => ['foodalchemist', 'lieferant', 'supplier', 'rueckverguetung', 'rebate', 'staffel', 'einkauf'],
            'read_only' => false,
            'idempotent' => false,
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.supplier_rebate.GET', 'foodalchemist.suppliers.GET'],
            'examples' => ['Setze für Lieferant 126 die Staffel: ab 1,75 Mio 10 %, ab 3,5 Mio 11,75 %, angenommene Stufe die höchste.'],
        ];
    }
}
