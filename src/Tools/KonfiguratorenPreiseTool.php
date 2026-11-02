<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Models\Team;

/** Spec 94 · foodalchemist.konfiguratoren.PREISE */
class KonfiguratorenPreiseTool extends KonfiguratorToolBasis
{
    protected function ressource(): string
    {
        return 'konfiguratoren';
    }

    protected function verb(): string
    {
        return 'PREISE';
    }

    protected function beschreibung(): string
    {
        return 'Interne Preisvorschau: je Paket und Konzept/Tageszeit/Add-on All-in pro Person, EK, DB, genauer Preis und DB an Unter- und Obergrenze, Warnungen.';
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
        return ['Zeige die Preise von Konfigurator 3.'];
    }

    protected function ausfuehren(Team $team, array $a): mixed
    {
        $k = app(\Platform\FoodAlchemist\Services\KonfiguratorService::class)->finde($team, self::id($a));

        return app(\Platform\FoodAlchemist\Services\KonfiguratorPricingService::class)->matrix($k);
    }
}
