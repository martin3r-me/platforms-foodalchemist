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
     * Master = der EINE Kurator des globalen Bestands. Die einzige Instanz, die GLOBALE
     * Zeilen pflegen darf.
     *
     * Konfiguriert (`foodalchemist.master_team_id`) gewinnt: genau dieses Team. Ungesetzt
     * gilt weiter die strukturelle Antwort „Team ohne Eltern" — dieselbe wie vor 2026-09-11.
     *
     * Warum die Config die schärfere Aussage ist: auf demo tragen ALLE Teams
     * `parent_team_id = NULL`, weil die Hierarchie nie gepflegt wurde. „Elternlos" ist dort
     * kein Master-Signal, sondern ein Zufall — und sobald globales Wissen schreibbar wird,
     * wäre dieser Zufall ein Schreibrecht. Die Config benennt den Kurator, statt ihn aus
     * einer ungepflegten Spalte zu erraten.
     */
    public static function isMaster(?Team $team): bool
    {
        if ($team === null) {
            return false;
        }
        $konfiguriert = config('foodalchemist.master_team_id');

        return is_numeric($konfiguriert)
            ? (int) $team->id === (int) $konfiguriert
            : $team->parent_team_id === null;
    }

    /**
     * SCHREIBRECHT MIT MASTER-AUSNAHME — die dritte Regel neben `owns()` (strikt) und
     * `applyVisible()` (Sichtbarkeit).
     *
     * `owns()` allein sperrt globale Zeilen für JEDEN aus: `owns(null, …)` ist immer false.
     * Für geerbtes Master-Wissen ist das richtig — ein Kind-Team soll den Katalog nicht
     * verändern. Für den KURATOR ist es falsch: er pflegt genau diesen globalen Bestand.
     *
     * Konkret sichtbar geworden am 2026-09-03: die 6 global geseedeten Wissens-Dokumente
     * sind über den Browser von niemandem editierbar, auch nicht von BHG. Und sobald der
     * kuratierte Korpus (818 Dossiers) auf `team_id NULL` wandert — Dominiques Modell:
     * „das Wissen ist global, damit die Generatoren laufen" —, wäre das gesamte Wissen
     * eingefroren. `owns()` ist dafür das falsche Werkzeug, nicht die falsche Regel.
     *
     * Dieselbe Ausnahme führte `Wissenskategorien::delete()` schon lokal und ausführlich
     * begründet; sie stand danach zweimal kopiert in den Einstellungen. Hier lebt sie an
     * EINER Stelle.
     *
     * ⚠ HISTORIE, damit niemand zurückdreht: bis 2026-09-11 stand hier die umgekehrte
     * Warnung — Wissens-Dokumente seien ausgenommen, globales Wissen für JEDEN
     * unveränderlich, und der kuratierte Korpus dürfe deshalb NICHT auf `team_id NULL`
     * wandern. Das war unter der damaligen Annahme richtig. Dominiques Entscheid vom
     * 2026-09-11 hebt die Annahme auf: er IST der Master, sein Bestand ist global, und er
     * pflegt ihn weiter. Damit heisst global „gehört dem Master", nicht „gehört niemandem",
     * und die Wissens-Schreibpfade fragen `mayWrite()` wie alle anderen auch.
     *
     * Die Sperre verschwindet dabei nicht, sie bekommt einen Namen: ein FREMDES Team darf
     * globales Wissen unverändert nicht anfassen. Gepinnt in `WissenMasterSchreibrechtTest`.
     *
     * `KnowledgeLinkService::docFuerSchreiben()` sagte das übrigens schon vor diesem
     * Entscheid („Verbindungen daran setzt das Master-Team") — der Rest des Moduls holt hier
     * nur auf.
     *
     * Bewusst NICHT geändert: `owns()` selbst. Die strikte Bedeutung bleibt für alle
     * Katalog-Tabellen richtig; wer die Ausnahme will, fragt danach.
     */
    public static function mayWrite(mixed $rowTeamId, ?Team $team): bool
    {
        return self::owns($rowTeamId, $team) || ($rowTeamId === null && self::isMaster($team));
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
