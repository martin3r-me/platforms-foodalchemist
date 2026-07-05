<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\SimulationService;

/**
 * R2.2 — Was-wäre-wenn-Preissimulation. Hypothetisches Szenario (Warengruppe |
 * Einzelartikel | GP, ± X %) → Portfolio-Antwort: Marge-Delta gesamt + Top-20
 * betroffene Gerichte + Ersatzvorschläge. REIN LESEND (verändert keine Echtdaten),
 * trotz POST-Verb — read_only in Metadata gesetzt.
 */
class SimulationPostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.simulation.POST';
    }

    public function getDescription(): string
    {
        return 'Was-wäre-wenn-Preissimulation (read-only): scope=warengruppe|artikel|gp + ref '
            . '(WG-Code | supplier_item_id | gp_id) + delta_pct (z. B. 20 = +20 %, -10 = −10 %). '
            . 'Liefert Marge-Delta über das Portfolio, Top-20 betroffene Gerichte und Ersatzvorschläge. '
            . 'Verändert NICHTS.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'scope' => ['type' => 'string', 'enum' => ['warengruppe', 'artikel', 'gp'], 'description' => 'Bezugsebene des Szenarios'],
                'ref' => ['type' => 'string', 'description' => 'Warengruppen-Code | supplier_item_id | gp_id (je nach scope)'],
                'delta_pct' => ['type' => 'number', 'description' => 'relative Preisänderung in % (z. B. 20 oder -10)'],
            ],
            'required' => ['scope', 'ref', 'delta_pct'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $scope = (string) ($arguments['scope'] ?? '');
        $ref = isset($arguments['ref']) ? (string) $arguments['ref'] : '';
        if (! in_array($scope, ['warengruppe', 'artikel', 'gp'], true) || $ref === '' || ! isset($arguments['delta_pct'])) {
            return ToolResult::error('scope (warengruppe|artikel|gp), ref und delta_pct sind Pflicht.', 'VALIDATION_ERROR');
        }
        $delta = (float) $arguments['delta_pct'];
        if ($delta <= -100.0) {
            return ToolResult::error('delta_pct muss > -100 sein.', 'VALIDATION_ERROR');
        }

        try {
            $ergebnis = app(SimulationService::class)->simuliere($team, $scope, $ref, $delta);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'EXECUTION_ERROR');
        }

        return ToolResult::success($ergebnis);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'simulation', 'was-waere-wenn', 'preis', 'marge', 'price', 'scenario'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.kalkulation.GET', 'foodalchemist.signale.SEARCH'],
            'examples' => [
                'Was passiert mit der Marge, wenn Butter (gp 8056) +20 % teurer wird?',
                'Simuliere Warengruppe 01 -10 %',
                'Artikel 12345 +15 % — welche Gerichte trifft es am härtesten?',
            ],
        ];
    }
}
