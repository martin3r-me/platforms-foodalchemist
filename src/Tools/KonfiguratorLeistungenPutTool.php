<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Models\Team;

/** Spec 94 · foodalchemist.konfigurator_leistungen.PUT */
class KonfiguratorLeistungenPutTool extends KonfiguratorToolBasis
{
    protected function ressource(): string
    {
        return 'konfigurator_leistungen';
    }

    protected function verb(): string
    {
        return 'PUT';
    }

    protected function beschreibung(): string
    {
        return 'Leistung im Konfigurator ändern (Regel, Preise, Zuordnung).';
    }

    protected function felder(): array
    {
        return [['id' => ['type' => 'integer']] + self::regelSchema() + self::zuordnungSchema(), ['id']];
    }

    protected function beispiele(): array
    {
        return ['Setze bei Leistung 21 den Schlüssel auf 1 je 25 Gäste.'];
    }

    protected function ausfuehren(Team $team, array $a): mixed
    {
        return ['leistung' => app(\Platform\FoodAlchemist\Services\KonfiguratorService::class)->leistungAendern($team, self::id($a), $a)->toArray()];
    }
}
