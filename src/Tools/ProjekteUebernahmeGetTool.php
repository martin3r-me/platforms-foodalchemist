<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\ProjektUebernahmeService;

/** Spec 82 §9 · Vorschau der Lizenz-Übernahme (Unter-Teams, DNA-Felder, Hindernisse). Schreibt nichts. Nur Plattform-Admin. */
class ProjekteUebernahmeGetTool extends WareneingangTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.projekte.UEBERNAHME_GET';
    }

    public function getDescription(): string
    {
        return 'Zeigt, was „Lizenz erteilt“ für ein Projekt täte, ohne etwas zu schreiben: Unter-Teams (je Kunden-Standort), Kunden-DNA-Felder die Team-DNA des Oberteams werden '
            .'(davon „Bleibt“ = verbindlich), schon gefüllte Felder (bleiben), Hindernisse. oberteam_id = das vorhandene Haupt-Team des Kunden. Nur Plattform-Admin.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => ['projekt_id' => ['type' => 'integer'], 'oberteam_id' => ['type' => 'integer']], 'required' => ['projekt_id', 'oberteam_id']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->ausfuehren($context, function (Team $team) use ($arguments, $context) {
            $oberteam = Team::findOrFail((int) $arguments['oberteam_id']);

            return ['vorschau' => app(ProjektUebernahmeService::class)->vorschau($team, $context->user, (int) $arguments['projekt_id'], $oberteam)];
        });
    }

    public function getMetadata(): array
    {
        return array_merge($this->metadaten(false, ['projekte', 'lizenz'], ['Was passiert, wenn Soulkitchen die Lizenz bekommt?'], ['foodalchemist.projekte.UEBERNAHME_POST']), [
            'tags' => ['foodalchemist', 'projekte', 'lizenz', 'read'],
        ]);
    }
}
