<?php

namespace Platform\FoodAlchemist\Enums;

/**
 * Signal-Schweregrad (#378) — steuert Sortierung/Optik in der Inbox.
 */
enum SignalSeverity: string
{
    case Info = 'info';
    case Warnung = 'warnung';
    case Kritisch = 'kritisch';

    public function label(): string
    {
        return match ($this) {
            self::Info => 'Info',
            self::Warnung => 'Warnung',
            self::Kritisch => 'Kritisch',
        };
    }

    public function badgeVariant(): string
    {
        return match ($this) {
            self::Info => 'info',
            self::Warnung => 'warning',
            self::Kritisch => 'danger',
        };
    }

    /** Sortier-Rang (kritisch zuerst). */
    public function rang(): int
    {
        return match ($this) {
            self::Kritisch => 0,
            self::Warnung => 1,
            self::Info => 2,
        };
    }
}
