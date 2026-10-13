<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\ProjektUebernahmeService;

/** Spec 82 §9 · „Lizenz erteilt“: Projekt in den Mandanten übernehmen (Kopie). Einmalig, nur Plattform-Admin, protokolliert. */
class ProjekteUebernahmePostTool extends WareneingangTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.projekte.UEBERNAHME_POST';
    }

    public function getDescription(): string
    {
        return 'Erteilt die Lizenz und übernimmt die Agentur-Akte in den Mandanten (Kopie, nicht live): je Kunden-Standort ein Unter-Team unter oberteam_id mit dem Standortprofil als Teamprofil, '
            .'Kunden-DNA wird Team-DNA des Oberteams („Bleibt“ wird verbindlich, schon gefüllte Felder bleiben), Mandant aktiv + kuratiert mit CRM-Firma. Einmalig je Projekt, '
            .'nicht rückgängig zu machen. Erst UEBERNAHME_GET lesen. Nur Plattform-Admin, protokolliert.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => ['projekt_id' => ['type' => 'integer'], 'oberteam_id' => ['type' => 'integer']], 'required' => ['projekt_id', 'oberteam_id']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->ausfuehren($context, function (Team $team) use ($arguments, $context) {
            $oberteam = Team::findOrFail((int) $arguments['oberteam_id']);

            return ['bericht' => app(ProjektUebernahmeService::class)->uebernehmen($team, $context->user, (int) $arguments['projekt_id'], $oberteam)];
        });
    }

    public function getMetadata(): array
    {
        return array_merge($this->metadaten(true, ['projekte', 'lizenz'], ['Erteile Soulkitchen die Lizenz und übernimm das Projekt.'], ['foodalchemist.projekte.UEBERNAHME_GET']), [
            'tags' => ['foodalchemist', 'projekte', 'lizenz', 'write'],
        ]);
    }
}
