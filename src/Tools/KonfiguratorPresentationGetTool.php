<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Models\Team;

/** Spec 94 · foodalchemist.konfigurator_presentation.GET */
class KonfiguratorPresentationGetTool extends KonfiguratorToolBasis
{
    protected function ressource(): string
    {
        return 'konfigurator_presentation';
    }

    protected function verb(): string
    {
        return 'GET';
    }

    protected function beschreibung(): string
    {
        return 'Status des Kundenlinks: live, URL, Ablauf, Design, Design geändert seit Veröffentlichung.';
    }

    protected function lesend(): bool
    {
        return true;
    }

    protected function felder(): array
    {
        return [['id' => ['type' => 'integer']], ['id']];
    }

    protected function beispiele(): array
    {
        return ['Ist der Link von Konfigurator 3 live?'];
    }

    protected function ausfuehren(Team $team, array $a): mixed
    {
        $k = app(\Platform\FoodAlchemist\Services\KonfiguratorService::class)->finde($team, self::id($a));
        $ref = $k->presentationPublicRef();

        return ['live' => $k->isPresentationLive(), 'url' => $ref ? route('foodalchemist.presentation.show', ['type' => 'konfigurator', 'token' => $ref]) : null,
            'expires_at' => $k->presentation_expires_at?->toIso8601String(), 'design' => $k->presentation_design,
            'design_geaendert' => app(\Platform\FoodAlchemist\Services\PresentationService::class)->designGeaendertSeitPublish($k)];
    }
}
