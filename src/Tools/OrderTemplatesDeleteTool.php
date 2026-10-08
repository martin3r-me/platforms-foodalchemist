<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\OrderTemplateService;

/** Spec 68 · Bestellvorlage löschen (bestehende Bestellungen bleiben unberührt). */
class OrderTemplatesDeleteTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.order_templates.DELETE';
    }

    public function getDescription(): string
    {
        return 'Löscht eine Bestellvorlage samt Positionen. Daraus angelegte Bestellungen bleiben unberührt.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => ['template_id' => ['type' => 'integer']], 'required' => ['template_id']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        try {
            app(OrderTemplateService::class)->loeschen($team, (int) ($arguments['template_id'] ?? 0));
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return ToolResult::error('Vorlage nicht gefunden.', 'NOT_FOUND');
        }

        return ToolResult::success(['template_id' => (int) $arguments['template_id'], 'geloescht' => true]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'einkauf', 'bestellvorlage', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['deletes'],
            'related_tools' => ['foodalchemist.order_templates.GET'],
            'examples' => ['Lösch die Vorlage „Sommerfest 2025".'],
        ];
    }
}
