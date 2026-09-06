<?php

namespace Platform\FoodAlchemist\Services\Reife;

use Platform\Core\Models\Team;

/**
 * Spec 50 · Schicht 4 (Vollständigkeit) — der EINZIGE artefakt-spezifische Teil eines
 * ansonsten generischen Mess-Passes. Ein Adapter je Artefakt-Typ; Hülle, Ampel und
 * Folgeschritte sind geteilt ({@see \Platform\FoodAlchemist\Services\ReifeService}).
 *
 * Bewusst dasselbe Muster wie Schicht 3 ({@see \Platform\FoodAlchemist\Services\Conformance\ConformanceAdapter}) —
 * dort hat es sich für „artefakt-agnostisch mit artefakt-spezifischem Rand" bewährt.
 *
 * ABGRENZUNG zu Schicht 3: die prüft, ob das VORHANDENE die Regelwerke einhält. Diese
 * Schicht prüft, ob überhaupt alles DA ist. Ein Concept ohne einen einzigen Header verstösst
 * gegen kein §, weil es keinen Header gibt, den man prüfen könnte — genau darum blieb die
 * Lücke bisher unsichtbar.
 *
 * HARTE AUFLAGE: **read-only und kein Provider-Call.** Diese Messung hängt am Schreibpfad
 * (jede Write-Antwort trägt sie); ein Reife-Report, der beim Lesen Daten erzeugt oder Kosten
 * verursacht, ist kein Report.
 */
interface ReifeAdapter
{
    /** Stabiler Domänen-Schlüssel für die Ablage/Anzeige (z. B. "recipe", "sales_recipe"). */
    public function artifactType(): string;

    /**
     * Die Messung für EIN Artefakt.
     *
     * @return array{name: string, status: ?string, luecken: list<array>, erfuellt: list<string>,
     *               nicht_messbar: list<array>, vorlaeufig: bool, kennzahlen: array<string, mixed>}|null
     *         null = nicht gefunden oder für dieses Team nicht sichtbar.
     */
    public function messe(Team $team, int $id): ?array;
}
