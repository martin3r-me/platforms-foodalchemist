<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\OrderTemplateService;

/** Spec 68 · Bestellvorlage anlegen — leer, mit Positionen oder aus einer Bestellung. */
class OrderTemplatesPostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.order_templates.POST';
    }

    public function getDescription(): string
    {
        return 'Legt eine Bestellvorlage an. Positionen: [{type, id, menge, einheit}] mit type gp (einheit kg|g|stk — Artikel '
            . 'wählt beim Bestellen die Lead-Strategie), recipe (einheit portions|ansaetze|kg — Bedarf aus der Rezeptur, '
            . '„Musterproduktion") oder supplier_item (einheit gebinde — fester Artikel). Alternativ from_order_id: aus einer '
            . 'Bestellung (Zeilen mit Grundprodukt → GP-Position, sonst fester Artikel).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
                'notiz' => ['type' => 'string'],
                'bestelltag' => ['type' => 'integer', 'description' => '1 = Montag … 7 = Sonntag'],
                'from_order_id' => ['type' => 'integer'],
                'positionen' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'type' => ['type' => 'string', 'enum' => ['gp', 'recipe', 'supplier_item']],
                    'id' => ['type' => 'integer'], 'menge' => ['type' => ['number', 'string']], 'einheit' => ['type' => 'string'],
                ], 'required' => ['type', 'id']]],
            ],
            'required' => ['name'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $svc = app(OrderTemplateService::class);
        $uid = $context->user?->id;
        try {
            if (! empty($arguments['from_order_id'])) {
                $v = $svc->ausBestellung($team, (int) $arguments['from_order_id'], (string) $arguments['name'], $uid);
            } elseif (! empty($arguments['positionen'])) {
                $v = $svc->ausQuellen($team, (string) $arguments['name'], array_map(fn ($p) => ['type' => $p['type'] ?? '', 'id' => (int) ($p['id'] ?? 0),
                    'qty' => $p['menge'] ?? 1, 'unit' => $p['einheit'] ?? null], (array) $arguments['positionen']), $uid);
            } else {
                $v = $svc->anlegen($team, ['name' => $arguments['name']], $uid);
            }
            if (array_key_exists('notiz', $arguments) || array_key_exists('bestelltag', $arguments)) {
                $v = $svc->aendern($team, $v->id, array_filter(['note' => $arguments['notiz'] ?? null, 'weekday' => $arguments['bestelltag'] ?? null], fn ($x) => $x !== null));
            }
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return ToolResult::error('Bestellung nicht gefunden.', 'NOT_FOUND');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['template_id' => $v->id, 'name' => $v->name, 'positionen' => $v->lines()->count()]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'einkauf', 'bestellvorlage', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['creates'],
            'related_tools' => ['foodalchemist.order_templates.PUT', 'foodalchemist.order_templates.APPLY'],
            'examples' => ['Leg eine Vorlage „Montag Molkerei" mit 10 kg Butter und 20 l Sahne an.', 'Speicher Bestellung 42 als Vorlage.'],
        ];
    }
}
