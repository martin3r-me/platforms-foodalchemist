<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Models\Team;

/** Spec 94 · foodalchemist.leistung_vorlagen.DELETE */
class LeistungVorlagenDeleteTool extends KonfiguratorToolBasis
{
    protected function ressource(): string
    {
        return 'leistung_vorlagen';
    }

    protected function verb(): string
    {
        return 'DELETE';
    }

    protected function beschreibung(): string
    {
        return 'Eigene Leistungs-Vorlage löschen. Kopien in Konfiguratoren bleiben unverändert.';
    }

    protected function felder(): array
    {
        return [['id' => ['type' => 'integer']], ['id']];
    }

    protected function beispiele(): array
    {
        return ['Lösche Vorlage 12.'];
    }

    protected function ausfuehren(Team $team, array $a): mixed
    {
        app(\Platform\FoodAlchemist\Services\LeistungVorlageService::class)->loeschen($team, self::id($a));

        return ['geloescht' => (int) $a['id']];
    }
}
