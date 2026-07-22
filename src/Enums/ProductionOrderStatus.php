<?php

namespace Platform\FoodAlchemist\Enums;

/**
 * Spec 18 — Lebenszyklus eines Produktionsauftrags (Küchen-Ausführung, kein Beleg-Versand).
 * planned = offen, sammelt/rechnet Ziele · in_progress = Produktion gestartet, Snapshot
 * eingefroren · done = fertig gemeldet · cancelled = storniert. Nur `planned` ist
 * editierbar/akkumulierend; alles andere ist read-only (Snapshot).
 */
enum ProductionOrderStatus: string
{
    case Planned = 'planned';
    case InProgress = 'in_progress';
    case Done = 'done';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Planned => 'geplant',
            self::InProgress => 'in Arbeit',
            self::Done => 'fertig',
            self::Cancelled => 'storniert',
        };
    }

    public function badgeVariant(): string
    {
        return match ($this) {
            self::Planned => 'secondary',
            self::InProgress => 'info',
            self::Done => 'success',
            self::Cancelled => 'danger',
        };
    }

    /** Nur der geplante Auftrag darf verändert/befüllt werden. */
    public function istOffen(): bool
    {
        return $this === self::Planned;
    }

    /** Erlaubte Folge-Status (Guard im Service). */
    public function darfWechselnZu(self $ziel): bool
    {
        return match ($this) {
            self::Planned => in_array($ziel, [self::InProgress, self::Cancelled], true),
            self::InProgress => in_array($ziel, [self::Done, self::Cancelled], true),
            self::Done => false,
            self::Cancelled => false,
        };
    }
}
