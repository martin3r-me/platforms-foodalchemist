<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\WareneingangService;

/** Spec 75a · Lieferschein buchen. */
class DeliveryNotesBookTool extends WareneingangTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.delivery_notes.BOOK';
    }

    public function getDescription(): string
    {
        return 'Bucht einen Lieferschein-Entwurf (Rolle ab Kuratieren): gelieferte Gebinde kommen als Wareneingang auf die Bestellzeilen '
            .'(Lager, Kontingent und Einkaufsjournal ziehen nach), Ware ohne Bestellung als Lagerzugang. abschliessen = Bestellungen, '
            .'die danach auf geliefert gehen; Default alle vollständig gelieferten. Positionen ohne Lieferschein zählen beim Abschluss als 0.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'id' => ['type' => 'integer'],
            'abschliessen' => ['type' => 'array', 'items' => ['type' => 'integer']],
        ], 'required' => ['id']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->ausfuehren($context, function ($team, $userId) use ($arguments) {
            $svc = app(WareneingangService::class);
            $svc->buchen($team, (int) $arguments['id'], $userId, isset($arguments['abschliessen']) ? array_map('intval', (array) $arguments['abschliessen']) : null);

            return ['lieferschein' => $svc->detail($team, (int) $arguments['id'])];
        });
    }

    public function getMetadata(): array
    {
        return $this->metadaten(true, ['lieferschein', 'buchen'], ['Buch den Lieferschein 12.'], ['foodalchemist.delivery_notes.STORNO', 'foodalchemist.delivery_notes.BACKORDER']);
    }
}
