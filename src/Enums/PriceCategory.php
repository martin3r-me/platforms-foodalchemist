<?php

namespace Platform\FoodAlchemist\Enums;

/**
 * GL-11 §3.1: preis_kategorie — abgeleitet aus (price, status), nie gespeichert (I2).
 * Aktiv (I3) = standard_ek | aktion. price < 0 schlägt IMMER zu service_charge
 * durch (I5 — GT-1 LA 31303090, trotz status '0').
 */
enum PriceCategory: string
{
    case ServiceCharge = 'service_charge';
    case StandardEk = 'standard_ek';
    case Aktion = 'aktion';
    case Eingestellt = 'eingestellt';
    case Datenluecke = 'datenluecke';
    case Unbekannt = 'unbekannt';

    public static function fuer(?float $price, ?string $status): self
    {
        return match (true) {
            $price !== null && $price < 0 => self::ServiceCharge,
            $status === '0' && $price !== null => self::StandardEk,
            $status === '2' && $price !== null => self::Aktion,
            $status === '2' && $price === null => self::Eingestellt,
            $status === '0' && $price === null => self::Datenluecke,
            default => self::Unbekannt,
        };
    }

    public function istAktiv(): bool
    {
        return $this === self::StandardEk || $this === self::Aktion;
    }

    public function label(): string
    {
        return match ($this) {
            self::ServiceCharge => 'Service-Zuschlag',
            self::StandardEk => 'Standard-EK',
            self::Aktion => 'Aktion',
            self::Eingestellt => 'eingestellt',
            self::Datenluecke => 'Datenlücke',
            self::Unbekannt => 'unbekannt',
        };
    }
}
