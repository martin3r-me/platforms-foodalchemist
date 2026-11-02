<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Models\Team;

/** Spec 94 · foodalchemist.konfigurator_pakete.DELETE */
class KonfiguratorPaketeDeleteTool extends KonfiguratorToolBasis
{
    protected function ressource(): string
    {
        return 'konfigurator_pakete';
    }

    protected function verb(): string
    {
        return 'DELETE';
    }

    protected function beschreibung(): string
    {
        return 'Paket entfernen.';
    }

    protected function felder(): array
    {
        return [['id' => ['type' => 'integer']], ['id']];
    }

    protected function beispiele(): array
    {
        return ['Entferne Paket 5.'];
    }

    protected function ausfuehren(Team $team, array $a): mixed
    {
        app(\Platform\FoodAlchemist\Services\KonfiguratorService::class)->paketEntfernen($team, self::id($a));

        return ['geloescht' => (int) $a['id']];
    }
}
