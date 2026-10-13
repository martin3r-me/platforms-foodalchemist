<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Support\MachbarkeitKontext;

/**
 * Spec 82 §6 / §12 E — Machbarkeits-Check ohne KI: Braucht ein Rezept Equipment X und das Ziel-Team hat X nicht?
 *
 * Dreiwertig, nur ausdrücklich „nicht_vorhanden" warnt:
 *  - warnung        : mindestens ein benötigtes Equipment ist im Profil als nicht_vorhanden markiert.
 *  - hinweis        : keine Warnung, aber Equipment mit unbekanntem Zustand → „Profil unvollständig".
 *  - ok             : alles Benötigte ist vorhanden (Standardausstattung zählt ohne Gegenangabe als vorhanden).
 *  - ohne_angabe    : am Rezept (und seinen Unter-Rezepten) steht gar kein Equipment — das ist NICHT „machbar".
 * Die Equipment-Anforderung schließt Unter-Rezepte ein (Basisrezept-Regelwerk: max. 3 Ebenen).
 */
class MachbarkeitService
{
    public const MAX_EBENEN = 3;

    public function __construct(private readonly TeamProfilService $profil) {}

    /**
     * @param  list<int>  $recipeIds
     * @return list<array<string,mixed>>
     */
    public function pruefe(MachbarkeitKontext $kontext, array $recipeIds): array
    {
        $wirksam = $this->profil->wirksameAusstattung($kontext->ziel);
        $sichtbar = FoodAlchemistRecipe::visibleToTeam($kontext->ziel)->whereIn('id', $recipeIds)->pluck('name', 'id');
        $ausgabe = [];

        foreach (array_values(array_unique(array_map('intval', $recipeIds))) as $id) {
            if (! isset($sichtbar[$id])) {
                $ausgabe[] = ['recipe_id' => $id, 'name' => null, 'status' => 'nicht_sichtbar', 'warnungen' => [], 'hinweise' => [], 'equipment_geprueft' => 0];

                continue;
            }
            $ausgabe[] = $this->pruefeEins($id, (string) $sichtbar[$id], $wirksam);
        }

        return $ausgabe;
    }

    /** @param  array<int,string>  $wirksam */
    private function pruefeEins(int $recipeId, string $name, array $wirksam): array
    {
        $bedarf = $this->bedarf($recipeId);   // equipment_id => [name, aus rezept]
        $warnungen = [];
        $unbekannt = [];
        foreach ($bedarf as $eqId => $info) {
            $zustand = $wirksam[$eqId] ?? TeamProfilService::STATUS_UNBEKANNT;
            if ($zustand === TeamProfilService::STATUS_NICHT_VORHANDEN) {
                $warnungen[] = ['equipment_id' => $eqId, 'equipment' => $info['name'], 'benoetigt_in_rezept_id' => $info['recipe_id'], 'benoetigt_in_rezept' => $info['recipe']];
            } elseif ($zustand === TeamProfilService::STATUS_UNBEKANNT) {
                $unbekannt[] = ['equipment_id' => $eqId, 'equipment' => $info['name'], 'grund' => 'Profil unvollständig'];
            }
        }
        $status = match (true) {
            $bedarf === [] => 'ohne_angabe',
            $warnungen !== [] => 'warnung',
            $unbekannt !== [] => 'hinweis',
            default => 'ok',
        };

        return ['recipe_id' => $recipeId, 'name' => $name, 'status' => $status, 'warnungen' => $warnungen, 'hinweise' => $unbekannt, 'equipment_geprueft' => count($bedarf)];
    }

    /**
     * Equipment-Bedarf eines Rezepts samt Unter-Rezepten (Breitensuche, Zyklen-sicher, max. MAX_EBENEN Ebenen).
     *
     * @return array<int, array{name:string, recipe_id:int, recipe:string}>  erste Fundstelle je Equipment
     */
    private function bedarf(int $recipeId): array
    {
        $bedarf = [];
        $besucht = [];
        $ebene = [$recipeId];

        for ($tiefe = 0; $tiefe <= self::MAX_EBENEN && $ebene !== []; $tiefe++) {
            $ebene = array_values(array_diff($ebene, $besucht));
            if ($ebene === []) {
                break;
            }
            array_push($besucht, ...$ebene);

            $zeilen = DB::table('foodalchemist_recipe_equipment AS re')
                ->join('foodalchemist_vocab_kitchen_equipment AS e', 'e.id', '=', 're.equipment_id')
                ->join('foodalchemist_recipes AS r', 'r.id', '=', 're.recipe_id')
                ->whereIn('re.recipe_id', $ebene)->whereNull('e.deleted_at')
                ->get(['re.equipment_id', 'e.name AS equipment', 're.recipe_id', 'r.name AS recipe']);
            foreach ($zeilen as $z) {
                $bedarf[(int) $z->equipment_id] ??= ['name' => (string) $z->equipment, 'recipe_id' => (int) $z->recipe_id, 'recipe' => (string) $z->recipe];
            }

            $ebene = DB::table('foodalchemist_recipe_ingredients')
                ->whereIn('recipe_id', $ebene)->whereNotNull('referenced_recipe_id')->whereNull('deleted_at')
                ->pluck('referenced_recipe_id')->map(fn ($i) => (int) $i)->unique()->values()->all();
        }

        return $bedarf;
    }
}
