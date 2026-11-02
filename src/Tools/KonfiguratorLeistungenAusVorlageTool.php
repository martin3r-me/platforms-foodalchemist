<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Models\Team;

/** Spec 94 · foodalchemist.konfigurator_leistungen.AUS_VORLAGE */
class KonfiguratorLeistungenAusVorlageTool extends KonfiguratorToolBasis
{
    protected function ressource(): string
    {
        return 'konfigurator_leistungen';
    }

    protected function verb(): string
    {
        return 'AUS_VORLAGE';
    }

    protected function beschreibung(): string
    {
        return 'Vorlagen als Kopie übernehmen (mehrere). Bezüge zwischen Vorlagen werden auf die übernommenen Leistungen umgehängt.';
    }

    protected function felder(): array
    {
        return [['konfigurator_id' => ['type' => 'integer'], 'vorlage_ids' => ['type' => 'array', 'items' => ['type' => 'integer']], 'konfigurator_konzept_id' => ['type' => 'integer'], 'gilt_fuer' => ['type' => 'string', 'enum' => ['lunch', 'dinner', 'beide']]], ['konfigurator_id', 'vorlage_ids']];
    }

    protected function beispiele(): array
    {
        return ['Übernimm die Vorlagen 1, 2, 3 als Grundausstattung in Konfigurator 3.'];
    }

    protected function ausfuehren(Team $team, array $a): mixed
    {
        $erg = app(\Platform\FoodAlchemist\Services\KonfiguratorService::class)->ausVorlagen($team, self::id($a, 'konfigurator_id'),
            array_map('intval', (array) $a['vorlage_ids']), ! empty($a['konfigurator_konzept_id']) ? (int) $a['konfigurator_konzept_id'] : null, $a['gilt_fuer'] ?? null);

        return ['leistungen' => array_map(fn ($l) => $l->toArray(), $erg['leistungen']), 'warnungen' => $erg['warnungen']];
    }
}
