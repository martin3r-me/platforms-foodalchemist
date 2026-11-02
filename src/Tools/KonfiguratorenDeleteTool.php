<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Models\Team;

/** Spec 94 · foodalchemist.konfiguratoren.DELETE */
class KonfiguratorenDeleteTool extends KonfiguratorToolBasis
{
    protected function ressource(): string
    {
        return 'konfiguratoren';
    }

    protected function verb(): string
    {
        return 'DELETE';
    }

    protected function beschreibung(): string
    {
        return 'Eigenen Konfigurator samt Konzepten, Leistungen und Paketen löschen.';
    }

    protected function felder(): array
    {
        return [['id' => ['type' => 'integer']], ['id']];
    }

    protected function beispiele(): array
    {
        return ['Lösche Konfigurator 3.'];
    }

    protected function ausfuehren(Team $team, array $a): mixed
    {
        app(\Platform\FoodAlchemist\Services\KonfiguratorService::class)->loeschen($team, self::id($a));

        return ['geloescht' => (int) $a['id']];
    }
}
