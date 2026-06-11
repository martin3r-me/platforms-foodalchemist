<?php

namespace Platform\FoodAlchemist\Enums;

/**
 * GL-04 §4.1: Schwellen-Bänder (MatchStatus::from_score, rs:63–73).
 * Schwellen-Assertions wörtlich — NIE auf Punktwerte verschärfen (09 §1 Regel 1).
 */
enum MatchBand: string
{
    case Exact = 'exact';            // ≥ 0.85 — Auto-Übernahme als Vorschlag
    case FuzzyHigh = 'fuzzy_high';   // ≥ 0.70 — Vorschlag, sichtbar markiert
    case FuzzyLow = 'fuzzy_low';     // ≥ 0.50 — Review nötig
    case NoMatch = 'no_match';       // < 0.50 — Hard-Stop

    public static function fuerScore(float $score): self
    {
        return match (true) {
            $score >= 0.85 => self::Exact,
            $score >= 0.70 => self::FuzzyHigh,
            $score >= 0.50 => self::FuzzyLow,
            default => self::NoMatch,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Exact => 'sicher',
            self::FuzzyHigh => 'wahrscheinlich',
            self::FuzzyLow => 'Review nötig',
            self::NoMatch => 'kein Treffer',
        };
    }
}
