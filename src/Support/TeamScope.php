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

        // Spec 77d: Inhalts-Tabellen — dieselbe Hüllen-Regel wie scopeVisibleToTeam
        // Auch bei Alias (`foodalchemist_recipes AS r`, Spalte `r.team_id`): Basistabelle aus from/join auflösen (wie beim Stamm-Zweig),
        // die Freigabe-Prüfung hängt am Alias-Präfix (`r.id`). Vorher blieb der Alias-Fall ungefiltert (Tabellenname `r` ist keine Inhalts-Tabelle).
        [$basisTabelle, $idPraefix] = self::basisTabelleUndPraefix($query, $teamIdColumn);
        if ($team !== null && ($typ = InhaltsFreigabe::typFuerTabelle($basisTabelle)) !== null) {
            return $query->where(fn ($q) => InhaltsFreigabe::anwenden($q, $teamIdColumn, $idPraefix . '.id', $typ, $team, $ids, $basisTabelle));
        }

        // Spec 82 §12 A3: GP/Lieferant/Artikel — globale stamm_paket-Zeilen nur bei Opt-in.
        // Auch bei Alias (`foodalchemist_supplier_items AS i`, Spalte `i.team_id`): Der Alias wird zur Basistabelle aufgelöst,
        // die Klassenspalte hängt am Alias (`i.stamm_class`). Vorher blieb die Stamm-Klasse bei Alias-Abfragen ungefiltert.
        [$basis, $klassePraefix] = self::basisTabelleUndPraefix($query, $teamIdColumn);
        if ($team !== null && StammPaket::betrifft($basis)) {
            return $query->where(function ($q) use ($teamIdColumn, $ids, $klassePraefix, $team, $basis) {
                $q->where(fn ($g) => StammPaket::globaleZeilen($g, $teamIdColumn, $klassePraefix . '.stamm_class', $team, $basis));
                if ($ids !== []) {
                    $q->orWhereIn($teamIdColumn, $ids);
                }
            });
        }

        // Spec 84 B3 Stufe 4: Vokabular/Einstellungen nur bis zur Kundengrenze (erst ab Schalter 4)
        $ids = \Platform\FoodAlchemist\Models\Scopes\TeamSicht::ketteFuerTabelle($basisTabelle, $ids);

        return $query->where(function ($q) use ($teamIdColumn, $ids) {
            $q->whereNull($teamIdColumn);
            if ($ids !== []) {
                $q->orWhereIn($teamIdColumn, $ids);
            }
        });
    }

    /**
     * Basistabelle und Spalten-Präfix der Abfrage für die Stamm-Klasse (Spec 82 §12 A3).
     * Mit Alias-Spalte (`i.team_id`): der Alias wird in `from` bzw. den Joins gesucht (`foodalchemist_supplier_items AS i` → Tabelle
     * `foodalchemist_supplier_items`, Präfix `i`). Ohne Punkt: die `from`-Tabelle (ein Alias dort wird abgeschnitten, aber als Präfix benutzt).
     * Unbekannter Präfix: er gilt selbst als Tabellenname (wie bisher).
     *
     * @return array{0: string, 1: string} [Basistabelle, Präfix der Klassenspalte]
     */
    private static function basisTabelleUndPraefix($query, string $teamIdColumn): array
    {
        $q = $query instanceof \Illuminate\Database\Eloquent\Builder ? $query->getQuery() : $query;
        $quellen = [];
        if (is_string($q->from ?? null)) {
            $quellen[] = $q->from;
        }
        foreach ((array) ($q->joins ?? []) as $join) {
            if (is_string($join->table ?? null)) {
                $quellen[] = $join->table;
            }
        }
        $zerlegt = array_map(static function (string $roh): array {
            $roh = str_replace(['`', '"'], '', trim($roh));

            return preg_match('/^(\S+)\s+as\s+(\S+)$/i', $roh, $m) ? [$m[1], $m[2]] : [$roh, $roh];   // [Tabelle, Alias oder Tabelle]
        }, $quellen);

        if (str_contains($teamIdColumn, '.')) {
            $praefix = strstr($teamIdColumn, '.', true);
            foreach ($zerlegt as [$tabelle, $alias]) {
                if (strcasecmp($alias, $praefix) === 0) {
                    return [$tabelle, $praefix];
                }
            }

            return [$praefix, $praefix];
        }
        [$tabelle, $alias] = $zerlegt[0] ?? ['', ''];

        return [$tabelle, $alias];
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
     * Spec 84 B1 (Dominique 16.10., E1 „Demo ist egal“): EINE Master-Definition — nur das konfigurierte Team
     * ({@see MasterTeam::istMaster}). Der frühere Rückfall „ungesetzt = jedes Team ohne Eltern“ entfällt: auf io sind alle
     * Mandanten elternlos, „elternlos“ ist kein Master-Signal. Ohne Konfiguration gibt es keinen Master (einmalige Log-Warnung).
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
        if (MasterTeam::id() === null && ! self::$masterWarnungGeloggt) {
            self::$masterWarnungGeloggt = true;
            \Illuminate\Support\Facades\Log::warning('Spec 84 B1: kein Master-Team konfiguriert — Pairing-Signale, Anker-Wissen und globale Pflege haben keinen Kurator.');
        }

        return MasterTeam::istMaster($team);
    }

    /** Einmal je Prozess warnen (B1), nicht bei jeder Abfrage. */
    private static bool $masterWarnungGeloggt = false;

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
