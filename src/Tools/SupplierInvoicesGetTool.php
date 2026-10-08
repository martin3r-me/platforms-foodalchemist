<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\LieferantenRechnungService;

/** Spec 75b · Lieferanten-Rechnungen lesen. */
class SupplierInvoicesGetTool extends WareneingangTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.supplier_invoices.GET';
    }

    public function getDescription(): string
    {
        return 'Rechnungen lesen. id = eine Rechnung mit Triple-Match-Befund je Position (bestellt, geliefert, berechnet, Preis Bestellung/Rechnung, '
            .'Befund ok|menge|preis|nicht_geliefert, Δ €), Summe vs. Beleg, freigebbar. ansicht=vorschlag + supplier_id = noch nicht abgerechnete '
            .'Lieferschein-Positionen (Grundlage für POST). Sonst Liste mit Filtern supplier_id, status (erfasst|freigegeben|bezahlt|storniert), suche.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'id' => ['type' => 'integer'], 'ansicht' => ['type' => 'string', 'enum' => ['liste', 'vorschlag']],
            'supplier_id' => ['type' => 'integer'], 'delivery_note_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
            'status' => ['type' => 'string'], 'suche' => ['type' => 'string'],
        ]];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->ausfuehren($context, function ($team, $userId) use ($arguments) {
            $svc = app(LieferantenRechnungService::class);
            if (! empty($arguments['id'])) {
                return ['rechnung' => $svc->detail($team, (int) $arguments['id'])];
            }
            if (($arguments['ansicht'] ?? '') === 'vorschlag') {
                if (empty($arguments['supplier_id'])) {
                    throw new \RuntimeException('Für den Vorschlag bitte supplier_id angeben.');
                }

                return ['positionen' => $svc->vorbelegen($team, (int) $arguments['supplier_id'], isset($arguments['delivery_note_ids']) ? array_map('intval', (array) $arguments['delivery_note_ids']) : null)];
            }

            return ['rechnungen' => $svc->liste($team, $arguments)];
        });
    }

    public function getMetadata(): array
    {
        return $this->metadaten(false, ['rechnung', 'read'], ['Welche Rechnungen sind noch in Prüfung?'], ['foodalchemist.supplier_invoices.POST', 'foodalchemist.triple_match.GET']);
    }
}
