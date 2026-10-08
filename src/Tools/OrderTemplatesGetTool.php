<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\OrderTemplateService;

/** Spec 68 · Bestellvorlagen lesen (Liste oder eine Vorlage, optional mit Vorschau je Lieferant). */
class OrderTemplatesGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.order_templates.GET';
    }

    public function getDescription(): string
    {
        return 'Bestellvorlagen des Teams (gespeicherte Bestellrunden). Ohne template_id: Liste. Mit template_id: Positionen '
            . '(type gp|recipe|supplier_item, Menge, Einheit) und mit vorschau=true die Bestellvorschau je Lieferant '
            . '(Artikel nach Lead-Strategie, Gebinde, Preis) zum liefertag.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'template_id' => ['type' => 'integer'],
                'vorschau' => ['type' => 'boolean'],
                'liefertag' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $svc = app(OrderTemplateService::class);
        if (empty($arguments['template_id'])) {
            return ToolResult::success(['vorlagen' => $svc->liste($team)->map(fn ($v) => [
                'template_id' => $v->id, 'name' => $v->name, 'positionen' => $v->lines_count, 'bestelltag' => $v->weekday,
                'zuletzt_genutzt' => $v->last_used_at?->toDateString(),
            ])->values()->all()]);
        }
        try {
            $v = $svc->detail($team, (int) $arguments['template_id']);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return ToolResult::error('Vorlage nicht gefunden.', 'NOT_FOUND');
        }
        $out = ['template_id' => $v->id, 'name' => $v->name, 'notiz' => $v->note, 'bestelltag' => $v->weekday,
            'positionen' => $v->lines->map(fn ($l) => ['line_id' => $l->id, 'type' => $l->type, 'id' => $l->alsQuelle()['id'],
                'bezeichnung' => $l->bezeichnung(), 'menge' => (float) $l->qty, 'einheit' => $l->unit, 'notiz' => $l->note])->values()->all()];
        if (! empty($arguments['vorschau'])) {
            $p = $svc->vorschau($team, $v->id, $arguments['liefertag'] ?? null);
            $out['vorschau'] = ['lieferanten' => array_map(fn ($g) => [
                'lieferant' => $g['supplier'], 'liefertag' => $g['delivery_date'], 'summe' => $g['total_net'],
                'positionen' => array_map(fn ($x) => ['artikel' => $x['designation'] ?? null, 'gebinde' => $x['qty_packs'] ?? null, 'summe' => $x['line_total'] ?? null], $g['positionen']),
            ], $p['orders_preview']), 'offen' => $p['unresolved'], 'hinweise' => $p['warnings']];
        }

        return ToolResult::success($out);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'einkauf', 'bestellvorlage'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => [],
            'related_tools' => ['foodalchemist.order_templates.APPLY', 'foodalchemist.order_templates.POST'],
            'examples' => ['Welche Bestellvorlagen haben wir?', 'Was würde die Vorlage Montag Molkerei morgen kosten?'],
        ];
    }
}
