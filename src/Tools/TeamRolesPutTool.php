<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Services\FaRechte;

/** Spec 61 · FA-Rolle eines Mitglieds setzen (nur FA-Admin). */
class TeamRolesPutTool extends WareneingangTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.team_roles.PUT';
    }

    public function getDescription(): string
    {
        return 'Setzt die FA-Rolle eines Teammitglieds (lesen | kuratieren | freigeben | admin). Nur FA-Admins. Inhaber und Admins des '
            .'Teams sind immer FA-Admin und lassen sich hier nicht ändern; KI-Benutzer bekommen höchstens kuratieren.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'user_id' => ['type' => 'integer'],
            'rolle' => ['type' => 'string', 'enum' => array_map(fn ($r) => $r->value, FaRolle::cases())],
        ], 'required' => ['user_id', 'rolle']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->ausfuehren($context, function ($team) use ($arguments, $context) {
            $rolle = FaRolle::tryFrom((string) ($arguments['rolle'] ?? '')) ?? throw new \RuntimeException('Unbekannte Rolle. Erlaubt: lesen, kuratieren, freigeben, admin.');
            app(FaRechte::class)->setzeRolle($team, $context->user, (int) $arguments['user_id'], $rolle);

            return ['user_id' => (int) $arguments['user_id'], 'rolle' => app(FaRechte::class)->rolle(\Platform\Core\Models\User::find((int) $arguments['user_id']), $team)->value];
        });
    }

    public function getMetadata(): array
    {
        return $this->metadaten(true, ['rechte', 'rollen', 'write'], ['Gib Anna die Rolle Kuratieren.'], ['foodalchemist.team_roles.GET']);
    }
}
