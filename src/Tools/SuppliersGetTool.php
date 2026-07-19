<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\SupplierService;

/**
 * R9.1 (read): Lieferanten-Stammblatt — Stammdaten, Beziehungs-Status, Konditionen,
 * Ansprechpartner, Absprachen-Log, Vertrags-/Dokument-Fristen, WG-Abdeckung.
 */
class SuppliersGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.suppliers.GET';
    }

    public function getDescription(): string
    {
        return 'Liefert das vollständige Stammblatt eines Lieferanten: Stammdaten, Status '
            . '(aktiv/zweitquelle/gesperrt), Konditionen (Rückvergütung/Zahlungsziel/Mindestbestellwert/'
            . 'Frei-Haus), Ansprechpartner, Absprachen-Log, Vertrags-/Dokument-Fristen und WG-Abdeckung. Read-only.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'supplier_id' => ['type' => 'integer'],
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
        try {
            return ToolResult::success(app(SupplierService::class)->stammblatt($team, (int) $arguments['supplier_id']));
        } catch (\Throwable $e) {
            return ToolResult::error('Lieferant nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'lieferant', 'supplier', 'stammblatt', 'konditionen', 'vertrag'],
            'read_only' => true,
            'idempotent' => true,
            'risk_level' => 'safe',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.suppliers.PUT', 'foodalchemist.supplier_agreements.POST', 'foodalchemist.artikel.LIST'],
            'examples' => ['Zeig mir das Stammblatt von Lieferant 42.'],
        ];
    }
}
