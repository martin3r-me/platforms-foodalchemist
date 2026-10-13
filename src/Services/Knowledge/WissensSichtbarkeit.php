<?php

declare(strict_types=1);

namespace Platform\FoodAlchemist\Services\Knowledge;

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
     * Lese-Filter für rohe Query-Builder auf `foodalchemist_knowledge_*`.
     *
     * @template T of \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder
     * @param  T  $query
     * @return T
     */
    public static function lesbar($query, string $teamIdColumn, ?Team $team)
    {
        $ids = TeamScope::ancestryIds($team);
        $global = self::darfGlobalLesen($team);

        return $query->where(function ($q) use ($teamIdColumn, $ids, $global) {
            if ($ids === [] && ! $global) {
                $q->whereRaw('1 = 0');

                return;
            }
            if ($ids !== []) {
                $q->whereIn($teamIdColumn, $ids);
            }
            if ($global) {
                $q->orWhereNull($teamIdColumn);
            }
        });
    }
}
