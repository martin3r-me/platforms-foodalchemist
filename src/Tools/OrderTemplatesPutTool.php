<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\OrderTemplateService;

/** Spec 68 · Bestellvorlage ändern: Kopf, Positionen setzen/ändern/entfernen. */
class OrderTemplatesPutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.order_templates.PUT';
    }

    public function getDescription(): string
    {
        return 'Ändert eine Bestellvorlage: name/notiz/bestelltag; positionen_setzen [{type, id, menge, einheit}] (gleiche Quelle '
            . 'wird überschrieben, neue angehängt); positionen_aendern [{line_id, menge?, einheit?}]; positionen_entfernen [line_id].';
    }

    public function getSchema(): array
    {
        $pos = ['type' => 'object', 'properties' => ['type' => ['type' => 'string'], 'id' => ['type' => 'integer'], 'menge' => ['type' => ['number', 'string']], 'einheit' => ['type' => 'string']]];

        return [
            'type' => 'object',
            'properties' => [
                'template_id' => ['type' => 'integer'],
                'name' => ['type' => 'string'], 'notiz' => ['type' => ['string', 'null']], 'bestelltag' => ['type' => ['integer', 'null']],
                'positionen_setzen' => ['type' => 'array', 'items' => $pos],
                'positionen_aendern' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['line_id' => ['type' => 'integer'], 'menge' => ['type' => ['number', 'string']], 'einheit' => ['type' => 'string']]]],
                'positionen_entfernen' => ['type' => 'array', 'items' => ['type' => 'integer']],
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
        $svc = app(OrderTemplateService::class);
        $id = (int) ($arguments['template_id'] ?? 0);
        try {
            $svc->detail($team, $id);
            $kopf = [];
            foreach (['name' => 'name', 'notiz' => 'note', 'bestelltag' => 'weekday'] as $in => $feld) {
                if (array_key_exists($in, $arguments)) {
                    $kopf[$feld] = $arguments[$in];
                }
            }
            if ($kopf !== []) {
                $svc->aendern($team, $id, $kopf);
            }
            foreach ((array) ($arguments['positionen_setzen'] ?? []) as $p) {
                $svc->positionSetzen($team, $id, (string) ($p['type'] ?? ''), (int) ($p['id'] ?? 0), $p['menge'] ?? 1, $p['einheit'] ?? null);
            }
            foreach ((array) ($arguments['positionen_aendern'] ?? []) as $p) {
                $this->eigeneZeile($team, $id, (int) ($p['line_id'] ?? 0));
                $svc->positionAendern($team, (int) $p['line_id'], $p['menge'] ?? null, $p['einheit'] ?? null);
            }
            foreach ((array) ($arguments['positionen_entfernen'] ?? []) as $lineId) {
                $this->eigeneZeile($team, $id, (int) $lineId);
                $svc->positionEntfernen($team, (int) $lineId);
            }
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return ToolResult::error('Vorlage oder Position nicht gefunden.', 'NOT_FOUND');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['template_id' => $id, 'positionen' => $svc->detail($team, $id)->lines->count()]);
    }

    private function eigeneZeile($team, int $templateId, int $lineId): void
    {
        \Platform\FoodAlchemist\Models\FoodAlchemistOrderTemplateLine::where('team_id', $team->id)
            ->where('order_template_id', $templateId)->findOrFail($lineId);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'einkauf', 'bestellvorlage', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.order_templates.GET'],
            'examples' => ['Setz in der Vorlage Montag Molkerei die Butter auf 12 kg.'],
        ];
    }
}
