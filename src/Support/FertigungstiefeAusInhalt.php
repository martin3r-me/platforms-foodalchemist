<?php

namespace Platform\FoodAlchemist\Support;

use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;

/**
 * Fertigungstiefe aus dem INHALT eines Rezepts, nicht aus dem Lauf-Regler. Der Regler ist eine Erlaubnis
 * („Teil-Convenience darf“), kein Wert: demo Lauf 92 trug „teilfertig“ an allen acht Rezepten — auch an der
 * selbst gekochten Gemüsebrühe aus Rohware und Wasser (Review Berater, Punkt S).
 *
 * - teilfertig: mindestens eine Zeile ist Convenience-Ware (WG 13), TK-Gemüse/-Obst (WG 01/02) oder ein
 *   Unterrezept, das selbst teilfertig/convenience ist (z. B. „Jus: Rind (Zukauf)“).
 * - from_scratch: sonst. Gewürze, Öle, Essige (WG 10/11) und Brot sind Zutaten, keine Vorfertigung. Bewusst auch
 *   TK-Fisch und TK-Fleisch: das ist tiefgekühlte ROHWARE, die Küche verarbeitet sie vollständig — anders als
 *   TK-Gemüse, das blanchiert und geschnitten kommt.
 * - convenience setzt nur der Zukauf-Pfad ({@see \Platform\FoodAlchemist\Services\ZukaufBasisrezeptService::baue}).
 */
final class FertigungstiefeAusInhalt
{
    /** Eigene Abfrage — lädt nichts am Modell des Aufrufers um (Review Hans: load() mit Spaltenliste nahm den gp-Namen). */
    public static function ableiten(FoodAlchemistRecipe $recipe): string
    {
        return self::ausZutaten($recipe->ingredients()
            ->with(['gp:id,commodity_group_code,condition', 'referencedRecipe:id,production_depth'])
            ->get());
    }

    /** @param  iterable<\Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient>  $zutaten  mit gp + referencedRecipe */
    public static function ausZutaten(iterable $zutaten): string
    {
        foreach ($zutaten as $z) {
            $sub = $z->referencedRecipe;
            if ($sub !== null && in_array($sub->production_depth, ['teilfertig', 'convenience'], true)) {
                return 'teilfertig';
            }
            $gp = $z->gp;
            if ($gp === null) {
                continue;
            }
            $wg = (string) ($gp->commodity_group_code ?? '');
            if ($wg === '13' || (mb_strtoupper((string) $gp->condition) === 'TK' && in_array($wg, ['01', '02'], true))) {
                return 'teilfertig';
            }
        }

        return 'from_scratch';
    }
}
