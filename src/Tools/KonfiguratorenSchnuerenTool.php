<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Models\Team;

/** Spec 94 · foodalchemist.konfiguratoren.SCHNUEREN */
class KonfiguratorenSchnuerenTool extends KonfiguratorToolBasis
{
    protected function ressource(): string
    {
        return 'konfiguratoren';
    }

    protected function verb(): string
    {
        return 'SCHNUEREN';
    }

    protected function beschreibung(): string
    {
        return 'Pakete schnüren: Mengenregeln auf alle Pakete (oder paket_id) anwenden. Handwerte bleiben; Antwort mit Abweichungen Handwert gegen Regel.';
    }

    protected function felder(): array
    {
        return [['id' => ['type' => 'integer'], 'paket_id' => ['type' => 'integer']], ['id']];
    }

    protected function beispiele(): array
    {
        return ['Schnüre die Pakete von Konfigurator 3.'];
    }

    protected function ausfuehren(Team $team, array $a): mixed
    {
        $k = app(\Platform\FoodAlchemist\Services\KonfiguratorService::class)->finde($team, self::id($a));

        return app(\Platform\FoodAlchemist\Services\KonfiguratorPricingService::class)->schnueren($team, $k, ! empty($a['paket_id']) ? (int) $a['paket_id'] : null);
    }
}
