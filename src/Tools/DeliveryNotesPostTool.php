<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\WareneingangService;

/** Spec 75a · Lieferschein erfassen (Entwurf), optional gleich buchen. */
class DeliveryNotesPostTool extends WareneingangTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.delivery_notes.POST';
    }

    public function getDescription(): string
    {
        return 'Legt einen Lieferschein als Entwurf an (Rolle ab Kuratieren). Ohne lines: alle offenen Positionen des Lieferanten '
            .'mit offener Menge vorbelegt. Ein Lieferschein darf Positionen aus mehreren Bestellungen desselben Lieferanten tragen. '
            .'buchen=true bucht direkt (Wareneingang je Bestellzeile, Lager, vollständig gelieferte Bestellungen auf geliefert).';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'supplier_id' => ['type' => 'integer'],
            'delivery_note_number' => ['type' => 'string'],
            'delivered_on' => ['type' => 'string', 'description' => 'YYYY-MM-DD, Default heute.'],
            'note' => ['type' => 'string'],
            'inventory_location_id' => ['type' => 'integer', 'description' => 'Lagerort für Ware ohne Bestellung; Default Standardlager.'],
            'lines' => $this->zeilenSchema(),
            'buchen' => ['type' => 'boolean'],
            'abschliessen' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Nur mit buchen: diese Bestellungen auf geliefert setzen. Default: alle vollständig gelieferten.'],
        ], 'required' => ['supplier_id']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->ausfuehren($context, function ($team, $userId) use ($arguments) {
            $svc = app(WareneingangService::class);
            $in = array_intersect_key($arguments, array_flip(['supplier_id', 'delivery_note_number', 'delivered_on', 'note', 'inventory_location_id', 'lines']));
            $note = $svc->speichern($team, $in, $userId);
            if (! empty($arguments['buchen'])) {
                $svc->buchen($team, (int) $note->id, $userId, isset($arguments['abschliessen']) ? array_map('intval', (array) $arguments['abschliessen']) : null);
            }

            return ['lieferschein' => $svc->detail($team, (int) $note->id)];
        });
    }

    public function getMetadata(): array
    {
        return $this->metadaten(true, ['lieferschein', 'write'], ['Erfasse den Lieferschein 4711 von Chefs Culinar, alles wie bestellt, und buch ihn.'],
            ['foodalchemist.delivery_notes.GET', 'foodalchemist.delivery_notes.BOOK']);
    }
}
