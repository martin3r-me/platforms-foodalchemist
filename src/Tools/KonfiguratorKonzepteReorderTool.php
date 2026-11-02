<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Models\Team;

/** Spec 94 · foodalchemist.konfigurator_konzepte.REORDER */
class KonfiguratorKonzepteReorderTool extends KonfiguratorToolBasis
{
    protected function ressource(): string
    {
        return 'konfigurator_konzepte';
    }

    protected function verb(): string
    {
        return 'REORDER';
    }

    protected function beschreibung(): string
    {
        return 'Reihenfolge der Konzepte setzen: ids in der gewünschten Reihenfolge.';
    }

    protected function felder(): array
    {
        return [['konfigurator_id' => ['type' => 'integer'], 'ids' => ['type' => 'array', 'items' => ['type' => 'integer']]], ['konfigurator_id', 'ids']];
    }

    protected function beispiele(): array
    {
        return ['Ordne die Konzepte 9, 7, 8.'];
    }

    protected function ausfuehren(Team $team, array $a): mixed
    {
        $svc = app(\Platform\FoodAlchemist\Services\KonfiguratorService::class);
        $k = $svc->finde($team, self::id($a, 'konfigurator_id'));
        $eigene = $k->konzepte()->pluck('id')->map(fn ($id) => (int) $id)->all();
        foreach (array_values(array_map('intval', (array) $a['ids'])) as $pos => $id) {
            if (! in_array($id, $eigene, true)) {
                throw new \InvalidArgumentException("Konzept {$id} gehört nicht zu diesem Konfigurator.");
            }
            $svc->konzeptAendern($team, $id, ['position' => $pos]);
        }

        return ['reihenfolge' => array_map('intval', (array) $a['ids'])];
    }
}
