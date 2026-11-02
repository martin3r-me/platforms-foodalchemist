<?php

namespace Platform\FoodAlchemist\Support;

/**
 * Spec 93 E · Sammelthemen erkennen: „Trendprognose 2027“, „Foodtrends 2026“, „Ausblick“ … bündeln jede Studie mit
 * jeder anderen und zählen kein konkretes Signal. Der Prompt verbietet sie; durchgesetzt wird es hier (Kurator 02.11.:
 * ein Prompt-Verbot allein ist keine Durchsetzung).
 *
 * Sammelthema = das Thema besteht nur aus Rubrikwörtern, Füllwörtern und Zahlen („Trendprognose 2027“, „Top 10 Food
 * Trends“). „Ube-Trend 2027“, „Fermentation“, „Topping“ oder „Reportage-Dinner“ bleiben erlaubt.
 */
final class TrendSammelthema
{
    /** Rubrikwörter, ganz (normalisiert: klein, Umlaute ausgeschrieben). Dazu jedes Wort auf „…trend“/„…trends“. */
    private const RUBRIK = ['prognose', 'prognosen', 'trendprognose', 'trendprognosen', 'ausblick', 'vorhersage', 'vorhersagen',
        'prediction', 'predictions', 'forecast', 'forecasts', 'outlook', 'report', 'reports', 'trendreport', 'trendreports',
        'rueckblick', 'jahresrueckblick', 'top'];

    /** Füllwörter, die ein reines Rubrik-Thema nicht konkret machen. */
    private const FUELL = ['food', 'foods', 'getraenke', 'beverage', 'beverages', 'drink', 'drinks', 'and', 'und', 'der', 'die', 'des',
        'fuer', 'for', 'the', 'neue', 'new', 'global', 'globale', 'flavor', 'flavour'];

    public static function ist(string $thema): bool
    {
        $woerter = array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', StichwortFilter::normalisieren($thema)) ?: [], fn ($w) => $w !== ''));
        if (array_filter($woerter, [self::class, 'istRubrik']) === []) {
            return false;
        }
        // Zahlen (Jahr, „Top 10“) machen nichts konkret; ein einziges Sachwort schon („Ube-Trend 2027“ bleibt)
        $rest = array_filter($woerter, fn ($w) => ! self::istRubrik($w) && ! in_array($w, self::FUELL, true) && preg_match('/^\d+$/', $w) !== 1);

        return $rest === [];
    }

    private static function istRubrik(string $wort): bool
    {
        return in_array($wort, self::RUBRIK, true) || preg_match('/trends?$/', $wort) === 1;
    }
}
