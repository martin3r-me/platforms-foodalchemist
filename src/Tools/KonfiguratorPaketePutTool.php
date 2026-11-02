<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Models\Team;

/** Spec 94 · foodalchemist.konfigurator_pakete.PUT */
class KonfiguratorPaketePutTool extends KonfiguratorToolBasis
{
    protected function ressource(): string
    {
        return 'konfigurator_pakete';
    }

    protected function verb(): string
    {
        return 'PUT';
    }

    protected function beschreibung(): string
    {
        return 'Paket ändern (Grenzen, Bezeichnung, referenz_pax; null = zurück auf Mitte) und neu schnüren.';
    }

    protected function felder(): array
    {
        return [['id' => ['type' => 'integer'], 'von_pax' => ['type' => 'integer'], 'bis_pax' => ['type' => 'integer'], 'bezeichnung' => ['type' => 'string'], 'referenz_pax' => ['type' => ['integer', 'null']]], ['id']];
    }

    protected function beispiele(): array
    {
        return ['Setze bei Paket 5 die Obergrenze auf 249.'];
    }

    protected function ausfuehren(Team $team, array $a): mixed
    {
        return ['paket' => app(\Platform\FoodAlchemist\Services\KonfiguratorService::class)->paketAendern($team, self::id($a), $a)->toArray()];
    }
}
