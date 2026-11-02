<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Models\Team;

/** Spec 94 · foodalchemist.konfiguratoren.GET */
class KonfiguratorenGetTool extends KonfiguratorToolBasis
{
    protected function ressource(): string
    {
        return 'konfiguratoren';
    }

    protected function verb(): string
    {
        return 'GET';
    }

    protected function beschreibung(): string
    {
        return 'Konfiguratoren lesen. Ohne id: Liste. Mit id: Kopf, Konzepte, Leistungen (mit EK/VK), Pakete mit Mengen-Matrix, nur intern.';
    }

    protected function lesend(): bool
    {
        return true;
    }

    protected function felder(): array
    {
        return [['id' => ['type' => 'integer']], []];
    }

    protected function beispiele(): array
    {
        return ['Zeige Konfigurator 3 mit Leistungen und Paketen.'];
    }

    protected function ausfuehren(Team $team, array $a): mixed
    {
        if (empty($a['id'])) {
            return ['konfiguratoren' => \Platform\FoodAlchemist\Models\FoodAlchemistKonfigurator::visibleToTeam($team)->orderByDesc('updated_at')
                ->get(['id', 'name', 'kunden_titel', 'status', 'ort', 'gueltig_bis', 'presentation_enabled'])->toArray()];
        }
        $k = app(\Platform\FoodAlchemist\Services\KonfiguratorService::class)->finde($team, (int) $a['id']);
        $k->load(['konzepte', 'leistungen', 'pakete.mengen']);

        return ['konfigurator' => $k->makeHidden(['presentation_snapshot_json'])->toArray()];
    }
}
