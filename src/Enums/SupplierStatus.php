<?php

namespace Platform\FoodAlchemist\Enums;

/**
 * R9.1 (E1) — kommerzieller Beziehungs-Status eines Lieferanten. Feiner als
 * is_inactive: aktiv = Standard-Bezugsquelle, zweitquelle = Ausweich/Backup,
 * gesperrt = bewusst nicht bestellen (bleibt sichtbar, blockiert aber Lead-Setzung).
 */
enum SupplierStatus: string
{
    case Aktiv = 'aktiv';
    case Zweitquelle = 'zweitquelle';
    case Gesperrt = 'gesperrt';

    public function label(): string
    {
        return match ($this) {
            self::Aktiv => 'aktiv',
            self::Zweitquelle => 'Zweitquelle',
            self::Gesperrt => 'gesperrt',
        };
    }

    public function badgeVariant(): string
    {
        return match ($this) {
            self::Aktiv => 'success',
            self::Zweitquelle => 'warning',
            self::Gesperrt => 'danger',
        };
    }
}
