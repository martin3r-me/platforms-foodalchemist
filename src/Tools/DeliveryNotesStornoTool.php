<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\WareneingangService;

/** Spec 75a · Gebuchten Lieferschein stornieren bzw. Entwurf löschen. */
class DeliveryNotesStornoTool extends WareneingangTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.delivery_notes.STORNO';
    }

    public function getDescription(): string
    {
        return 'Gebuchter Lieferschein: storniert (Wareneingang und Lager zurück) — nur solange keine betroffene Bestellung abgeschlossen ist. '
            .'Entwurf: löscht ihn. Rolle ab Kuratieren.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'required' => ['id']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->ausfuehren($context, function ($team, $userId) use ($arguments) {
            $svc = app(WareneingangService::class);
            $id = (int) $arguments['id'];
            if (($svc->detail($team, $id)['status'] ?? null) === 'entwurf') {
                $svc->loeschen($team, $id, $userId);

                return ['geloescht' => true, 'id' => $id];
            }
            $svc->stornieren($team, $id, $userId);

            return ['lieferschein' => $svc->detail($team, $id)];
        });
    }

    public function getMetadata(): array
    {
        return $this->metadaten(true, ['lieferschein', 'storno'], ['Storniere den Lieferschein 12, der war falsch.'], ['foodalchemist.delivery_notes.GET']);
    }
}
