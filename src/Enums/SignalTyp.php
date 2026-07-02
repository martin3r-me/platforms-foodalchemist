<?php

namespace Platform\FoodAlchemist\Enums;

/**
 * Signal-Typen (#378) — detektierte Auffälligkeiten (Klasse B) im „Signale"-Modul.
 * Die Entscheidungs-Queues (LA→GP-Match, KI-Bulk, VK ohne Klasse …) sind Klasse A
 * und bleiben in der ReviewQueue — sie sind KEINE SignalTyp-Werte.
 */
enum SignalTyp: string
{
    case PreisAnomalie = 'preis_anomalie';
    case VeraltetePreise = 'veraltete_preise';
    case MargeUnterZiel = 'marge_unter_ziel';
    case WareneinsatzUeberZiel = 'wareneinsatz_ueber_ziel';
    case DatenqualitaetGpLa = 'datenqualitaet_gp_la';

    public function label(): string
    {
        return match ($this) {
            self::PreisAnomalie => 'Preis-Anomalie',
            self::VeraltetePreise => 'Veraltete Preise',
            self::MargeUnterZiel => 'Marge unter Ziel',
            self::WareneinsatzUeberZiel => 'Wareneinsatz über Ziel',
            self::DatenqualitaetGpLa => 'Datenqualität GP/LA',
        };
    }

    /** Heroicon (ohne Präfix) für die Inbox-Darstellung. */
    public function icon(): string
    {
        return match ($this) {
            self::PreisAnomalie => 'heroicon-o-arrow-trending-up',
            self::VeraltetePreise => 'heroicon-o-clock',
            self::MargeUnterZiel => 'heroicon-o-scale',
            self::WareneinsatzUeberZiel => 'heroicon-o-shopping-cart',
            self::DatenqualitaetGpLa => 'heroicon-o-exclamation-triangle',
        };
    }
}
