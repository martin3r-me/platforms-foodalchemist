<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Models\Team;

/** Spec 94 · foodalchemist.konfigurator_konzepte.PUT */
class KonfiguratorKonzeptePutTool extends KonfiguratorToolBasis
{
    protected function ressource(): string
    {
        return 'konfigurator_konzepte';
    }

    protected function verb(): string
    {
        return 'PUT';
    }

    protected function beschreibung(): string
    {
        return 'Konzept im Konfigurator ändern (Concepts, Titel, Claim, Indikativ, Abschnitt, Bindung, Position).';
    }

    protected function felder(): array
    {
        return [['id' => ['type' => 'integer']] + self::konzeptSchema(), ['id']];
    }

    protected function beispiele(): array
    {
        return ['Setze bei Konzept 9 das Lunch-Concept 41.'];
    }

    protected function ausfuehren(Team $team, array $a): mixed
    {
        return ['konzept' => app(\Platform\FoodAlchemist\Services\KonfiguratorService::class)->konzeptAendern($team, self::id($a), $a)->toArray()];
    }
}
