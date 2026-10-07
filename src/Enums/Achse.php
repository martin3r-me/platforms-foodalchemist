<?php

namespace Platform\FoodAlchemist\Enums;

/**
 * Spec 60 · P4: feste Achsen für Bedarf und Eigenschaft eines Ankers (Vokabular aus dem
 * Pilot der Dossier-Auslese, 2026-10-06). Eine Aussage, die auf keine Achse passt, wird
 * nicht in eine neue Achse gepresst, sondern bleibt als Text offen.
 *
 * Kontrast = ein Bestandteil braucht eine Achse, ein anderer liefert sie.
 */
enum Achse: string
{
    // Geschmack
    case Saeure = 'saeure';
    case Suesse = 'suesse';
    case Salz = 'salz';
    case Bitter = 'bitter';
    case Umami = 'umami';
    case Schaerfe = 'schaerfe';
    case Fett = 'fett';
    // Textur
    case Knusprig = 'knusprig';
    case Cremig = 'cremig';
    case Bissfest = 'bissfest';
    case Weich = 'weich';
    case Saftig = 'saftig';
    // weitere Bedarfe
    case Traeger = 'traeger';
    case Frische = 'frische';
    case Roestaroma = 'roestaroma';
    case Aromatik = 'aromatik';
    case Kaelte = 'kaelte';
    case HitzeKurz = 'hitze_kurz';

    public function label(): string
    {
        return match ($this) {
            self::Saeure => 'Säure',
            self::Suesse => 'Süße',
            self::Salz => 'Salz',
            self::Bitter => 'Bitterkeit',
            self::Umami => 'Umami',
            self::Schaerfe => 'Schärfe',
            self::Fett => 'Fett',
            self::Knusprig => 'Knusper',
            self::Cremig => 'Cremigkeit',
            self::Bissfest => 'Biss',
            self::Weich => 'Weichheit',
            self::Saftig => 'Saftigkeit',
            self::Traeger => 'Träger',
            self::Frische => 'Frische',
            self::Roestaroma => 'Röstaroma',
            self::Aromatik => 'kräftige Aromatik',
            self::Kaelte => 'Kälte',
            self::HitzeKurz => 'kurze Hitze',
        };
    }

    /** Achsen, die ein anderer Bestandteil liefern kann (Kälte/kurze Hitze sind Zubereitungs-Bedarfe). */
    public function lieferbar(): bool
    {
        return ! in_array($this, [self::Kaelte, self::HitzeKurz], true);
    }
}
