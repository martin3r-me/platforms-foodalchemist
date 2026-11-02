<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Models\Team;

/** Spec 94 · foodalchemist.leistung_vorlagen.POST */
class LeistungVorlagenPostTool extends KonfiguratorToolBasis
{
    protected function ressource(): string
    {
        return 'leistung_vorlagen';
    }

    protected function verb(): string
    {
        return 'POST';
    }

    protected function beschreibung(): string
    {
        return 'Leistungs-Vorlage anlegen: Kategorie, Bezeichnung, Mengenregel, Preis je Stück/Stunde, EK-Satz, Leistungs-Aufschlagsklasse oder fester VK mit Begründung.';
    }

    protected function felder(): array
    {
        return [self::regelSchema() + ['aktiv' => ['type' => 'boolean'], 'position' => ['type' => 'integer']], ['kategorie', 'bezeichnung', 'ek_satz']];
    }

    protected function beispiele(): array
    {
        return ['Lege die Vorlage Servicekraft an: 1 je 30 Gäste, je Stunde aus der Einsatzzeit, EK 32 €.'];
    }

    protected function ausfuehren(Team $team, array $a): mixed
    {
        return ['vorlage' => app(\Platform\FoodAlchemist\Services\LeistungVorlageService::class)->anlegen($team, $a)->toArray()];
    }
}
