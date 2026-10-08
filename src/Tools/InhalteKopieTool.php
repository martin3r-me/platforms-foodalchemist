<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\InhaltsFreigabeService;

/** Spec 77d · Freigegebenes oder geerbtes Rezept/Konzept/Format als eigene Kopie ins Team holen. */
class InhalteKopieTool extends WareneingangTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.inhalte.KOPIE';
    }

    public function getDescription(): string
    {
        return 'Freigegebenes (oder vom Oberteam geerbtes) Rezept, Konzept oder Format ist im Standort nur lesbar. Zum Anpassen legt '
            .'dieses Tool eine eigene Kopie im aktuellen Team an (typ recipe|concept|format, id). Die Kopie merkt sich ihr Original '
            .'(kopie_von_id) und zeigt später an, wenn sich das Original geändert hat. Ab Kuratieren.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'required' => ['typ', 'id'], 'properties' => [
            'typ' => ['type' => 'string', 'enum' => ['recipe', 'concept', 'format']], 'id' => ['type' => 'integer'],
        ]];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->ausfuehren($context, function ($team) use ($arguments, $context) {
            $k = app(InhaltsFreigabeService::class)->kopieAnlegen($team, (string) $arguments['typ'], (int) $arguments['id'], $context->user);

            return ['id' => (int) $k->id, 'name' => (string) $k->name, 'kopie_von_id' => (int) $k->kopie_von_id];
        });
    }

    public function getMetadata(): array
    {
        return $this->metadaten(true, ['freigaben', 'kopie'], ['Hol dir das Gulasch-Rezept vom Oberteam als eigene Kopie.'], ['foodalchemist.standort_inhalte.GET']);
    }
}
