<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Models\Team;

/** Spec 94 · foodalchemist.konfiguratoren.PAKETE_VORSCHLAG_UEBERNEHMEN */
class KonfiguratorenPaketeVorschlagUebernehmenTool extends KonfiguratorToolBasis
{
    protected function ressource(): string
    {
        return 'konfiguratoren';
    }

    protected function verb(): string
    {
        return 'PAKETE_VORSCHLAG_UEBERNEHMEN';
    }

    protected function beschreibung(): string
    {
        return 'Pakete ersetzen (zum Beispiel mit dem Vorschlag) und schnüren. Handwerte der alten Pakete gehen verloren.';
    }

    protected function felder(): array
    {
        return [['id' => ['type' => 'integer'], 'pakete' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['von_pax' => ['type' => 'integer'], 'bis_pax' => ['type' => 'integer']]]]], ['id', 'pakete']];
    }

    protected function beispiele(): array
    {
        return ['Übernimm die Pakete 50–74, 75–99, 100–199 für Konfigurator 3.'];
    }

    protected function ausfuehren(Team $team, array $a): mixed
    {
        return ['pakete' => app(\Platform\FoodAlchemist\Services\KonfiguratorService::class)->vorschlagUebernehmen($team, self::id($a), (array) $a['pakete'])];
    }
}
