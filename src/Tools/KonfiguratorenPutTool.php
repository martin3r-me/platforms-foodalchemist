<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Models\Team;

/** Spec 94 · foodalchemist.konfiguratoren.PUT */
class KonfiguratorenPutTool extends KonfiguratorToolBasis
{
    protected function ressource(): string
    {
        return 'konfiguratoren';
    }

    protected function verb(): string
    {
        return 'PUT';
    }

    protected function beschreibung(): string
    {
        return 'Kopf und Regeln eines eigenen Konfigurators ändern (Status entwurf|live|archiv, Einsatzzeiten, Kombi-Rabatt, Rundung).';
    }

    protected function felder(): array
    {
        return [['id' => ['type' => 'integer']] + self::kopfSchema(), ['id']];
    }

    protected function beispiele(): array
    {
        return ['Setze bei Konfigurator 3 den Kombi-Rabatt auf 5 %.'];
    }

    protected function ausfuehren(Team $team, array $a): mixed
    {
        return ['konfigurator' => app(\Platform\FoodAlchemist\Services\KonfiguratorService::class)->aendern($team, self::id($a), $a)->makeHidden(['presentation_snapshot_json'])->toArray()];
    }
}
