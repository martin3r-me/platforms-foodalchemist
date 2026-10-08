<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\PurchaseJournalService;

/** Spec 66 §4 · Einkauf eines Grundprodukts über die Zeit (aus dem Einkaufsjournal). */
class GpEinkaufGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.gp_einkauf.GET';
    }

    public function getDescription(): string
    {
        return 'Wie viel wurde von einem Grundprodukt eingekauft? Liefert aus dem Einkaufsjournal des Teams Menge (kg/l/Stk) '
            . 'und € je Monat (Default 12 Monate, max. 36), je Lieferant (Menge, €, Ø-Preis, letzter Kauf) und die Summen.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'gp_id' => ['type' => 'integer', 'description' => 'Grundprodukt-Id.'],
                'monate' => ['type' => 'integer', 'description' => 'Zeitraum in Monaten (1–36, Default 12).'],
            ],
            'required' => ['gp_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $gp = FoodAlchemistGp::visibleToTeam($team)->find((int) ($arguments['gp_id'] ?? 0));
        if ($gp === null) {
            return ToolResult::error('Grundprodukt nicht gefunden.', 'NOT_FOUND');
        }

        return ToolResult::success(['gp_id' => $gp->id, 'name' => $gp->name]
            + app(PurchaseJournalService::class)->gpEinkauf($team, $gp->id, (int) ($arguments['monate'] ?? 12)));
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'einkauf', 'grundprodukt', 'journal'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => [],
            'related_tools' => ['foodalchemist.inventory.GET'],
            'examples' => ['Wie viel Butter haben wir dieses Jahr gekauft und bei wem?'],
        ];
    }
}
