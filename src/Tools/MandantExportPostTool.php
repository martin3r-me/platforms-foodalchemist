<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\MandantExportService;

/**
 * Spec 82 §13 C3 · Datenexport eines Mandanten ANSTOSSEN (Job). Plattform-Admin oder Inhaber/Admin des Mandanten. Der Auslöser sieht
 * nur Status und Zählung; die Datei lädt nur Inhaber/Admin des Mandanten (§13 G). Ein laufender Export wird wiederverwendet.
 */
class MandantExportPostTool extends WareneingangTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.mandant_export.POST';
    }

    public function getDescription(): string
    {
        return 'Startet den Datenexport eines Mandanten (alle eigenen Inhalte des Mandanten und seiner Unter-Teams als ZIP). Läuft asynchron; Stand und Manifest '
            .'über mandant_export.GET. Plattform-Admin oder Inhaber/Admin des Mandanten. Der Plattform-Admin sieht nie Inhalte, die Datei laden nur Inhaber/Admin des Mandanten.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => ['team_id' => ['type' => 'integer', 'description' => 'Mandant (Standard: eigenes Team).']]];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->ausfuehren($context, function (Team $team) use ($arguments, $context) {
            $svc = app(MandantExportService::class);
            $mandant = ! empty($arguments['team_id']) ? Team::findOrFail((int) $arguments['team_id']) : $team;
            $e = $svc->starte($mandant, $context->user);

            return ['team_id' => (int) $mandant->id, 'export' => $svc->darstellung($e->refresh(), $context->user, $mandant)];
        });
    }

    public function getMetadata(): array
    {
        return array_merge($this->metadaten(true, ['mandanten', 'export'], ['Exportiere alle Daten von Soulkitchen.'], ['foodalchemist.mandant_export.GET']), [
            'tags' => ['foodalchemist', 'mandanten', 'export'],
        ]);
    }
}
