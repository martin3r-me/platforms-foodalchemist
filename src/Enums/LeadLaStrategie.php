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

    public function beschreibung(): string
    {
        return match ($this) {
            self::GuenstigsterPreis => 'Lead = niedrigster Vergleichspreis (GL-03-Standardkette).',
            self::StammLieferant => 'Artikel der Stamm-Lieferanten (je Warengruppe, M1-06) gewinnen; innerhalb derselben Stufe entscheidet der Preis.',
            self::PrioritaetsKette => 'Feste Lieferanten-Reihenfolge des Teams; innerhalb derselben Stufe entscheidet der Preis.',
        };
    }
}
