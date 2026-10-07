<?php

namespace Platform\FoodAlchemist\Tests\Support;

use Platform\FoodAlchemist\Services\Pairing\AnkerGraph;

/**
 * Spec 60 · P2: Test-Kanten für die Harmonie-Tabelle.
 *
 * `kante()` ist der Weg für neue Tests. `ausFixture()` übersetzt die Kanten-Zeilen der
 * alten Tabelle `pairing_anchor_edges`, damit bestehende Fixtures unverändert lesbar bleiben:
 *   - `level` gesetzt          → diese Stufe (2 oder 3)
 *   - erprobt/klassisch/modern/verbund/trinitas (früher Gewicht 1,0) → Stufe 3
 *   - aroma ohne level (früher Gewicht 0,9)                          → Stufe 2
 *   - kontrast                → keine Kante (Kontrast liegt nicht in der Harmonie)
 * Jede Kante wird in beiden Richtungen geschrieben ({@see AnkerGraph::setze}).
 */
final class Harmonie
{
    public static function kante(int $a, int $b, int $stufe = AnkerGraph::HARMONIERT): void
    {
        app(AnkerGraph::class)->setze($a, $b, $stufe);
    }

    /** @param  array<string, mixed>  $zeile  Spalten der früheren pairing_anchor_edges-Zeile */
    public static function ausFixture(array $zeile): void
    {
        $typ = (string) ($zeile['type'] ?? 'aroma');
        if ($typ === 'kontrast') {
            return;
        }
        $level = isset($zeile['level']) && $zeile['level'] !== null ? (int) $zeile['level'] : null;
        $stufe = $level ?? (in_array($typ, ['erprobt', 'klassisch', 'modern', 'verbund', 'trinitas'], true)
            ? AnkerGraph::HARMONIERT : AnkerGraph::PASST);
        // Unterschiedlich gestufte Richtungen: die höhere gewinnt (wie früher beim Lesen).
        if ($stufe < AnkerGraph::PASST
            || app(AnkerGraph::class)->stufe((int) $zeile['anchor_a_id'], (int) $zeile['anchor_b_id']) >= $stufe) {
            return;
        }
        self::kante((int) $zeile['anchor_a_id'], (int) $zeile['anchor_b_id'], $stufe);
    }
}
