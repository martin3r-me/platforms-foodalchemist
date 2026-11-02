<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Models\Team;

/** Spec 94 · foodalchemist.konfigurator_konzepte.POST */
class KonfiguratorKonzeptePostTool extends KonfiguratorToolBasis
{
    protected function ressource(): string
    {
        return 'konfigurator_konzepte';
    }

    protected function verb(): string
    {
        return 'POST';
    }

    protected function beschreibung(): string
    {
        return 'Konzept verknüpfen: rolle=haupt mit lunch_concept_id und/oder dinner_concept_id, oder rolle=addon mit concept_id, abschnitt, addon_tageszeit, gebunden_an_id.';
    }

    protected function felder(): array
    {
        return [['konfigurator_id' => ['type' => 'integer']] + self::konzeptSchema(), ['konfigurator_id']];
    }

    protected function beispiele(): array
    {
        return ['Verknüpfe Concept 40 als Dinner-Hauptkonzept Culture mit Konfigurator 3.'];
    }

    protected function ausfuehren(Team $team, array $a): mixed
    {
        return ['konzept' => app(\Platform\FoodAlchemist\Services\KonfiguratorService::class)->konzeptHinzu($team, self::id($a, 'konfigurator_id'), $a)->toArray()];
    }
}
