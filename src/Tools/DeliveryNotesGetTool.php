<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\WareneingangService;

/** Spec 75a · Lieferscheine lesen: Liste, Detail, erwartete Lieferungen, Vorschlag je Lieferant. */
class DeliveryNotesGetTool extends WareneingangTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.delivery_notes.GET';
    }

    public function getDescription(): string
    {
        return 'Wareneingang lesen. id = ein Lieferschein mit Positionen (bestellt, erwartet, geliefert, Abweichung). '
            .'ansicht=erwartet = gesendete/bestätigte Bestellungen mit offener Ware nach Liefertag. '
            .'ansicht=vorschlag + supplier_id (optional order_ids) = offene Positionen des Lieferanten über alle offenen Bestellungen, '
            .'Menge = noch offen (Grundlage für delivery_notes.POST). Sonst Liste mit Filtern supplier_id, status '
            .'(entwurf|gebucht|storniert), von, bis, suche, nur_abweichung.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'id' => ['type' => 'integer'],
            'ansicht' => ['type' => 'string', 'enum' => ['liste', 'erwartet', 'vorschlag']],
            'supplier_id' => ['type' => 'integer'],
            'order_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
            'status' => ['type' => 'string', 'enum' => ['entwurf', 'gebucht', 'storniert']],
            'von' => ['type' => 'string'], 'bis' => ['type' => 'string'], 'suche' => ['type' => 'string'],
            'nur_abweichung' => ['type' => 'boolean'],
        ]];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->ausfuehren($context, function ($team) use ($arguments) {
            $svc = app(WareneingangService::class);
            if (! empty($arguments['id'])) {
                return ['lieferschein' => $svc->detail($team, (int) $arguments['id'])];
            }

            return match ($arguments['ansicht'] ?? 'liste') {
                'erwartet' => ['lieferungen' => $svc->erwarteteLieferungen($team, $arguments['bis'] ?? null)],
                'vorschlag' => empty($arguments['supplier_id'])
                    ? throw new \RuntimeException('Für den Vorschlag bitte supplier_id angeben.')
                    : ['positionen' => $svc->vorbelegen($team, (int) $arguments['supplier_id'], isset($arguments['order_ids']) ? array_map('intval', (array) $arguments['order_ids']) : null)],
                default => ['lieferscheine' => $svc->liste($team, $arguments)],
            };
        });
    }

    public function getMetadata(): array
    {
        return $this->metadaten(false, ['lieferschein', 'read'], ['Welche Lieferungen erwarten wir heute?', 'Zeig mir den Lieferschein 12.'],
            ['foodalchemist.delivery_notes.POST', 'foodalchemist.delivery_notes.BOOK']);
    }
}
