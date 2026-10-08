<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\TripleMatchService;

/** Spec 75b · Triple Match lesen. */
class TripleMatchGetTool extends WareneingangTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.triple_match.GET';
    }

    public function getDescription(): string
    {
        return 'Abgleich Bestellung ⇄ Lieferschein ⇄ Rechnung je Bestellzeile der letzten tage (Default 90): bestellt, geliefert, berechnet, Preise, '
            .'Befund (nicht_geliefert | menge | preis | zu_wenig | zu_viel | nicht_berechnet | offen | ok) und Δ €, schwerste/teuerste zuerst; Kopfzahlen. '
            .'Filter supplier_id, nur_abweichung. Toleranz ändern: triple_match.PUT. Reklamation: orders.CLAIM.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'supplier_id' => ['type' => 'integer'], 'nur_abweichung' => ['type' => 'boolean'], 'tage' => ['type' => 'integer'],
        ]];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->ausfuehren($context, function ($team, $userId) use ($arguments) {
            $tm = app(TripleMatchService::class);

            return $tm->abgleich($team, $arguments);
        });
    }

    public function getMetadata(): array
    {
        return $this->metadaten(false, ['triple_match', 'abgleich', 'read'], ['Wo weichen Rechnungen von Lieferung oder Bestellpreis ab?'], ['foodalchemist.supplier_invoices.GET', 'foodalchemist.orders.CLAIM']);
    }
}
