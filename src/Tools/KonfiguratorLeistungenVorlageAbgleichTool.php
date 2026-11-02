<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Models\Team;

/** Spec 94 · foodalchemist.konfigurator_leistungen.VORLAGE_ABGLEICH */
class KonfiguratorLeistungenVorlageAbgleichTool extends KonfiguratorToolBasis
{
    protected function ressource(): string
    {
        return 'konfigurator_leistungen';
    }

    protected function verb(): string
    {
        return 'VORLAGE_ABGLEICH';
    }

    protected function beschreibung(): string
    {
        return 'Ohne leistung_id: welche Leistungen eine geänderte Vorlage haben (konfigurator_id). Mit leistung_id: Werte der Vorlage neu übernehmen.';
    }

    protected function felder(): array
    {
        return [['konfigurator_id' => ['type' => 'integer'], 'leistung_id' => ['type' => 'integer']], []];
    }

    protected function beispiele(): array
    {
        return ['Welche Leistungen in Konfigurator 3 haben eine geänderte Vorlage?'];
    }

    protected function ausfuehren(Team $team, array $a): mixed
    {
        $svc = app(\Platform\FoodAlchemist\Services\KonfiguratorService::class);
        if (! empty($a['leistung_id'])) {
            return ['leistung' => $svc->vorlageAbgleich($team, (int) $a['leistung_id'])->toArray()];
        }

        return ['geaendert' => $svc->vorlageGeaendert($team, self::id($a, 'konfigurator_id'))];
    }
}
