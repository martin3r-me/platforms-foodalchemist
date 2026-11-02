<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Models\Team;

/** Spec 94 · foodalchemist.konfigurator_leistungen.DELETE */
class KonfiguratorLeistungenDeleteTool extends KonfiguratorToolBasis
{
    protected function ressource(): string
    {
        return 'konfigurator_leistungen';
    }

    protected function verb(): string
    {
        return 'DELETE';
    }

    protected function beschreibung(): string
    {
        return 'Leistung aus dem Konfigurator entfernen.';
    }

    protected function felder(): array
    {
        return [['id' => ['type' => 'integer']], ['id']];
    }

    protected function beispiele(): array
    {
        return ['Entferne Leistung 21.'];
    }

    protected function ausfuehren(Team $team, array $a): mixed
    {
        app(\Platform\FoodAlchemist\Services\KonfiguratorService::class)->leistungEntfernen($team, self::id($a));

        return ['geloescht' => (int) $a['id']];
    }
}
