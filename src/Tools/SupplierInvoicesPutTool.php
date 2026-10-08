<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\LieferantenRechnungService;

/** Spec 75b · Rechnung in Prüfung ändern, Abweichung begründen, strittig markieren. */
class SupplierInvoicesPutTool extends WareneingangTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.supplier_invoices.PUT';
    }

    public function getDescription(): string
    {
        return 'Ändert eine Rechnung in Prüfung (Rolle ab Kuratieren): Kopf, lines ersetzt alle Positionen. begruendung_line_id + begruendung akzeptiert '
            .'eine Abweichung bewusst (Voraussetzung für die Freigabe). strittig=true|false markiert die Rechnung.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'id' => ['type' => 'integer'], 'invoice_number' => ['type' => 'string'], 'invoice_date' => ['type' => 'string'],
            'total_net' => ['type' => ['number', 'string']], 'note' => ['type' => 'string'], 'lines' => ['type' => 'array', 'description' => 'Positionen. Ware: {delivery_note_line_id?, order_line_id?, qty_packs, pack_price, begruendung?}. Nebenkosten: {art: fracht|pfand|zuschlag|rabatt, designation?, line_net}.', 'items' => ['type' => 'object']],
            'begruendung_line_id' => ['type' => 'integer'], 'begruendung' => ['type' => 'string'], 'strittig' => ['type' => 'boolean'],
        ], 'required' => ['id']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->ausfuehren($context, function ($team, $userId) use ($arguments) {
            $svc = app(LieferantenRechnungService::class);
            $id = (int) $arguments['id'];
            $kopf = array_intersect_key($arguments, array_flip(['invoice_number', 'invoice_date', 'total_net', 'note', 'lines']));
            if ($kopf !== []) {
                $svc->speichern($team, $kopf, $userId, $id);
            }
            if (! empty($arguments['begruendung_line_id'])) {
                $svc->begruenden($team, (int) $arguments['begruendung_line_id'], $arguments['begruendung'] ?? null, $userId);
            }
            if (array_key_exists('strittig', $arguments)) {
                $svc->strittig($team, $id, (bool) $arguments['strittig'], $userId);
            }

            return ['rechnung' => $svc->detail($team, $id)];
        });
    }

    public function getMetadata(): array
    {
        return $this->metadaten(true, ['rechnung', 'write'], ['Bei RE 88123 ist der Mehlpreis ok, Preiserhöhung seit Oktober.'], ['foodalchemist.supplier_invoices.APPROVE']);
    }
}
