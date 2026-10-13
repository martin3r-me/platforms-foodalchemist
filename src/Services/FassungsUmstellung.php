<?php

namespace Platform\FoodAlchemist\Services;

use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;

/**
 * Spec 82 §12 B · DIE Mechanik „Zutat von einer alten auf die neue Fassung umhängen“. Gebraucht vom Übernehmen im Angebot (4b), vom automatischen Folgen
 * eigener Rezepte (4d) und vom Übernehmen am Rezept (4d). Hängt nur Zutatenzeilen EIGENER (nicht-Fassungs-) Rezepte des Teams um; rechnet nicht selbst.
 */
class FassungsUmstellung
{
    private const MAX_TIEFE = 8;

    /**
     * Im Baum eines Gerichts: das Gericht und alle seine eigenen Unter-Rezepte des Teams, deren Zutaten auf `$alt` zeigen, zeigen danach auf `$neu`.
     *
     * @return list<int> die Rezepte, an denen sich etwas geändert hat
     */
    public function imBaum(FoodAlchemistRecipe $wurzel, int $teamId, int $alt, int $neu): array
    {
        $geaendert = [];
        $besucht = [];
        $lauf = function (FoodAlchemistRecipe $r, int $tiefe) use (&$lauf, &$besucht, &$geaendert, $teamId, $alt, $neu) {
            if (isset($besucht[$r->id]) || $tiefe > self::MAX_TIEFE || $r->ist_fassung) {
                return;
            }
            $besucht[$r->id] = true;
            if ((int) $r->team_id === $teamId && $this->einRezept($r, $alt, $neu) > 0) {
                $geaendert[] = (int) $r->id;
            }
            foreach (FoodAlchemistRecipe::mitFassungen()->whereIn('id', $r->ingredients()->whereNotNull('referenced_recipe_id')->pluck('referenced_recipe_id'))->get() as $kind) {
                $lauf($kind, $tiefe + 1);
            }
        };
        $lauf($wurzel, 0);

        return $geaendert;
    }

    /** Nur dieses Rezept: seine Zutatenzeilen auf `$alt` zeigen danach auf `$neu`. @return int Anzahl umgehängter Zeilen */
    public function einRezept(FoodAlchemistRecipe $rezept, int $alt, int $neu): int
    {
        return $rezept->ingredients()->where('referenced_recipe_id', $alt)->update(['referenced_recipe_id' => $neu]);
    }
}
