<?php

namespace Platform\FoodAlchemist\Services\Conformance;

use Platform\Core\Models\Team;

/**
 * Schicht 3 (Konformitäts-Critic) — der EINZIGE artefakt-spezifische Teil eines
 * ansonsten generischen Prüf-Passes. Ein Adapter je Artefakt-Typ (Rezept/VK, GP,
 * LA …); er liefert (a) die prüfbare Beschreibung des Artefakts und (b) WELCHE
 * Regelwerke geprüft werden. Prompt (`conformance.check`) und Prüf-Mechanik
 * ({@see \Platform\FoodAlchemist\Services\ConformanceService}) sind geteilt —
 * damit KEIN Prompt/Service pro Generator (User-Entscheid 2026-08-27).
 */
interface ConformanceAdapter
{
    /**
     * Stabiler Domänen-Schlüssel des Artefakt-Typs für die Ablage (z. B. "recipe",
     * "gp", "la"). Rezept UND Verkaufsgericht teilen sich "recipe" (dieselbe Tabelle).
     */
    public function artifactType(): string;

    /**
     * Ob dieser Artefakt-Typ eine autonome Selbstheil-Runde beherrscht. true =
     * {@see self::revise} korrigiert wirklich (Rezept/VK via recipe.ueberarbeiten);
     * false = kein Freitext-Revise (GP/LA v1) → die Runde wird übersprungen, Verstöße
     * gehen direkt als Hinweis in die Ablage (kein sinnloser zweiter Prüf-Call).
     */
    public function unterstuetztHeilung(): bool;

    /**
     * Der Prüfauftrag für EIN Artefakt: was beschrieben und wogegen geprüft wird.
     *
     * @return array{
     *     kontext: array<string, mixed>,
     *     regelwerk_praefixe: array<int, string>,
     *     target_table: string
     * }
     */
    public function pruefauftrag(Team $team, int $id): array;

    /**
     * EINE autonome Selbstheil-Runde: das Artefakt exakt nach der Direktive (Liste
     * der Regelverstöße) korrigieren, ohne es sonst umzuschreiben. Best-effort —
     * gelingt sie nicht, bleibt der Verstoß als Hinweis stehen (kein Block).
     */
    public function revise(Team $team, int $id, string $direktive, array $befunde = []): void;
}
