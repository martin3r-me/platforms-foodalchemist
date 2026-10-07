<?php

namespace Platform\FoodAlchemist\Enums;

/**
 * M1-05 / V-27: Team-Strategie für die Lead-LA-Wahl (speist LeadLaService, M3-06).
 */
enum LeadLaStrategie: string
{
    case GuenstigsterPreis = 'guenstigster_preis';
    case StammLieferant = 'stamm_lieferant';
    case PrioritaetsKette = 'prioritaets_kette';

    public function label(): string
    {
        return match ($this) {
            self::GuenstigsterPreis => 'Günstigster Preis',
            self::StammLieferant => 'Stamm-Lieferant zuerst',
            self::PrioritaetsKette => 'Prioritäts-Kette',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::GuenstigsterPreis => 'Der Artikel mit dem niedrigsten Vergleichspreis liefert den Preis.',
            self::StammLieferant => 'Artikel der Stamm-Lieferanten (je Warengruppe) haben Vorrang. Innerhalb derselben Stufe entscheidet der Preis.',
            self::PrioritaetsKette => 'Eine feste Reihenfolge der Lieferanten entscheidet. Innerhalb derselben Stufe entscheidet der Preis.',
        };
    }
}
