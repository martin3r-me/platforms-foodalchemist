<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Models\Team;

/** Spec 94 · foodalchemist.leistung_vorlagen.GET */
class LeistungVorlagenGetTool extends KonfiguratorToolBasis
{
    protected function ressource(): string
    {
        return 'leistung_vorlagen';
    }

    protected function verb(): string
    {
        return 'GET';
    }

    protected function beschreibung(): string
    {
        return 'Leistungs-Vorlagen des Teams und der Team-Kette (geerbte lesbar). Mit id eine Vorlage; sonst Liste, optional kategorie/suche/nur_aktiv.';
    }

    protected function lesend(): bool
    {
        return true;
    }

    protected function felder(): array
    {
        return [['id' => ['type' => 'integer'], 'kategorie' => ['type' => 'string'], 'suche' => ['type' => 'string'], 'nur_aktiv' => ['type' => 'boolean']], []];
    }

    protected function beispiele(): array
    {
        return ['Zeige alle Personal-Vorlagen.'];
    }

    protected function ausfuehren(Team $team, array $a): mixed
    {
        $svc = app(\Platform\FoodAlchemist\Services\LeistungVorlageService::class);
        if (! empty($a['id'])) {
            $v = $svc->finde($team, (int) $a['id']);

            return ['vorlage' => $v->toArray() + ['eigen' => $v->isOwnedBy($team)]];
        }

        return ['vorlagen' => $svc->liste($team, $a['kategorie'] ?? null, $a['suche'] ?? null, (bool) ($a['nur_aktiv'] ?? false))
            ->map(fn ($v) => $v->toArray() + ['eigen' => $v->isOwnedBy($team)])->values()->all()];
    }
}
