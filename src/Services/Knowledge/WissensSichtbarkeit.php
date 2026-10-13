<?php

declare(strict_types=1);

namespace Platform\FoodAlchemist\Services\Knowledge;

use Illuminate\Support\Facades\Schema;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Support\TeamScope;

/**
 * Spec 82 §13 H — „Master-Wissen unsichtbar": EINE Stelle für die Lese-Sichtbarkeit des Wissens-Korpus.
 *
 * LESEN (MCP knowledge.GET/LIST/SEARCH, Datenwerk, Vorgangs-Anleitungen, Wissens-Browser):
 *   · die eigene Team-Kette (eigenes Team + Eltern) — immer
 *   · GLOBALES Wissen (team_id NULL) nur, wenn das Team zum Master gehört: das Master-Team selbst oder eines seiner Unter-Teams
 *     (sie erben das Wissen ohnehin). Der Plattform-Admin als solcher zählt NICHT — nur Mitgliedschaft im Master-Team.
 *   · Ohne konfiguriertes Master-Team (`MasterTeam::id()` null): niemand liest global (fail closed). Es gibt bewusst keinen
 *     Rückfall „jedes Wurzel-Team ist Master" — auf demo sind alle Teams elternlos.
 *
 * PROMPT (Generator, `KnowledgeContextService::contextFor` & Co.) läuft NICHT hierüber: Der Prompt enthält das Master-Wissen weiter,
 * der Mandant bekommt die Wirkung, nicht den Text. Die beiden Pfade sind im Service getrennt (`nurSichtbar` vs. `nurLesbar`).
 *
 * Notbremse: `foodalchemist.knowledge_master_regel` = false stellt die alte Regel wieder her (Lesen wie Prompt, dann gilt allein
 * `knowledge_team_scope`).
 */
final class WissensSichtbarkeit
{
    public static function aktiv(): bool
    {
        return (bool) config('foodalchemist.knowledge_master_regel', true);
    }

    /** Darf dieses Team globales (team_id NULL) und Master-Wissen LESEN? */
    public static function darfGlobalLesen(?Team $team): bool
    {
        $master = MasterTeam::id();

        return $team !== null && $master !== null && in_array($master, TeamScope::ancestryIds($team), true);
    }

    /**
     * Darf dieses Team die Kanon-ZUORDNUNG (welches Dossier in welchem Prompt) und Routing-Konfiguration lesen? Entscheid 13.10.:
     * Mandanten nicht, die Wirkung bleibt. Notbremse aus = wie früher (ja).
     */
    public static function darfKanonLesen(?Team $team): bool
    {
        return ! self::aktiv() || self::darfGlobalLesen($team);
    }

    /**
     * Lese-Filter für rohe Query-Builder auf `foodalchemist_knowledge_*`.
     *
     * AUSNAHME `art = ablauf` + `category = workflow` + Frontmatter `gilt_fuer_vorgang` (Entscheid 13.10.): Bedienanleitung für die Agenten
     * (`ablauf.GET`), also „Wirkung" wie der Prompt, kein Fachwissen — global (team_id NULL) und vom Master-Team gepflegte sind für jedes Team lesbar.
     *
     * @template T of \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder
     * @param  T  $query
     * @return T
     */
    public static function lesbar($query, string $teamIdColumn, ?Team $team, bool $ablaufAusnahme = true)
    {
        $ids = TeamScope::ancestryIds($team);
        $global = self::darfGlobalLesen($team);
        $master = MasterTeam::id();
        $praefix = str_contains($teamIdColumn, '.') ? strstr($teamIdColumn, '.', true) . '.' : '';
        $artSpalte = $praefix . 'art';
        $kategorieSpalte = $praefix . 'category';
        $inhaltSpalte = $praefix . 'content_md';
        $ablauf = $ablaufAusnahme && self::hatArt();   // nur für `knowledge_documents` (Spalte `art`), nicht für Bindungen/Kategorien

        return $query->where(function ($q) use ($teamIdColumn, $ids, $global, $master, $artSpalte, $kategorieSpalte, $inhaltSpalte, $ablauf) {
            $q->whereRaw('1 = 0');   // neutraler Anfang: jede Zeile unten ist ein OR
            if ($ids !== []) {
                $q->orWhereIn($teamIdColumn, $ids);
            }
            if ($global) {
                $q->orWhereNull($teamIdColumn);
            }
            if ($ablauf) {
                $q->orWhere(function ($a) use ($teamIdColumn, $master, $artSpalte, $kategorieSpalte, $inhaltSpalte) {
                    // Eigenschaft statt Namensmuster: art=ablauf UND category=workflow UND der Kopf des Dossiers nennt `gilt_fuer_vorgang`
                    // (die 4–5 internen Wissensmodul-Dokumente tragen art=ablauf/category=workflow, aber keinen Vorgang). Fail closed:
                    // ohne den Schlüssel im Frontmatter bleibt ein Dossier unlesbar. Saubere Lösung = eigene Spalte/Art (Datenentscheid).
                    $a->where($artSpalte, 'ablauf')->where($kategorieSpalte, 'workflow')
                        ->where($inhaltSpalte, 'like', "---%gilt_fuer_vorgang:%")
                        ->where(function ($t) use ($teamIdColumn, $master) {
                        $t->whereNull($teamIdColumn);
                        if ($master !== null) {
                            $t->orWhere($teamIdColumn, $master);
                        }
                    });
                });
            }
        });
    }

    /** Wie {@see lesbar()}, aber mit Notbremse: Regel aus = die alte Team-Sicht (`TeamScope::applyVisible`). */
    public static function lesbarOderAlt($query, string $teamIdColumn, ?Team $team, bool $ablaufAusnahme = true)
    {
        return self::aktiv() ? self::lesbar($query, $teamIdColumn, $team, $ablaufAusnahme) : TeamScope::applyVisible($query, $teamIdColumn, $team);
    }

    /**
     * Darf dieses Team GLOBALES Wissen (team_id NULL) und die globale Steuerung (Routings, Budget, Kanon-global, Kategorien) SCHREIBEN?
     * Nur das Master-Team selbst — streng über `MasterTeam::istMaster`, ohne den Rückfall „elternloses Team" von `TeamScope::isMaster`
     * (auf demo sind alle Teams elternlos). Notbremse aus = alte Regel (`TeamScope::isMaster`).
     */
    public static function darfGlobalSchreiben(?Team $team): bool
    {
        return self::aktiv() ? MasterTeam::istMaster($team) : TeamScope::isMaster($team);
    }

    /** Schreibrecht an einer Wissens-Zeile: Besitzer-Team, oder global (team_id NULL) nur der Master. Ersetzt `TeamScope::mayWrite` im Wissensmodul. */
    public static function mayWrite(mixed $rowTeamId, ?Team $team): bool
    {
        return TeamScope::owns($rowTeamId, $team) || ($rowTeamId === null && self::darfGlobalSchreiben($team));
    }

    private static function hatArt(): bool
    {
        static $hat = null;

        return $hat ??= Schema::hasColumn('foodalchemist_knowledge_documents', 'art');
    }
}
