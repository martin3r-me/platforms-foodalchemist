<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Models\Team;

/** Spec 94 · foodalchemist.konfiguratoren.POST */
class KonfiguratorenPostTool extends KonfiguratorToolBasis
{
    protected function ressource(): string
    {
        return 'konfiguratoren';
    }

    protected function verb(): string
    {
        return 'POST';
    }

    protected function beschreibung(): string
    {
        return 'Konfigurator anlegen (Name, Kundentitel, Ort, Einsatzzeiten, Kombi-Rabatt, Toleranz, Rundung, Gültigkeit).';
    }

    protected function felder(): array
    {
        return [self::kopfSchema(), ['name']];
    }

    protected function beispiele(): array
    {
        return ['Lege den Konfigurator Diva.licious 2026 an, Ort Köln, Einsatz 8/8/14 Stunden.'];
    }

    protected function ausfuehren(Team $team, array $a): mixed
    {
        return ['konfigurator' => app(\Platform\FoodAlchemist\Services\KonfiguratorService::class)->anlegen($team, $a)->makeHidden(['presentation_snapshot_json'])->toArray()];
    }
}
