<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\LieferantenRechnungService;

/** Spec 75b · Rechnung erfassen. */
class SupplierInvoicesPostTool extends WareneingangTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.supplier_invoices.POST';
    }

    public function getDescription(): string
    {
        return 'Erfasst eine Lieferanten-Rechnung (Rolle ab Kuratieren), Status „in Prüfung". Ohne lines: aus allen gebuchten, noch nicht abgerechneten '
            .'Lieferscheinen des Lieferanten vorbelegt (Menge aus Lieferschein, Preis aus Bestellung) bzw. aus delivery_note_ids — Sammelrechnung. '
            .'total_net = Summe laut Beleg (Erfassungskontrolle). Freigabe danach mit supplier_invoices.APPROVE.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'supplier_id' => ['type' => 'integer'], 'invoice_number' => ['type' => 'string'], 'invoice_date' => ['type' => 'string'],
            'total_net' => ['type' => ['number', 'string']], 'note' => ['type' => 'string'],
            'delivery_note_ids' => ['type' => 'array', 'items' => ['type' => 'integer']], 'lines' => ['type' => 'array', 'description' => 'Positionen. Ware: {delivery_note_line_id?, order_line_id?, qty_packs, pack_price, begruendung?}. Nebenkosten: {art: fracht|pfand|zuschlag|rabatt, designation?, line_net}.', 'items' => ['type' => 'object']],
        ], 'required' => ['supplier_id']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->ausfuehren($context, function ($team, $userId) use ($arguments) {
            $svc = app(LieferantenRechnungService::class);
            $inv = $svc->speichern($team, array_intersect_key($arguments, array_flip(['supplier_id', 'invoice_number', 'invoice_date', 'total_net', 'note', 'delivery_note_ids', 'lines'])), $userId);

            return ['rechnung' => $svc->detail($team, (int) $inv->id)];
        });
    }

    public function getMetadata(): array
    {
        return $this->metadaten(true, ['rechnung', 'write'], ['Erfasse die Chefs-Rechnung 88123 über die Lieferscheine dieser Woche, 412,30 € netto.'], ['foodalchemist.supplier_invoices.APPROVE']);
    }
}
