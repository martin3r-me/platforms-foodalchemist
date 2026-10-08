<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\LieferantenRechnungService;

/** Spec 75b · Rechnung freigeben (Rolle Freigeben). */
class SupplierInvoicesApproveTool extends WareneingangTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.supplier_invoices.APPROVE';
    }

    public function getDescription(): string
    {
        return 'Gibt eine Rechnung frei (nur Rolle Freigeben). Voraussetzung: Summe der Positionen = Summe laut Beleg und jede Abweichung im Triple Match '
            .'begründet. Schreibt danach Menge und gewichteten Preis als Rechnungsprüfung an die Bestellzeilen und den Rechnungskopf an die Bestellungen. '
            .'Fehlt die Rolle: FORBIDDEN.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'required' => ['id']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->ausfuehren($context, function ($team, $userId) use ($arguments) {
            $svc = app(LieferantenRechnungService::class);
            $svc->freigeben($team, (int) $arguments['id'], $userId);

            return ['rechnung' => $svc->detail($team, (int) $arguments['id'])];
        });
    }

    public function getMetadata(): array
    {
        return $this->metadaten(true, ['rechnung', 'freigabe'], ['Gib die Rechnung 88123 frei.'], ['foodalchemist.supplier_invoices.PAY']);
    }
}
