<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\StandortService;

/** Spec 77c · Standorte (Unter-Teams) des Teams, ihr fester Betrieb und die eigene Team-Brille lesen. */
class StandorteGetTool extends WareneingangTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.standorte.GET';
    }

    public function getDescription(): string
    {
        return 'Liest die Standorte des Teams: jeder Standort ist ein Unter-Team mit eigenen Einkaufspreisen, Bestellungen und Lager '
            .'und fährt genau einen Betrieb des Oberteams (betrieb_id null = Standard, erbt alles). Dazu die Team-Brille des '
            .'Benutzers (eigen | alle | team) und welche Team-IDs Listen und Auswertungen damit gerade lesen.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass()];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->ausfuehren($context, function ($team, $userId) {
            $s = app(StandortService::class);
            $betrieb = $s->zugeordneterBetrieb($team);

            return [
                'standorte' => $s->unterTeams($team),
                'eigener_betrieb' => $betrieb !== null ? ['id' => (int) $betrieb->id, 'name' => (string) $betrieb->name] : null,
                'brille' => $s->brille($team, $userId),
                'lese_team_ids' => $s->leseTeamIds($team, $userId),
            ];
        });
    }

    public function getMetadata(): array
    {
        return $this->metadaten(false, ['standorte', 'betriebe', 'brille'], ['Welche Standorte haben wir und welchen Betrieb fahren sie?'],
            ['foodalchemist.standorte.PUT', 'foodalchemist.standorte.SET_BRILLE']);
    }
}
