<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\FassungService;

/** Spec 82 §12 B · Rezept veröffentlichen: friert Rezept + Unter-Rezepte des Masters als Fassung ein (nur Master, Rolle „Freigeben“). */
class RecipeFassungenPublishTool extends WareneingangTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.recipe_fassungen.PUBLISH';
    }

    public function getDescription(): string
    {
        return 'Veröffentlicht den Arbeitsstand eines Master-Rezepts als neue Fassung (schreibgeschützt, samt Unter-Rezepten; '
            .'unveränderte Unter-Rezepte werden wiederverwendet). Erst danach sehen Abonnenten die Änderung. Nur im Master-Team '
            .'mit FA-Rolle „Freigeben“; Master-Rezepte dürfen nur globale Stamm-GPs nutzen. notiz (Änderungsprotokoll) ist Pflicht. '
            .'Liefert die neue Fassung und welche Fassungen ein geändertes Allergenbild haben.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'recipe_id' => ['type' => 'integer', 'description' => 'Arbeitszeile des Master-Rezepts.'],
            'notiz' => ['type' => 'string', 'description' => 'Änderungsprotokoll, z. B. „Jus: Röstgemüse angepasst“.'],
        ], 'required' => ['recipe_id', 'notiz']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->ausfuehren($context, fn (Team $team) => app(FassungService::class)
            ->veroeffentlichen($team, (int) ($arguments['recipe_id'] ?? 0), (string) ($arguments['notiz'] ?? ''), $context->user));
    }

    public function getMetadata(): array
    {
        return array_merge($this->metadaten(true, ['rezepte', 'fassung'], ['Veröffentliche den Kalbsjus mit der Notiz „Röstgemüse angepasst“.'], ['foodalchemist.recipe_fassungen.GET']), [
            'tags' => ['foodalchemist', 'rezepte', 'fassung', 'kurator', 'write'],
        ]);
    }
}
