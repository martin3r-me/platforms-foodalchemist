<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\StandortService;

/** Spec 77c · Oberteam ordnet einem Standort (Unter-Team) einen seiner Betriebe zu. Nur FA-Admin des Oberteams. */
class StandortePutTool extends WareneingangTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.standorte.PUT';
    }

    public function getDescription(): string
    {
        return 'Ordnet einem Standort (unter_team_id, ein Unter-Team dieses Teams) einen aktiven Betrieb des Oberteams zu '
            .'(outlet_id). outlet_id null = Standard, der Standort erbt alles. Im Standort ist die Betriebs-Brille danach fest '
            .'auf diesen Betrieb eingestellt. Nur Inhaber und Admins des Oberteams.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'required' => ['unter_team_id'], 'properties' => [
            'unter_team_id' => ['type' => 'integer'], 'outlet_id' => ['type' => ['integer', 'null']],
        ]];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->ausfuehren($context, function ($team) use ($arguments, $context) {
            $s = app(StandortService::class);
            $outlet = isset($arguments['outlet_id']) && $arguments['outlet_id'] !== '' ? (int) $arguments['outlet_id'] : null;
            $s->betriebZuordnen($team, (int) $arguments['unter_team_id'], $outlet, $context->user);

            return ['standorte' => $s->unterTeams($team)];
        });
    }

    public function getMetadata(): array
    {
        return $this->metadaten(true, ['standorte', 'betriebe'], ['Ordne dem Standort Köln den Betrieb Kantine Nord zu.'], ['foodalchemist.standorte.GET']);
    }
}
