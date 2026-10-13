<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\MasterTeamService;

/** Spec 82 §13 B4 · Master-Team festlegen (nur Plattform-Admin). */
class MasterTeamPutTool extends WareneingangTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.master_team.PUT';
    }

    public function getDescription(): string
    {
        return 'Setzt das Master-Team (team_id) oder entfernt den Eintrag (team_id 0: ENV FOODALCHEMIST_MASTER_TEAM_ID gilt wieder). Ein Wechsel ändert die Sichtbarkeit aller Abos: '
            .'mit Abos nur mit bestaetigt=true (vorher master_team.GET mit neues_team_id für die Vorschau). Jede Änderung wird protokolliert. Nur Plattform-Admin, sonst FORBIDDEN.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => ['team_id' => ['type' => 'integer'], 'bestaetigt' => ['type' => 'boolean']], 'required' => ['team_id']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->ausfuehren($context, function (Team $team) use ($arguments, $context) {
            $svc = app(MasterTeamService::class);
            $id = (int) ($arguments['team_id'] ?? 0);
            $neu = $id > 0 ? Team::findOrFail($id) : null;
            $svc->setze($neu, $context->user, (bool) ($arguments['bestaetigt'] ?? false));

            return ['master' => $svc->aktuell()];
        });
    }

    public function getMetadata(): array
    {
        return array_merge($this->metadaten(true, ['master_team'], ['BHG.DIGITAL ist das Master-Team.'], ['foodalchemist.master_team.GET']), [
            'tags' => ['foodalchemist', 'master_team', 'plattform', 'write'],
        ]);
    }
}
