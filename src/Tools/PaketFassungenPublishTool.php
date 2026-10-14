<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\PaketFassungService;

/** Spec 82 Abo Pakete · Paket veröffentlichen: friert den Arbeitsstand eines Master-Pakets als Fassung ein (nur Master, Rolle „Freigeben“). */
class PaketFassungenPublishTool extends WareneingangTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.paket_fassungen.PUBLISH';
    }

    public function getDescription(): string
    {
        return 'Veröffentlicht den Arbeitsstand eines Master-Pakets als neue Fassung (schreibgeschützt, ohne Master-Preise). Jedes Rezept im Paket '
            .'braucht eine veröffentlichte Rezept-Fassung, sonst bricht es mit einer Liste ab. Gleicher Inhalt wie die letzte Fassung erzeugt keine neue. '
            .'Nur im Master-Team mit FA-Rolle „Freigeben“; notiz (Änderungsprotokoll) ist Pflicht. Liefert fassung_id, neu, fassung_nr und Hinweise '
            .'(z. B. Rezepte mit unveröffentlichten Änderungen).';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'paket_id' => ['type' => 'integer', 'description' => 'Arbeitszeile des Master-Pakets.'],
            'notiz' => ['type' => 'string', 'description' => 'Änderungsprotokoll, z. B. „Salad Wall: Dressing getauscht“.'],
        ], 'required' => ['paket_id', 'notiz']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->ausfuehren($context, fn (Team $team) => app(PaketFassungService::class)
            ->veroeffentlichen($team, (int) ($arguments['paket_id'] ?? 0), (string) ($arguments['notiz'] ?? ''), $context->user));
    }

    public function getMetadata(): array
    {
        return array_merge($this->metadaten(true, ['pakete', 'fassung'], ['Veröffentliche das Paket Salad Wall mit der Notiz „Dressing getauscht“.'], ['foodalchemist.paket_fassungen.GET']), [
            'tags' => ['foodalchemist', 'pakete', 'fassung', 'kurator', 'write'],
        ]);
    }
}
