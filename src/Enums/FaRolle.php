<?php

namespace Platform\FoodAlchemist\Enums;

/**
 * Spec 61 §3 · Die vier FA-Rollen, aufsteigend — jede enthält die vorige.
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
            self::Lesen => 'sieht alles, druckt und exportiert, schreibt nichts',
            self::Kuratieren => 'legt an und bearbeitet, bucht Lieferscheine',
            self::Freigeben => 'was nach außen wirkt: Rechnungen freigeben, Bestellungen senden, VK freigeben',
            self::Admin => 'Einstellungen, Kataloge, Rollen der Mitglieder',
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
