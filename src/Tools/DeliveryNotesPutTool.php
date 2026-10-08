<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\WareneingangService;

/** Spec 75a · Lieferschein-Entwurf ändern (Kopf und/oder Positionen ersetzen). */
class DeliveryNotesPutTool extends WareneingangTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.delivery_notes.PUT';
    }

    public function getDescription(): string
    {
        return 'Ändert einen Lieferschein im Entwurf (Rolle ab Kuratieren): Nummer, Datum, Notiz, Lagerort; lines ersetzt alle Positionen. '
            .'Gebuchte Lieferscheine erst stornieren.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'id' => ['type' => 'integer'],
            'delivery_note_number' => ['type' => 'string'], 'delivered_on' => ['type' => 'string'], 'note' => ['type' => 'string'],
            'inventory_location_id' => ['type' => 'integer'], 'lines' => $this->zeilenSchema(),
        ], 'required' => ['id']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->ausfuehren($context, function ($team, $userId) use ($arguments) {
            $svc = app(WareneingangService::class);
            $in = array_intersect_key($arguments, array_flip(['delivery_note_number', 'delivered_on', 'note', 'inventory_location_id', 'lines']));
            $svc->speichern($team, $in, $userId, (int) $arguments['id']);

            return ['lieferschein' => $svc->detail($team, (int) $arguments['id'])];
        });
    }

    public function getMetadata(): array
    {
        return $this->metadaten(true, ['lieferschein', 'write'], ['Beim Lieferschein 12 kamen nur 8 Sack Mehl.'], ['foodalchemist.delivery_notes.BOOK']);
    }
}
