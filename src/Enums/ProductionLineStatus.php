<?php

namespace Platform\FoodAlchemist\Enums;

/**
 * Spec 30 E6 — Status einer einzelnen Produktionszeile (Küchen-Ausführung).
 *
 * Strukturgleich zu {@see ProductionOrderStatus}, damit die Blade-Helfer (`label`,
 * `badgeVariant`) unverändert greifen — aber mit ANDERER Semantik:
 *
 * Der Auftrags-Status ist ein Beleg-Lebenszyklus mit Einbahn-Charakter (Snapshot einfrieren,
 * nach außen melden). Ein Zeilen-Status ist eine CHECKLISTE. Checklisten müssen ent-hakbar
 * sein, sonst braucht der erste Fehlklick eine Datenbank-Operation. Die Übergänge sind
 * deshalb bewusst frei — die Strenge sitzt eine Ebene höher, wo sie hingehört.
 *
 * ⚠️ `skipped` ist NICHT dasselbe wie `is_struck` an der Zeile:
 *  · `is_struck`  = PLANUNGS-Entscheid im Status `planned` („produzieren wir nicht"),
 *                   fliegt aus allen Summen und aus dem Druck.
 *  · `skipped`    = AUSFÜHRUNGS-Ergebnis im Status `in_progress` („hätten wir sollen, haben
 *                   wir nicht"), bleibt als Soll in den Summen und zählt als nicht erledigt.
 * Wer die beiden zusammenführt, verliert genau diese Unterscheidung.
 */
enum ProductionLineStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Done = 'done';
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'offen',
            self::InProgress => 'in Arbeit',
            self::Done => 'erledigt',
            self::Skipped => 'übersprungen',
        };
    }

    public function badgeVariant(): string
    {
        return match ($this) {
            self::Open => 'secondary',
            self::InProgress => 'info',
            self::Done => 'success',
            self::Skipped => 'warning',
        };
    }

    /** Zählt als abgearbeitet — erledigt ODER bewusst übersprungen. */
    public function istAbgearbeitet(): bool
    {
        return $this === self::Done || $this === self::Skipped;
    }
}
