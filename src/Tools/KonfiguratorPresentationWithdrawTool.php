<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Models\Team;

/** Spec 94 · foodalchemist.konfigurator_presentation.WITHDRAW */
class KonfiguratorPresentationWithdrawTool extends KonfiguratorToolBasis
{
    protected function ressource(): string
    {
        return 'konfigurator_presentation';
    }

    protected function verb(): string
    {
        return 'WITHDRAW';
    }

    protected function beschreibung(): string
    {
        return 'Kundenlink zurückziehen (Token bleibt, Wieder-Veröffentlichung möglich).';
    }

    protected function felder(): array
    {
        return [['id' => ['type' => 'integer']], ['id']];
    }

    protected function beispiele(): array
    {
        return ['Ziehe den Link von Konfigurator 3 zurück.'];
    }

    protected function ausfuehren(Team $team, array $a): mixed
    {
        app(\Platform\FoodAlchemist\Services\PresentationService::class)->withdraw($team, \Platform\FoodAlchemist\Services\PresentationService::TYPE_KONFIGURATOR, self::id($a));

        return ['zurueckgezogen' => (int) $a['id']];
    }
}
