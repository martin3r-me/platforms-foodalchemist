<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\LieferantenRechnungService;

/** Spec 75b · Rechnung als bezahlt markieren (Rolle Freigeben). */
class SupplierInvoicesPayTool extends WareneingangTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.supplier_invoices.PAY';
    }

    public function getDescription(): string
    {
        return 'Markiert eine freigegebene Rechnung als bezahlt (nur Rolle Freigeben), setzt den Zahlungsstatus der betroffenen Bestellungen auf bezahlt.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'paid_at' => ['type' => 'string', 'description' => 'YYYY-MM-DD, Default heute.']], 'required' => ['id']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->ausfuehren($context, function ($team, $userId) use ($arguments) {
            $svc = app(LieferantenRechnungService::class);
            $svc->bezahlt($team, (int) $arguments['id'], $arguments['paid_at'] ?? null, $userId);

            return ['rechnung' => $svc->detail($team, (int) $arguments['id'])];
        });
    }

    public function getMetadata(): array
    {
        return $this->metadaten(true, ['rechnung', 'zahlung'], ['Rechnung 88123 ist am 20.10. bezahlt.'], ['foodalchemist.supplier_invoices.GET']);
    }
}
