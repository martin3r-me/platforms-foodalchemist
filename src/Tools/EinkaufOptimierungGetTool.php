<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\EinkaufOptimizerService;

/**
 * Einkauf E4/E5 (read): Wareneinsatz-Optimierung — Ist-Wareneinsatz aus dem Journal
 * gegenüber dem optimalen Bezug (günstigster Lieferant) als Listenpreis UND inkl.
 * Rückvergütung, plus die größten Einsparpotenziale. `exclude_supplier_ids` klammert
 * Lieferanten aus (Was-wäre-wenn). team-scoped, read-only.
 */
class EinkaufOptimierungGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.einkauf_optimierung.GET';
    }

    public function getDescription(): string
    {
        return 'Wareneinsatz-Optimierung aus dem Einkaufsjournal: Ist-Kosten vs. optimaler Bezug '
            . '(günstigster Lieferant) — als Listenpreis UND inkl. Rückvergütung — plus Top-Einsparpotenziale '
            . 'je Grundprodukt. Optional exclude_supplier_ids (Lieferanten ausklammern, Was-wäre-wenn) und '
            . 'von/bis (YYYY-MM-DD, Liefer-Zeitfenster). Braucht Journal-Daten (gelieferte FA-Bestellungen '
            . 'bzw. Necta-Import) — sonst leere Summen.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'exclude_supplier_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                'von' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                'bis' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $exclude = is_array($arguments['exclude_supplier_ids'] ?? null)
            ? array_map('intval', $arguments['exclude_supplier_ids']) : [];
        $von = ($arguments['von'] ?? '') !== '' ? (string) $arguments['von'] : null;
        $bis = ($arguments['bis'] ?? '') !== '' ? (string) $arguments['bis'] : null;

        return ToolResult::success(
            app(EinkaufOptimizerService::class)->optimieren($team, $exclude, $von, $bis)
        );
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'einkauf', 'optimierung', 'wareneinsatz', 'spend', 'rueckverguetung', 'preis'],
            'read_only' => true,
            'idempotent' => true,
            'risk_level' => 'read',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.einkauf_spend.GET', 'foodalchemist.einkauf_preisvergleich.GET'],
            'examples' => ['Wo verlieren wir am meisten Wareneinsatz — und was, wenn wir Lieferant 129 ausklammern?'],
        ];
    }
}
