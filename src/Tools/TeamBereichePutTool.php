<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\FaRechte;

/** Spec 77b · Bereiche schalten, Mitglieder einschränken, Kontingente setzen. */
class TeamBereichePutTool extends WareneingangTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.team_bereiche.PUT';
    }

    public function getDescription(): string
    {
        return 'Drei Aktionen (je Aufruf eine): (1) user_id + bereich + darf (bool) — Team-Admin schränkt ein Mitglied ein (darf=false) '
            .'oder hebt die Einschränkung auf; nur einschränken, nie über das Team hinaus. (2) bereich + aktiv (bool) ohne user_id — '
            .'Bereich für das Team an/aus (nur Plattform-Admin). (3) kontingente {max_standorte, max_user, ki_budget_eur_monat} '
            .'(nur Plattform-Admin, null = unbegrenzt). Fehlt das Recht: FORBIDDEN.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'user_id' => ['type' => 'integer'], 'bereich' => ['type' => 'string'],
            'darf' => ['type' => 'boolean'], 'aktiv' => ['type' => 'boolean'],
            'kontingente' => ['type' => 'object', 'properties' => [
                'max_standorte' => ['type' => ['integer', 'null']], 'max_user' => ['type' => ['integer', 'null']],
                'ki_budget_eur_monat' => ['type' => ['number', 'string', 'null']],
            ]],
        ]];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->ausfuehren($context, function ($team) use ($arguments, $context) {
            $rechte = app(FaRechte::class);
            if (isset($arguments['kontingente']) && is_array($arguments['kontingente'])) {
                $rechte->setzeKontingente($team, $context->user, $arguments['kontingente']);

                return ['kontingente' => $rechte->kontingente($team)];
            }
            $bereich = (string) ($arguments['bereich'] ?? '');
            if ($bereich === '') {
                throw new \RuntimeException('Bitte bereich angeben (oder kontingente).');
            }
            if (! empty($arguments['user_id'])) {
                $rechte->setzeUserSperre($team, $context->user, (int) $arguments['user_id'], $bereich, ! (bool) ($arguments['darf'] ?? true));

                return ['user_id' => (int) $arguments['user_id'], 'gesperrte_bereiche' => $rechte->userSperren($team, (int) $arguments['user_id'])];
            }
            $rechte->setzeTeamBereich($team, $context->user, $bereich, (bool) ($arguments['aktiv'] ?? true));

            return ['team_bereiche' => $rechte->teamBereiche($team)];
        });
    }

    public function getMetadata(): array
    {
        return $this->metadaten(true, ['rechte', 'bereiche', 'kontingente', 'write'], ['Anna soll das Controlling nicht sehen.'], ['foodalchemist.team_bereiche.GET']);
    }
}
