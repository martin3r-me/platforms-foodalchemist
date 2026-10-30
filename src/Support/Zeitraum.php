<?php

namespace Platform\FoodAlchemist\Support;

use Carbon\Carbon;

/**
 * Zeitraum-Presets der Browser (Bestellungen, Produktion) und ihrer MCP-Gegenstücke (Spec 91 PR 4): ein Preset ergibt ein
 * von/bis-Fenster. Vorher rechnete jede Komponente selbst, das MCP-Tool kannte die Presets nicht.
 */
final class Zeitraum
{
    public const PRESETS = ['heute', 'woche', 'naechste', 'vergangen'];

    /** @return array{0: ?string, 1: ?string} von/bis; unbekannter oder leerer Key = kein Fenster */
    public static function grenzen(string $key): array
    {
        $heute = Carbon::today();

        return match ($key) {
            'heute' => [$heute->toDateString(), $heute->toDateString()],
            'woche' => [
                $heute->copy()->startOfWeek(Carbon::MONDAY)->toDateString(),
                $heute->copy()->endOfWeek(Carbon::SUNDAY)->toDateString(),
            ],
            'naechste' => [
                $heute->copy()->addWeek()->startOfWeek(Carbon::MONDAY)->toDateString(),
                $heute->copy()->addWeek()->endOfWeek(Carbon::SUNDAY)->toDateString(),
            ],
            'vergangen' => [null, $heute->copy()->subDay()->toDateString()],
            default => [null, null],
        };
    }
}
