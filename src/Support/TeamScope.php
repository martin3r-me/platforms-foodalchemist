<?php

namespace Platform\FoodAlchemist\Support;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;

/**
 * Zentrale Mandanten-Regel — Master-Vererbung (Entscheid Dominique 2026-07-12):
 * BHG.DIGITAL (Root) ist Master; Kind-Teams erben dessen Katalog + den globalen Seed (team_id NULL).
 *
 *  - Sichtbar   = team_id IS NULL (globaler Seed) ODER team_id ∈ Ancestry (eigenes + Master-Kette)
 *  - Editierbar = nur das eigene Team (Master/Seed sind für Kind-Teams read-only)
 *
 * Für die Eloquent-Modelle erledigt das der Trait BelongsToTeamHierarchy (scopeVisibleToTeam /
 * isOwnedBy). Dieser Helper ist das Pendant für ROHE DB::table-Queries (Livewire-Settings,
 * Knowledge-Service), wo die Model-Scopes nicht greifen — damit die Regel an EINER Stelle lebt.
 */
final class TeamScope
{
    /** Ancestry-IDs (eigenes Team zuerst … Root) oder [] wenn kein Team. Quelle: Trait-Cache. */
    public static function ancestryIds(?Team $team): array
    {
        return $team === null ? [] : FoodAlchemistGp::teamAncestryIds($team);
    }

    /**
     * Sichtbarkeits-Filter für rohe Query-Builder: NULL (globaler Seed) ODER Ancestry.
     * Gruppiert in einer Klammer, damit nachfolgende where() nicht am OR hängen.
     *
     * @template T of \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder
     * @param  T  $query
     * @return T
     */
    public static function applyVisible($query, string $teamIdColumn, ?Team $team)
    {
        $ids = self::ancestryIds($team);

        return $query->where(function ($q) use ($teamIdColumn, $ids) {
            $q->whereNull($teamIdColumn);
            if ($ids !== []) {
                $q->orWhereIn($teamIdColumn, $ids);
            }
        });
    }

    /** Schreibrecht: nur das Besitzer-Team; Master/Seed (team_id NULL) + Fremd-Teams sind read-only. */
    public static function owns(mixed $rowTeamId, ?Team $team): bool
    {
        return $team !== null && $rowTeamId !== null && (int) $rowTeamId === (int) $team->id;
    }

    /**
     * Die dritte Zugriffsart: einen REFERENZIERTEN Fremdschlüssel autorisieren (MVP-044/050).
     *
     * Nicht zu verwechseln mit `owns()`. Geprüft wird SICHTBARKEIT, nicht Eigentum — genau
     * darin liegt der Zweck der Master-Vererbung: ein Kind-Team muss die geerbte Kategorie,
     * Klasse und Aufschlagsklasse am EIGENEN Rezept verwenden dürfen. Wer hier versehentlich
     * `owns()` einsetzt, macht den Master-Katalog unbenutzbar.
     *
     * Anlass: UI-Selects waren gescopt, die Services übernahmen die ID danach roh aus einem
     * client-kontrollierten Formular (`array_intersect_key` über eine Whitelist). Damit war die
     * Auswahlliste die einzige „Prüfung" — und die liegt im Browser.
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $modelClass  muss BelongsToTeamHierarchy nutzen
     * @param  mixed  $id  rohe ID aus dem Formular; '', null und 0 leeren das Feld regulär
     * @return int|null die geprüfte ID, oder null wenn das Feld geleert wird
     *
     * @throws \RuntimeException wenn die ID in diesem Team nicht sichtbar ist
     */
    public static function referenz(string $modelClass, mixed $id, ?Team $team, string $feld): ?int
    {
        if ($id === null || $id === '' || (int) $id === 0) {
            return null;
        }

        if (! $modelClass::visibleToTeam($team)->whereKey((int) $id)->exists()) {
            throw new \RuntimeException("{$feld}: die gewählte Zuordnung ist in diesem Team nicht verfügbar.");
        }

        return (int) $id;
    }

    /**
     * Mengenvariante für Pivot-Syncs — EIN Query statt einer Prüfung pro ID, sonst tauscht man
     * ein Sicherheits- gegen ein Performance-Problem.
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $modelClass
     * @param  array<int|string>  $ids
     * @return array<int, int>
     *
     * @throws \RuntimeException wenn mindestens eine ID nicht sichtbar ist
     */
    public static function referenzen(string $modelClass, array $ids, ?Team $team, string $feld): array
    {
        $gewuenscht = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($gewuenscht === []) {
            return [];
        }

        $sichtbar = $modelClass::visibleToTeam($team)->whereKey($gewuenscht)->pluck('id')
            ->map(fn ($i) => (int) $i)->all();

        if (count($sichtbar) !== count($gewuenscht)) {
            throw new \RuntimeException("{$feld}: mindestens eine gewählte Zuordnung ist in diesem Team nicht verfügbar.");
        }

        return $gewuenscht;
    }
}
