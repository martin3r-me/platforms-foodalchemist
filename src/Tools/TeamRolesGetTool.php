<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Services\FaRechte;

/** Spec 61/75 · Rechte der Teammitglieder lesen (abgeleitet aus der Plattform-Rolle). */
class TeamRolesGetTool extends WareneingangTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.team_roles.GET';
    }

    public function getDescription(): string
    {
        return 'Listet die Mitglieder mit Plattform-Rolle (owner | admin | member | viewer, gepflegt in den Team-Einstellungen der Plattform), '
            .'der daraus abgeleiteten FA-Stufe (lesen | kuratieren | freigeben | admin) und dem Häkchen „darf Rechnungen freigeben". '
            .'Dazu die eigene Stufe des Aufrufers.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass()];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->ausfuehren($context, function ($team) use ($context) {
            $rechte = app(FaRechte::class);

            return [
                'meine_rolle' => $rechte->rolle($context->user, $team)->value,
                'stufen' => array_map(fn (FaRolle $r) => ['rolle' => $r->value, 'label' => $r->label(), 'darf' => $r->beschreibung()], FaRolle::cases()),
                'mitglieder' => $rechte->mitglieder($team),
            ];
        });
    }

    public function getMetadata(): array
    {
        return $this->metadaten(false, ['rechte', 'rollen'], ['Wer darf bei uns Rechnungen freigeben?'], ['foodalchemist.team_roles.PUT']);
    }
}
