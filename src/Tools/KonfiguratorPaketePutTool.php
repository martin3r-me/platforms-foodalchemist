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
        return 'Paket ändern (Grenzen, Bezeichnung, referenz_pax; 0 = zurück auf die Mitte) und neu schnüren.';
    }

    protected function felder(): array
    {
        return [['id' => ['type' => 'integer'], 'von_pax' => ['type' => 'integer'], 'bis_pax' => ['type' => 'integer'], 'bezeichnung' => ['type' => 'string'], 'referenz_pax' => ['type' => 'integer', 'description' => 'Referenz-Pax von Hand; 0 = zurück auf die Mitte des Pakets']], ['id']];
    }

    protected function beispiele(): array
    {
        return ['Setze bei Paket 5 die Obergrenze auf 249.'];
    }

    protected function ausfuehren(Team $team, array $a): mixed
    {
        if (array_key_exists('referenz_pax', $a) && (int) $a['referenz_pax'] === 0) {
            $a['referenz_pax'] = null;   // 0 = zurück auf die Mitte (kein Union-Typ im Schema)
        }

        return ['paket' => app(\Platform\FoodAlchemist\Services\KonfiguratorService::class)->paketAendern($team, self::id($a), $a)->toArray()];
    }
}
