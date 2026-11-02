<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Models\Team;

/** Spec 94 · foodalchemist.konfigurator_leistungen.POST */
class KonfiguratorLeistungenPostTool extends KonfiguratorToolBasis
{
    protected function ressource(): string
    {
        return 'konfigurator_leistungen';
    }

    protected function verb(): string
    {
        return 'POST';
    }

    protected function beschreibung(): string
    {
        return 'Leistung frei anlegen (EK/VK von Hand). konfigurator_konzept_id leer = Grundausstattung; gilt_fuer und ersetzt_leistung_id für Zusatz-Leistungen.';
    }

    protected function felder(): array
    {
        return [['konfigurator_id' => ['type' => 'integer']] + self::regelSchema() + self::zuordnungSchema(), ['konfigurator_id', 'kategorie', 'bezeichnung', 'ek_satz']];
    }

    protected function beispiele(): array
    {
        return ['Lege bei Konfigurator 3 einen LKW an: Stufen bis 199 → 1, bis 799 → 2, sonst 3; EK 808 €.'];
    }

    protected function ausfuehren(Team $team, array $a): mixed
    {
        return ['leistung' => app(\Platform\FoodAlchemist\Services\KonfiguratorService::class)->leistungHinzu($team, self::id($a, 'konfigurator_id'), $a)->toArray()];
    }
}
