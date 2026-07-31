<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Services\PurchaseJournalService;

/**
 * Einkauf E2/E5 (read): echtes Spend aus dem Einkaufsjournal (Ist-Einkäufe, netto) —
 * gesamt + je Lieferant. Ersetzt für gepflegte Journale den bisherigen Nutzungs-Proxy
 * und ist die Grundlage der erreichten Rückvergütungs-Stufe. Optional von/bis-Zeitfenster.
 * team-scoped, read-only.
 */
class EinkaufSpendGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.einkauf_spend.GET';
    }

    public function getDescription(): string
    {
        return 'Echtes Einkaufs-Spend (netto) aus dem Journal: Gesamtsumme + Aufschlüsselung je Lieferant '
            . '(absteigend), optional im Zeitfenster von/bis (YYYY-MM-DD). Quelle: tatsächliche Ist-Einkäufe '
            . '(gelieferte FA-Bestellungen + Necta-Import) — nicht der Nutzungs-Proxy.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
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
        $von = ($arguments['von'] ?? '') !== '' ? (string) $arguments['von'] : null;
        $bis = ($arguments['bis'] ?? '') !== '' ? (string) $arguments['bis'] : null;

        $svc = app(PurchaseJournalService::class);
        $proLieferant = $svc->spendProLieferant($team, $von, $bis);
        $namen = FoodAlchemistSupplier::whereIn('id', array_keys($proLieferant))->pluck('name', 'id');

        $rows = [];
        foreach ($proLieferant as $sid => $spend) {
            $rows[] = ['supplier_id' => (int) $sid, 'supplier' => $namen[$sid] ?? ('#' . $sid), 'spend' => round($spend, 2)];
        }
        usort($rows, fn ($a, $b) => $b['spend'] <=> $a['spend']);

        return ToolResult::success([
            'spend_total' => round($svc->spend($team, null, $von, $bis), 2),
            'je_lieferant' => $rows,
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'einkauf', 'spend', 'umsatz', 'lieferant', 'journal'],
            'read_only' => true,
            'idempotent' => true,
            'risk_level' => 'read',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.einkauf_optimierung.GET', 'foodalchemist.supplier_rebate.GET'],
            'examples' => ['Wie viel haben wir dieses Jahr je Lieferant tatsächlich eingekauft?'],
        ];
    }
}
