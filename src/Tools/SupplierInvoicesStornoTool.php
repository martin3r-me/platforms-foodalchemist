<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\LieferantenRechnungService;

/** Spec 75b · Rechnung löschen (in Prüfung) oder stornieren (freigegeben). */
class SupplierInvoicesStornoTool extends WareneingangTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.supplier_invoices.STORNO';
    }

    public function getDescription(): string
    {
        return 'In Prüfung: löscht die Rechnung (ab Kuratieren). Freigegeben: storniert sie (nur Rolle Freigeben) und rechnet die Prüfwerte an den '
            .'Bestellzeilen aus den verbleibenden Rechnungen neu. Bezahlte Rechnungen nicht.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'required' => ['id']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->ausfuehren($context, function ($team, $userId) use ($arguments) {
            $r = app(LieferantenRechnungService::class)->stornieren($team, (int) $arguments['id'], $userId);

            return $r === null ? ['geloescht' => true, 'id' => (int) $arguments['id']] : ['status' => $r->status, 'id' => (int) $r->id];
        });
    }

    public function getMetadata(): array
    {
        return $this->metadaten(true, ['rechnung', 'storno'], ['Die Rechnung 88123 war doppelt, bitte stornieren.'], ['foodalchemist.supplier_invoices.GET']);
    }
}
