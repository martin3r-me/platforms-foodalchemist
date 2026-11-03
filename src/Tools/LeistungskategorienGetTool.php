<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Models\Team;

/** Spec 94b · foodalchemist.leistungskategorien.GET — wählbare Leistungskategorien (Vokabular, team-pflegbar). */
class LeistungskategorienGetTool extends KonfiguratorToolBasis
{
    protected function ressource(): string
    {
        return 'leistungskategorien';
    }

    protected function verb(): string
    {
        return 'GET';
    }

    protected function lesend(): bool
    {
        return true;
    }

    protected function beschreibung(): string
    {
        return 'Liefert die Kategorien, die dieses Team für Leistungs-Vorlagen und Konfigurator-Leistungen wählen kann (slug, name), '
            . 'in Reihenfolge. Gepflegt unter Einstellungen → Leistungskategorien; der slug gehört in das Feld kategorie.';
    }

    protected function felder(): array
    {
        return [[], []];
    }

    protected function ausfuehren(Team $team, array $a): mixed
    {
        $svc = app(\Platform\FoodAlchemist\Services\Konfigurator\LeistungsKategorieService::class);

        return ['kategorien' => $svc->waehlbar($team)->map(fn ($k) => ['slug' => $k->slug, 'name' => $k->name])->values()->all()];
    }
}
