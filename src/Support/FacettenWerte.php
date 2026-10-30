<?php

namespace Platform\FoodAlchemist\Support;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistEinsatzmoment;
use Platform\FoodAlchemist\Models\FoodAlchemistEventtyp;
use Platform\FoodAlchemist\Models\FoodAlchemistSaison;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;

/**
 * Facetten-Vokabular (Servierform, Eventtyp, Einsatzmoment, Saison) der Concepter- und Format-Browser und ihrer MCP-Listen
 * (Spec 91 PR 5): aktive Werte in Pflege-Reihenfolge. Vorher fragte jeder Browser selbst ab, MCP kannte die ids nicht.
 */
final class FacettenWerte
{
    /** @return array{facetteServierformen: \Illuminate\Support\Collection, facetteEventtypen: \Illuminate\Support\Collection, facetteMomente: \Illuminate\Support\Collection, facetteSaisons: \Illuminate\Support\Collection} */
    public static function fuer(Team $team): array
    {
        return [
            'facetteServierformen' => FoodAlchemistServierform::where('is_inactive', false)->orderBy('sort_order')->get(['id', 'code', 'label']),
            'facetteEventtypen' => FoodAlchemistEventtyp::visibleToTeam($team)->where('is_inactive', false)->orderBy('sort_order')->get(['id', 'name']),
            'facetteMomente' => FoodAlchemistEinsatzmoment::visibleToTeam($team)->where('is_inactive', false)->orderBy('sort_order')->get(['id', 'name']),
            'facetteSaisons' => FoodAlchemistSaison::visibleToTeam($team)->where('is_inactive', false)->orderBy('sort_order')->get(['id', 'name']),
        ];
    }

    /** MCP-Form: je Facette die Liste {id, name}; Schlüssel = Filter-Parameter der Listen-Tools. */
    public static function alsParameter(Team $team): array
    {
        $w = self::fuer($team);

        return [
            'servierform' => $w['facetteServierformen']->map(fn ($s) => ['id' => (int) $s->id, 'name' => $s->label])->all(),
            'eventtyp' => $w['facetteEventtypen']->map(fn ($e) => ['id' => (int) $e->id, 'name' => $e->name])->all(),
            'einsatzmoment' => $w['facetteMomente']->map(fn ($m) => ['id' => (int) $m->id, 'name' => $m->name])->all(),
            'saison' => $w['facetteSaisons']->map(fn ($s) => ['id' => (int) $s->id, 'name' => $s->name])->all(),
        ];
    }
}
