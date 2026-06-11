<?php

namespace Platform\FoodAlchemist\Enums;

/**
 * 4-Wert-Allergen-Modell (GL-01, normativ; Merge-Rang: enthalten > spuren > nicht_enthalten > unbekannt).
 */
enum AllergenValue: string
{
    case Enthalten = 'enthalten';
    case Spuren = 'spuren';
    case NichtEnthalten = 'nicht_enthalten';
    case Unbekannt = 'unbekannt';

    /** Merge-Rang für ALL-MAXIMAL (GL-01 §4.1 — Code-verifiziert: unbekannt ist NIEDRIGSTER Rang). */
    public function rank(): int
    {
        return match ($this) {
            self::Enthalten => 3,
            self::Spuren => 2,
            self::NichtEnthalten => 1,
            self::Unbekannt => 0,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Enthalten => 'enthalten',
            self::Spuren => 'Spuren',
            self::NichtEnthalten => 'nicht enthalten',
            self::Unbekannt => 'unbekannt',
        };
    }

    public function badgeVariant(): string
    {
        return match ($this) {
            self::Enthalten => 'danger',
            self::Spuren => 'warning',
            self::NichtEnthalten => 'success',
            self::Unbekannt => 'secondary',
        };
    }
}
