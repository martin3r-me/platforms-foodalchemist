<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\StandortService;

/** Spec 77c · Team-Brille umschalten (nur Ansicht, jede Rolle). */
class StandorteSetBrilleTool extends WareneingangTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.standorte.SET_BRILLE';
    }

    public function getDescription(): string
    {
        return 'Schaltet die Team-Brille des Oberteams um: modus eigen (nur das eigene Team), alle (eigenes Team und alle Standorte, '
            .'Listen gemischt mit Standort-Spalte) oder team (genau ein Standort, team_id). Nur Ansicht — geschrieben wird '
            .'immer im besitzenden Team, Belege eines Standorts sind im Oberteam nur lesbar.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'required' => ['modus'], 'properties' => [
            'modus' => ['type' => 'string', 'enum' => ['eigen', 'alle', 'team']], 'team_id' => ['type' => ['integer', 'null']],
        ]];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->ausfuehren($context, function ($team, $userId) use ($arguments) {
            $s = app(StandortService::class);
            $brille = $s->setzeBrille($team, (string) $arguments['modus'], isset($arguments['team_id']) ? (int) $arguments['team_id'] : null, $userId);

            return ['brille' => $brille, 'lese_team_ids' => $s->leseTeamIds($team, $userId)];
        });
    }

    public function getMetadata(): array
    {
        return $this->metadaten(true, ['standorte', 'brille'], ['Zeig mir alle Standorte gemeinsam.'], ['foodalchemist.standorte.GET']);
    }
}
