<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\RebateService;

/**
 * Einkauf E1 (read): Rückvergütungs-Staffel + effektiver Prozentsatz eines Teams für einen
 * Lieferanten. Zeigt Stufen (Schwelle ab € → %), die greifende Quelle (manuell/auto/flat),
 * die angenommene Stufe und optional den effektiven Satz für eine Warengruppe bzw. einen
 * Was-wäre-wenn-Umsatz. team-scoped (kein Kunden-Scoping — eigene Session).
 */
class SupplierRebateGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.supplier_rebate.GET';
    }

    public function getDescription(): string
    {
        return 'Liest die Rückvergütungs-Staffel eines Lieferanten (supplier_id) für das aktuelle Team: '
            . 'Stufen (threshold_eur → percent), effektiver Prozentsatz, Quelle (manuell|auto_umsatz|flat_legacy), '
            . 'angenommene Stufe. Optional commodity_group (ausgeschlossene Warengruppe → 0 %) und '
            . 'revenue (Was-wäre-wenn: welcher Satz bei diesem Jahresumsatz). Rückvergütung ist ein '
            . 'rückwirkender Jahresbonus (effektiver Netto-Preis fürs Vergleichen), kein Zeilen-Rabatt. '
            . 'Konditionen werden vom Eltern-Team geerbt: geerbt=true + quelle_team_id sagen, '
            . 'dass die gezeigte Staffel einem übergeordneten Team gehört. Eine eigene Staffel '
            . '(supplier_rebate.PUT) überschreibt die geerbte vollständig.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'supplier_id' => ['type' => 'integer'],
                'commodity_group' => ['type' => 'string', 'description' => 'optional: Warengruppen-Code; ausgeschlossene → 0 %'],
                'revenue' => ['type' => 'number', 'description' => 'optional: Was-wäre-wenn-Jahresumsatz €'],
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
        $commodityGroup = ($arguments['commodity_group'] ?? '') !== '' ? (string) $arguments['commodity_group'] : null;
        $revenue = array_key_exists('revenue', $arguments) && $arguments['revenue'] !== null && $arguments['revenue'] !== ''
            ? (float) $arguments['revenue'] : null;

        $info = $rebate->stufenInfo($team, $supplierId, $revenue);
        $info['effektiv_prozent_fuer_wg'] = $rebate->effektiverProzent($team, $supplierId, $commodityGroup, $revenue);
        $info['commodity_group'] = $commodityGroup;

        return ToolResult::success($info);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'lieferant', 'supplier', 'rueckverguetung', 'rebate', 'staffel', 'einkauf', 'preis'],
            'read_only' => true,
            'idempotent' => true,
            'risk_level' => 'read',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.supplier_rebate.PUT', 'foodalchemist.suppliers.GET'],
            'examples' => ['Welche Rückvergütung greift bei Lieferant 126, und was wäre der Satz bei 2,5 Mio € Umsatz?'],
        ];
    }
}
