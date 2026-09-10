<?php

namespace Platform\FoodAlchemist\Services\Knowledge;

use Platform\Core\Models\Team;

/**
 * Gemeinsame Kanonquelle für die migrierten Critic-Pfade und ihre Vorschau.
 *
 * ★ Welcher Artefakt-Typ migriert ist, steht in `config('foodalchemist.ai.conformance_kanon')`
 * — nicht im Code. Ein Rollout auf ein weiteres Artefakt ist damit EINE Config-Zeile plus
 * kuratierte Kanon-Zeilen, keine Code-Verzweigung (Grundsatz E: in der Konfiguration
 * setzbar, nicht im Code vergraben).
 *
 * Fehlt der Eintrag, gilt der Artefakt-Typ als NICHT migriert und behält den bisherigen
 * Präfix-Lader. Das ist der einzige erlaubte „Rückfall" — er ist eine Konfigurations-
 * entscheidung, kein stiller Ausfall.
 *
 * ⚠ Eine Config-Zeile OHNE kuratierten Pflichtkanon macht den Prüfpass kaputt statt ihn
 * umzustellen: {@see self::assertAvailable()} wirft dann zur Laufzeit. Stand 2026-09-10 hat
 * `gp` NULL und `la` NULL Kanon-Zeilen — dort ist zuerst Kuration nötig.
 */
final class ConformanceKnowledge
{
    /** Nur noch Rückfall-Default für den ersten migrierten Pfad. */
    public const CANON_KEY = 'recipe.generator';

    /** Kanon-Prompt-Key für einen Artefakt-Schlüssel, oder null = nicht migriert. */
    public static function kanonKeyFuer(?string $artefakt): ?string
    {
        if ($artefakt === null || $artefakt === '') {
            return null;
        }
        $map = config('foodalchemist.ai.conformance_kanon', []);
        $key = is_array($map) ? ($map[$artefakt] ?? null) : null;

        return is_string($key) && $key !== '' ? $key : null;
    }

    public static function assertAvailable(Team $team, string $kanonKey = self::CANON_KEY): void
    {
        $canon = app(KnowledgeCanonService::class);
        if ($canon->documentsFor('prompt_key', $kanonKey, $team)->where('mode', 'pflicht')->isEmpty()
            || collect($canon->unaufloesbareZeilen($team, $kanonKey))->where('mode', 'pflicht')->where('scope', 'prompt_key')->where('role', 'root')->isNotEmpty()) {
            throw new \RuntimeException("Kein vollständiger aktiver Pflichtkanon für die Prüfung [{$kanonKey}].");
        }
    }
}
