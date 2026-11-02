<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Models\Team;

/** Spec 94 · foodalchemist.leistung_vorlagen.PUT */
class LeistungVorlagenPutTool extends KonfiguratorToolBasis
{
    protected function ressource(): string
    {
        return 'leistung_vorlagen';
    }

    protected function verb(): string
    {
        return 'PUT';
    }

    protected function beschreibung(): string
    {
        return 'Eigene Leistungs-Vorlage ändern (geerbte nicht). Nur übergebene Felder ändern sich; der VK wird neu bestimmt.';
    }

    protected function felder(): array
    {
        return [['id' => ['type' => 'integer']] + self::regelSchema() + ['aktiv' => ['type' => 'boolean'], 'position' => ['type' => 'integer']], ['id']];
    }

    protected function beispiele(): array
    {
        return ['Setze bei Vorlage 12 den EK-Satz auf 34.'];
    }

    protected function ausfuehren(Team $team, array $a): mixed
    {
        return ['vorlage' => app(\Platform\FoodAlchemist\Services\LeistungVorlageService::class)->aendern($team, self::id($a), $a)->toArray()];
    }
}
