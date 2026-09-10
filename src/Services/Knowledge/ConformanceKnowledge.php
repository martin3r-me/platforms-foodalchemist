<?php

namespace Platform\FoodAlchemist\Services\Knowledge;

use Platform\Core\Models\Team;

/** Gemeinsame Kanonquelle für den migrierten Basisrezept-Critic und seine Vorschau. */
final class ConformanceKnowledge
{
    public const CANON_KEY = 'recipe.generator';

    public static function assertAvailable(Team $team): void
    {
        $canon = app(KnowledgeCanonService::class);
        if ($canon->documentsFor('prompt_key', self::CANON_KEY, $team)->where('mode', 'pflicht')->isEmpty()
            || collect($canon->unaufloesbareZeilen($team, self::CANON_KEY))->where('mode', 'pflicht')->where('scope', 'prompt_key')->where('role', 'root')->isNotEmpty()) {
            throw new \RuntimeException('Kein vollständiger aktiver Pflichtkanon für die Basisrezept-Prüfung.');
        }
    }
}
