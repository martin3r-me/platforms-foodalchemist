<?php

namespace Platform\FoodAlchemist\Enums;

/**
 * GP-Regelwerk v3.7.1 §5 Ausnahme 3 — Rolle eines Lieferanten für die Marke in der GP-Klammer. Nur beim Hersteller steht sein Kurzname in der
 * Klammer; Händler und ungeklärte Lieferanten (offen) nie — dort zählt nur eine Marke, die im Artikel steht.
 */
enum SupplierRolle: string
{
    case Hersteller = 'hersteller';
    case Haendler = 'haendler';
    case Offen = 'offen';

    public function label(): string
    {
        return match ($this) {
            self::Hersteller => 'Hersteller',
            self::Haendler => 'Händler',
            self::Offen => 'nicht eingestuft',
        };
    }
}
