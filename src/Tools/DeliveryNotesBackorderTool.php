<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\WareneingangService;

/** Spec 75a · Unterlieferte Bestellung abschließen, Rest als Nachlieferungs-Entwurf. */
class DeliveryNotesBackorderTool extends WareneingangTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.delivery_notes.BACKORDER';
    }

    public function getDescription(): string
    {
        return 'Schließt eine gesendete/bestätigte Bestellung ab und legt die Fehlmenge als Nachlieferungs-Entwurf beim gleichen '
            .'Lieferanten an (Rolle ab Kuratieren). Positionen ohne Wareneingang zählen als nicht geliefert. Der Rest kommt später '
            .'als Lieferschein auf die Nachlieferung.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'order_id' => ['type' => 'integer'],
            'liefertag' => ['type' => 'string', 'description' => 'YYYY-MM-DD der Nachlieferung (optional).'],
        ], 'required' => ['order_id']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->ausfuehren($context, fn ($team, $userId) => ['nachlieferung' => app(WareneingangService::class)
            ->nachlieferung($team, (int) $arguments['order_id'], $arguments['liefertag'] ?? null, $userId)]);
    }

    public function getMetadata(): array
    {
        return $this->metadaten(true, ['nachlieferung', 'write'], ['Der Rest von Bestellung ord-41 kommt Freitag nach.'], ['foodalchemist.delivery_notes.GET']);
    }
}
