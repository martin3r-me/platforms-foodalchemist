<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\OrderTemplateService;

/** Spec 68 · Bestellvorlage anwenden: Bestell-Entwürfe je Lieferant zum Liefertag (über die Bestellrunde). */
class OrderTemplatesApplyTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.order_templates.APPLY';
    }

    public function getDescription(): string
    {
        return 'Wendet eine Bestellvorlage an und legt Bestell-ENTWÜRFE je Lieferant zum liefertag an (nichts wird versendet). '
            . 'Artikel für Grundprodukte nach Lead-Strategie (optional strategie), Rezepte über die Rezeptur. mengen: '
            . '{line_id: menge} überschreibt einzelne Positionen (0 = auslassen). Erst mit order_templates.GET vorschau=true prüfen.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'template_id' => ['type' => 'integer'],
                'liefertag' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                'mengen' => ['type' => 'object', 'additionalProperties' => ['type' => ['number', 'string']]],
                'strategie' => ['type' => 'string', 'description' => 'Lead-Strategie (leer = Team-Standard).'],
            ],
            'required' => ['template_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $mengen = [];
        foreach ((array) ($arguments['mengen'] ?? []) as $k => $v) {
            $mengen[(int) $k] = $v;
        }
        try {
            $r = app(OrderTemplateService::class)->anwenden($team, (int) ($arguments['template_id'] ?? 0), $arguments['liefertag'] ?? null,
                $mengen, $arguments['strategie'] ?? null, $context->user?->id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return ToolResult::error('Vorlage nicht gefunden.', 'NOT_FOUND');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['bestellungen' => $r['orders'], 'offen' => $r['unresolved'], 'hinweise' => $r['warnings']]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'einkauf', 'bestellvorlage', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['creates', 'updates'],
            'related_tools' => ['foodalchemist.order_templates.GET', 'foodalchemist.orders.GET'],
            'examples' => ['Leg die Bestellung aus der Vorlage Montag Molkerei für den 12.10. an.'],
        ];
    }
}
