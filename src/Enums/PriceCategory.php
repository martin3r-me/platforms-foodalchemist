<?php

namespace Platform\FoodAlchemist\Enums;

/**
 * GL-11 §3.1: preis_kategorie — abgeleitet aus (price, status), nie gespeichert (I2).
 * Aktiv (I3) = standard_ek | auslaufend.
 *
 * Lebenszyklus derselben Necta-Preiszeile (belegt an io, 16.10.2026): status '0' (gelistet) → '2' MIT Preis (läuft aus, der
 * letzte Normalpreis gilt weiter) → '2' OHNE Preis (eingestellt: nicht mehr bestellbar, Kluth LA 318750 im Bestellportal geprüft).
 * Belege: status 2 + Preis hat genau eine Zeile, Preis = letzter N-Preis, kein promotionalPrice, kein Historien-Typ A (15 von 15);
 * status 2 + NULL: Necta stellt bestehende Zeilen um, 1,3 % mit späterem N-Stand, 0 kehren zurück. Früher hieß 2 + Preis
 * „Aktion“ (GL-11, unbelegt); die echte Aktion ist der Historien-Typ A (price_history.status 'A'). price < 0 schlägt IMMER zu service_charge
 * durch (I5 — GT-1 LA 31303090, trotz status '0').
 */
enum PriceCategory: string
{
    case ServiceCharge = 'service_charge';
    case StandardEk = 'standard_ek';
    case Auslaufend = 'auslaufend';
    case Eingestellt = 'eingestellt';
    case Datenluecke = 'datenluecke';
    case Unbekannt = 'unbekannt';

    public static function fuer(?float $price, ?string $status): self
    {
        return match (true) {
            $price !== null && $price < 0 => self::ServiceCharge,
            $status === '0' && $price !== null => self::StandardEk,
            $status === '2' && $price !== null => self::Auslaufend,
            $status === '2' && $price === null => self::Eingestellt,
            $status === '0' && $price === null => self::Datenluecke,
            default => self::Unbekannt,
        };
    }

    public function istAktiv(): bool
    {
        return $this === self::StandardEk || $this === self::Auslaufend;
    }

    public function label(): string
    {
        return match ($this) {
            self::ServiceCharge => 'Service-Zuschlag',
            self::StandardEk => 'Standard-EK',
            self::Auslaufend => 'läuft aus',
            self::Eingestellt => 'eingestellt',
            self::Datenluecke => 'Datenlücke',
            self::Unbekannt => 'unbekannt',
        };
    }
}
