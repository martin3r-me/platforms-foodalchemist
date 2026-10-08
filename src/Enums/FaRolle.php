<?php

namespace Platform\FoodAlchemist\Enums;

/**
 * Spec 61 §3 · Die vier FA-Stufen, aufsteigend — jede enthält die vorige. Abgeleitet aus der
 * Plattform-Rolle (FaRechte), nicht separat gespeichert.
 * Die Rechte-Namen (`recht()`) sind so gewählt, dass sie später auf den Core-Authz-Graphen
 * (read/write/manage) abbildbar sind.
 */
enum FaRolle: string
{
    case Lesen = 'lesen';
    case Kuratieren = 'kuratieren';
    case Freigeben = 'freigeben';
    case Admin = 'admin';

    public function rang(): int
    {
        return match ($this) {
            self::Lesen => 1,
            self::Kuratieren => 2,
            self::Freigeben => 3,
            self::Admin => 4,
        };
    }

    public function mindestens(self $rolle): bool
    {
        return $this->rang() >= $rolle->rang();
    }

    public function label(): string
    {
        return match ($this) {
            self::Lesen => 'Lesen',
            self::Kuratieren => 'Kuratieren',
            self::Freigeben => 'Freigeben',
            self::Admin => 'FA-Admin',
        };
    }

    public function beschreibung(): string
    {
        return match ($this) {
            self::Lesen => 'Betrachter: sieht alles, druckt und exportiert, schreibt nichts',
            self::Kuratieren => 'Mitglied: legt an und bearbeitet, bucht Lieferscheine, erfasst Rechnungen',
            self::Freigeben => 'Mitglied mit Häkchen: gibt Rechnungen frei und markiert sie als bezahlt',
            self::Admin => 'Inhaber/Admin: alles, inkl. Einstellungen und Freigaberechte',
        };
    }

    public function recht(): string
    {
        return match ($this) {
            self::Lesen => 'fa.lesen',
            self::Kuratieren => 'fa.kuratieren',
            self::Freigeben => 'fa.freigeben',
            self::Admin => 'fa.verwalten',
        };
    }

    public static function max(self $a, self $b): self
    {
        return $a->rang() >= $b->rang() ? $a : $b;
    }
}
