<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Models\Team;

/** Spec 94 · foodalchemist.konfiguratoren.PAKETE_VORSCHLAGEN */
class KonfiguratorenPaketeVorschlagenTool extends KonfiguratorToolBasis
{
    protected function ressource(): string
    {
        return 'konfiguratoren';
    }

    protected function verb(): string
    {
        return 'PAKETE_VORSCHLAGEN';
    }

    protected function beschreibung(): string
    {
        return 'Paketgrenzen vorschlagen (deterministisch, ohne KI): Pakete so breit wie möglich innerhalb der Toleranz. Schreibt nichts.';
    }

    protected function lesend(): bool
    {
        return true;
    }

    protected function felder(): array
    {
        return [['id' => ['type' => 'integer'], 'von' => ['type' => 'integer'], 'bis' => ['type' => 'integer'], 'toleranz_pct' => ['type' => 'number'], 'raster' => ['type' => 'integer'], 'mindestbreite' => ['type' => 'integer']], ['id', 'von', 'bis']];
    }

    protected function beispiele(): array
    {
        return ['Schlage Pakete für 50 bis 1200 Gäste vor.'];
    }

    protected function ausfuehren(Team $team, array $a): mixed
    {
        $k = app(\Platform\FoodAlchemist\Services\KonfiguratorService::class)->finde($team, self::id($a));
        if ((int) $a['von'] < 1 || (int) $a['bis'] < (int) $a['von']) {
            throw new \InvalidArgumentException('von ab 1, bis nicht unter von.');
        }

        return ['vorschlag' => app(\Platform\FoodAlchemist\Services\KonfiguratorPricingService::class)->paketgrenzenVorschlagen($k, (int) $a['von'], (int) $a['bis'],
            isset($a['toleranz_pct']) ? (float) $a['toleranz_pct'] : null, max(1, (int) ($a['raster'] ?? 25)), max(1, (int) ($a['mindestbreite'] ?? 25)))];
    }
}
