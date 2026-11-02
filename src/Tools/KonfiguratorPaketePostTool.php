<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Models\Team;

/** Spec 94 · foodalchemist.konfigurator_pakete.POST */
class KonfiguratorPaketePostTool extends KonfiguratorToolBasis
{
    protected function ressource(): string
    {
        return 'konfigurator_pakete';
    }

    protected function verb(): string
    {
        return 'POST';
    }

    protected function beschreibung(): string
    {
        return 'Pax-Paket anlegen (von_pax, bis_pax, optional bezeichnung, referenz_pax; Standard Mitte) und schnüren.';
    }

    protected function felder(): array
    {
        return [['konfigurator_id' => ['type' => 'integer'], 'von_pax' => ['type' => 'integer'], 'bis_pax' => ['type' => 'integer'], 'bezeichnung' => ['type' => 'string'], 'referenz_pax' => ['type' => 'integer']], ['konfigurator_id', 'von_pax', 'bis_pax']];
    }

    protected function beispiele(): array
    {
        return ['Lege bei Konfigurator 3 das Paket 100–199 Gäste an.'];
    }

    protected function ausfuehren(Team $team, array $a): mixed
    {
        return ['paket' => app(\Platform\FoodAlchemist\Services\KonfiguratorService::class)->paketAnlegen($team, self::id($a, 'konfigurator_id'), $a)->toArray()];
    }
}
