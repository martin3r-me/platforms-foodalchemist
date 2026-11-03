<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Models\Team;

/** Spec 94 · foodalchemist.konfigurator_paket_mengen.PUT */
class KonfiguratorPaketMengenPutTool extends KonfiguratorToolBasis
{
    protected function ressource(): string
    {
        return 'konfigurator_paket_mengen';
    }

    protected function verb(): string
    {
        return 'PUT';
    }

    protected function beschreibung(): string
    {
        return 'Handwert in der Mengen-Matrix setzen (anzahl) oder ohne anzahl zurück auf die Regel. konfigurator_konzept_id leer = gilt für alle Hauptkonzepte.';
    }

    protected function felder(): array
    {
        return [['paket_id' => ['type' => 'integer'], 'leistung_id' => ['type' => 'integer'], 'konfigurator_konzept_id' => ['type' => 'integer'], 'anzahl' => ['type' => 'number', 'description' => 'Handwert; weglassen = zurück auf die Regel'], 'notiz' => ['type' => 'string']], ['paket_id', 'leistung_id']];
    }

    protected function beispiele(): array
    {
        return ['Setze in Paket 5 die Servicekräfte (Leistung 21) auf 7.'];
    }

    protected function ausfuehren(Team $team, array $a): mixed
    {
        $menge = app(\Platform\FoodAlchemist\Services\KonfiguratorService::class)->mengeSetzen($team, self::id($a, 'paket_id'), self::id($a, 'leistung_id'),
            ! empty($a['konfigurator_konzept_id']) ? (int) $a['konfigurator_konzept_id'] : null, isset($a['anzahl']) ? (float) $a['anzahl'] : null, $a['notiz'] ?? null);

        return ['menge' => $menge->toArray()];
    }
}
