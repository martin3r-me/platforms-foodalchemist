<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\SupplierService;

/**
 * R9.1 (write): Beziehungs-Ebene eines Lieferanten pflegen — Status, Konditionen,
 * neuer Ansprechpartner. Nur team-EIGENE Lieferanten (D1; geerbte Katalog-Lieferanten
 * sind read-only). Stammdaten-Pflege läuft weiter über den bestehenden Editor.
 */
class SuppliersPutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.suppliers.PUT';
    }

    public function getDescription(): string
    {
        return 'Pflegt die kommerzielle Beziehungs-Ebene eines Lieferanten: status '
            . '(aktiv|zweitquelle|gesperrt), Konditionen (rebate_pct, payment_term_days, '
            . 'min_order_value, free_shipping_threshold) und optional einen neuen Ansprechpartner '
            . '(contact: {name, role?, phone?, email?}). Nur eigene Lieferanten des aktuellen Teams.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'supplier_id' => ['type' => 'integer'],
                'status' => ['type' => 'string', 'enum' => ['aktiv', 'zweitquelle', 'gesperrt']],
                'rebate_pct' => ['type' => 'number'],
                'payment_term_days' => ['type' => 'integer'],
                'min_order_value' => ['type' => 'number'],
                'free_shipping_threshold' => ['type' => 'number'],
                'contact' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string'],
                        'role' => ['type' => 'string'],
                        'phone' => ['type' => 'string'],
                        'email' => ['type' => 'string'],
                    ],
                    'required' => ['name'],
                ],
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
        $svc = app(SupplierService::class);
        $id = (int) $arguments['supplier_id'];
        $geaendert = [];

        try {
            if (isset($arguments['status'])) {
                $svc->setStatus($team, $id, (string) $arguments['status']);
                $geaendert[] = 'status';
            }
            $konditionen = array_intersect_key($arguments, array_flip(['rebate_pct', 'payment_term_days', 'min_order_value', 'free_shipping_threshold']));
            if ($konditionen !== []) {
                $svc->updateConditions($team, $id, $konditionen);
                $geaendert[] = 'konditionen';
            }
            if (isset($arguments['contact']) && is_array($arguments['contact'])) {
                $svc->addContact($team, $id, $arguments['contact']);
                $geaendert[] = 'kontakt';
            }
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'ACCESS_DENIED');
        }

        if ($geaendert === []) {
            return ToolResult::error('Nichts zu ändern (status/Konditionen/contact angeben).', 'NO_CHANGE');
        }

        return ToolResult::success(['supplier_id' => $id, 'geaendert' => $geaendert, 'stammblatt' => $svc->stammblatt($team, $id)]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'command',
            'tags' => ['foodalchemist', 'lieferant', 'supplier', 'status', 'konditionen', 'kontakt'],
            'read_only' => false,
            'idempotent' => false,
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.suppliers.GET', 'foodalchemist.supplier_agreements.POST'],
            'examples' => ['Setz Lieferant 42 auf Zweitquelle und Zahlungsziel 30 Tage.'],
        ];
    }
}
