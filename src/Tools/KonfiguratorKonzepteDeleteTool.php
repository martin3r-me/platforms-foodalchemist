<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Models\Team;

/** Spec 94 · foodalchemist.konfigurator_konzepte.DELETE */
class KonfiguratorKonzepteDeleteTool extends KonfiguratorToolBasis
{
    protected function ressource(): string
    {
        return 'konfigurator_konzepte';
    }

    protected function verb(): string
    {
        return 'DELETE';
    }

    protected function beschreibung(): string
    {
        return 'Konzept entfernen (seine Zusatz-Leistungen gehen mit).';
    }

    protected function felder(): array
    {
        return [['id' => ['type' => 'integer']], ['id']];
    }

    protected function beispiele(): array
    {
        return ['Entferne Konzept 9.'];
    }

    protected function ausfuehren(Team $team, array $a): mixed
    {
        app(\Platform\FoodAlchemist\Services\KonfiguratorService::class)->konzeptEntfernen($team, self::id($a));

        return ['geloescht' => (int) $a['id']];
    }
}
