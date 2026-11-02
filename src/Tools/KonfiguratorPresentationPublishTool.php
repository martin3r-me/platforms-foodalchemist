<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Models\Team;

/** Spec 94 · foodalchemist.konfigurator_presentation.PUBLISH */
class KonfiguratorPresentationPublishTool extends KonfiguratorToolBasis
{
    protected function ressource(): string
    {
        return 'konfigurator_presentation';
    }

    protected function verb(): string
    {
        return 'PUBLISH';
    }

    protected function beschreibung(): string
    {
        return 'Kundenlink veröffentlichen (Preistabelle wird neu gerechnet, kein EK im Link). expires_at, sonst gueltig_bis des Konfigurators; design, slug optional.';
    }

    protected function felder(): array
    {
        return [['id' => ['type' => 'integer'], 'expires_at' => ['type' => 'string'], 'design' => ['type' => 'string'], 'slug' => ['type' => 'string']], ['id']];
    }

    protected function beispiele(): array
    {
        return ['Veröffentliche Konfigurator 3 bis 31.12.2026.'];
    }

    protected function ausfuehren(Team $team, array $a): mixed
    {
        $svc = app(\Platform\FoodAlchemist\Services\KonfiguratorService::class);
        $k = $svc->finde($team, self::id($a));
        $ablauf = $a['expires_at'] ?? $k->gueltig_bis?->endOfDay()->toIso8601String();
        $erg = app(\Platform\FoodAlchemist\Services\PresentationService::class)->publish($team, \Platform\FoodAlchemist\Services\PresentationService::TYPE_KONFIGURATOR, $k->id,
            array_filter(['expires_at' => $ablauf, 'design' => $a['design'] ?? ($k->presentation_design ?: 'konfigurator'), 'slug' => $a['slug'] ?? null], fn ($v) => $v !== null) + ['price_mode' => 'auto']);
        $svc->aendern($team, $k->id, ['status' => 'live']);

        return $erg;
    }
}
