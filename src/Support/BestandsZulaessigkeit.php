<?php

namespace Platform\FoodAlchemist\Support;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;

/**
 * EINE Entscheidung, ob ein bestehendes Basisrezept (Bestand) an eine Zeile gebunden werden darf. Dreimal galt eine
 * Regel an einer Stelle, ein zweiter Bindungsweg gab eine andere Antwort (syncIngredients, bestSubrecipeMatch,
 * validiereProposedSub — zuletzt demo Lauf 95: der Plan lehnte „Jus: Rind (Zukauf)“ ab, der Matcher band ihn trotzdem).
 * Jeder Weg, der aus einem Treffer bindet, ruft diese Funktion; der Architekturtest hält das fest.
 *
 * Reihenfolge der Prüfungen (der erste Grund gewinnt):
 *  1. Freigabe — Bestand = nur `approved` (Entscheid 09.10.); entfällt für eigene Import-Bausteine (`vertraut`).
 *  2. Fertigung — Zukauf-Bestand (production_depth convenience), wenn der Lauf „from scratch“ verlangt
 *     ({@see BestandsPassung::fertigungGrund}); ohne Lauf-Regler greift sie nicht.
 *  3. Passung — Typ-Gruppe, Bestandteile und harte Diät ({@see BestandsPassung::grund}). Mit `bestandteile: false`
 *     (Matcher-Treffer, Haus-Zuordnung, Import) nur die Diät.
 *
 * Nicht hier: Zyklus-Schutz (braucht das Elternrezept — {@see \Platform\FoodAlchemist\Services\RecipeRecomputeService::pruefeVerknuepfung})
 * und die Einkaufsform-Sperre des Generators (eine Zeile „TK-Erbsen“ ist Ware, kein Basisrezept).
 */
final class BestandsZulaessigkeit
{
    /** Spalten, die {@see self::pruefe} am Kandidaten liest. */
    public const SPALTEN = ['id', 'name', 'status', 'team_id', 'spec_is_vegan', 'spec_is_vegetarian', 'production_depth'];

    /**
     * @param  array{vertraut?: bool, bestandteile?: bool, diaet_hart?: list<string>, regler?: ?string}  $kontext
     * @return string|null  Grund der Ablehnung, null = darf gebunden werden
     */
    public static function pruefe(Team $team, string $zeile, FoodAlchemistRecipe $kandidat, array $kontext = []): ?string
    {
        if (! ($kontext['vertraut'] ?? false)) {
            $status = $kandidat->status instanceof \BackedEnum ? $kandidat->status->value : (string) $kandidat->status;
            if ($status !== 'approved') {
                return "noch nicht freigegeben (Status {$status})";
            }
        }
        $fertigung = BestandsPassung::fertigungGrund($team, $zeile, $kandidat->production_depth, $kontext['regler'] ?? null);
        if ($fertigung !== null) {
            return $fertigung;
        }

        return BestandsPassung::grund(
            ($kontext['bestandteile'] ?? true) ? $zeile : '',
            (string) $kandidat->name,
            $kandidat->spec_is_vegan !== null ? (bool) $kandidat->spec_is_vegan : null,
            $kandidat->spec_is_vegetarian !== null ? (bool) $kandidat->spec_is_vegetarian : null,
            array_values(array_filter((array) ($kontext['diaet_hart'] ?? []), 'is_string')),
        );
    }

    /** Kandidat im Bestands-Scope (sichtbar, Basisrezept) laden — ohne Status-Filter, den prüft {@see self::pruefe}. */
    public static function lade(Team $team, int $id): ?FoodAlchemistRecipe
    {
        return $id > 0 ? FoodAlchemistRecipe::query()->visibleToTeam($team)->basis()->whereKey($id)->first(self::SPALTEN) : null;
    }
}
