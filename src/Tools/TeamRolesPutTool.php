<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\FaRechte;

/** Spec 61/75 · Häkchen „darf Rechnungen freigeben" für ein Mitglied setzen (nur FA-Admin). */
class TeamRolesPutTool extends WareneingangTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.team_roles.PUT';
    }

    public function getDescription(): string
    {
        return 'Setzt für ein Mitglied (Plattform-Rolle member) das FA-Häkchen „darf Rechnungen freigeben" (nur Inhaber/Admin). '
            .'Die Rolle selbst (owner/admin/member/viewer) pflegt man in den Team-Einstellungen der Plattform, nicht hier. '
            .'KI-Benutzer und Betrachter bekommen das Häkchen nie.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'user_id' => ['type' => 'integer'],
            'darf_freigeben' => ['type' => 'boolean'],
        ], 'required' => ['user_id', 'darf_freigeben']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->ausfuehren($context, function ($team) use ($arguments, $context) {
            $rechte = app(FaRechte::class);
            $rechte->setzeFreigabe($team, $context->user, (int) $arguments['user_id'], (bool) $arguments['darf_freigeben']);

            return ['user_id' => (int) $arguments['user_id'],
                'rolle' => $rechte->rolle(\Platform\Core\Models\User::find((int) $arguments['user_id']), $team)->value];
        });
    }

    public function getMetadata(): array
    {
        return $this->metadaten(true, ['rechte', 'freigabe', 'write'], ['Anna aus dem Büro soll Rechnungen freigeben dürfen.'], ['foodalchemist.team_roles.GET']);
    }
}
