<?php

namespace Platform\FoodAlchemist\Enums;

/**
 * Signal-Lebenszyklus (#378): offen → erledigt | ignoriert.
 */
enum SignalStatus: string
{
    case Offen = 'offen';
    case Erledigt = 'erledigt';
    case Ignoriert = 'ignoriert';

    public function label(): string
    {
        return match ($this) {
            self::Offen => 'Offen',
            self::Erledigt => 'Erledigt',
            self::Ignoriert => 'Ignoriert',
        };
    }

    public function badgeVariant(): string
    {
        return match ($this) {
            self::Offen => 'warning',
            self::Erledigt => 'success',
            self::Ignoriert => 'secondary',
        };
    }

    public function istOffen(): bool
    {
        return $this === self::Offen;
    }
}
