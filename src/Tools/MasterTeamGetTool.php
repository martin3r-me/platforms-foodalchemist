<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\MasterTeamService;

/** Spec 82 §13 B4 · Master-Team lesen, optional die Wirkungs-Vorschau eines Wechsels (nur Plattform-Admin). */
class MasterTeamGetTool extends WareneingangTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.master_team.GET';
    }

    public function getDescription(): string
    {
        return 'Liest das Master-Team (team_id, Name, Quelle: einstellung | env | keiner, ENV-Fallback). Mit neues_team_id: Vorschau eines Wechsels '
            .'(betroffene Abos, ob eine Bestätigung nötig ist; null/0 = Eintrag entfernen, ENV gilt). Nur Plattform-Admin, sonst FORBIDDEN.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => ['neues_team_id' => ['type' => 'integer', 'description' => 'Zielteam für die Vorschau; 0 = Eintrag entfernen']]];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->ausfuehren($context, function (Team $team) use ($arguments, $context) {
            $svc = app(MasterTeamService::class);
            $svc->nurPlattformAdminOeffentlich($context->user, 'Master-Team einsehen');
            $out = ['master' => $svc->aktuell()];
            if (array_key_exists('neues_team_id', $arguments)) {
                $out['vorschau'] = $svc->vorschau((int) $arguments['neues_team_id'] ?: null);
            }

            return $out;
        });
    }

    public function getMetadata(): array
    {
        return array_merge($this->metadaten(false, ['master_team'], ['Wer ist das Master-Team?'], ['foodalchemist.master_team.PUT']), [
            'tags' => ['foodalchemist', 'master_team', 'plattform'],
        ]);
    }
}
