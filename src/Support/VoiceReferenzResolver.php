<?php

namespace Platform\FoodAlchemist\Support;

/**
 * Spec 53 / Paket F (4): erkennt, ob ein Transkript eine BESTÄTIGUNG/REFERENZ auf einen
 * bereits gezeigten, noch offenen Vorschlag ist ("ja", "das zweite", "nummer 2") — statt
 * jedes Mal den vollen Tool-Loop zu bemühen. Läuft VOR {@see \Platform\FoodAlchemist\Services\VoiceCommandService::verarbeite()};
 * ein Treffer wird über DIESELBEN Methoden ausgeführt, die auch der Bestätigen-Klick
 * aufruft (GL-07 bleibt unverändert — nichts läuft hier direkter als über den Knopf).
 *
 * Bewusst NICHT LLM-basiert: eine feste, kurze Muster-Liste ist für sechs, sieben deutsche
 * Wendungen schneller, billiger und vorhersagbarer als ein Modell-Aufruf nur für "ja".
 */
class VoiceReferenzResolver
{
    /** Ordinalwort → 1-basierte Position. */
    private const ORDINALE = [
        'erste' => 1, 'ersten' => 1, 'erstes' => 1,
        'zweite' => 2, 'zweiten' => 2, 'zweites' => 2,
        'dritte' => 3, 'dritten' => 3, 'drittes' => 3,
        'vierte' => 4, 'vierten' => 4, 'viertes' => 4,
        'fünfte' => 5, 'fünften' => 5, 'fünftes' => 5,
        'sechste' => 6, 'sechsten' => 6, 'sechstes' => 6,
    ];

    /** Reine Zustimmung OHNE Ordnungszahl — nur eindeutig, wenn GENAU EIN Vorschlag offen ist. */
    private const ZUSTIMMUNG = [
        'ja', 'jo', 'jup', 'jep', 'genau', 'ok', 'okay', 'passt', 'bestätigt', 'bestätige',
        'mach das', 'mach es', 'mach ihn', 'mach sie', 'tu es', 'los', 'einverstanden',
    ];

    /**
     * @return int|null 0-basierter Index in die offenen Vorschläge, oder null = keine
     *                   erkennbare Referenz (der Aufrufer soll dann den normalen Tool-Loop fahren).
     */
    public static function erkenne(string $transkript, int $anzahlOffen): ?int
    {
        if ($anzahlOffen <= 0) {
            return null;
        }
        $norm = self::normalisiert($transkript);
        if ($norm === '') {
            return null;
        }

        // "nummer 2" / "vorschlag 2" / bloß "2" — GANZER Text muss dem Muster entsprechen,
        // sonst würde z. B. "füge 200 Gramm hinzu" fälschlich als Referenz auf #200 gelten.
        if (preg_match('/^(?:nummer|vorschlag|nr\.?)?\s*(\d+)$/u', $norm, $treffer) === 1) {
            $n = (int) $treffer[1];
            if ($n >= 1 && $n <= $anzahlOffen) {
                return $n - 1;
            }
        }

        foreach (self::ORDINALE as $wort => $position) {
            if (str_contains($norm, $wort) && mb_strlen($norm) <= 30) {
                return $position <= $anzahlOffen ? $position - 1 : null;
            }
        }

        // Reine Zustimmung: nur bei GENAU EINEM offenen Vorschlag eindeutig — bei mehreren
        // ist "ja" mehrdeutig, dann lieber gar nicht raten (fällt in den normalen Tool-Loop,
        // der Agent fragt im Zweifel nach oder antwortet konversationell).
        if ($anzahlOffen === 1 && in_array($norm, self::ZUSTIMMUNG, true)) {
            return 0;
        }

        return null;
    }

    private static function normalisiert(string $text): string
    {
        $t = mb_strtolower(trim($text));
        $t = preg_replace('/[.!?,;]+$/u', '', $t) ?? $t;

        return trim($t);
    }
}
