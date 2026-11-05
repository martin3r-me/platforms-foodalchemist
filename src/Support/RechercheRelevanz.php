<?php

namespace Platform\FoodAlchemist\Support;

/** Präziser Vorfilter vor Eingang und KI; keine allgemeine Branchen-Relevanzheuristik. */
final class RechercheRelevanz
{
    public static function grund(string $titel, ?string $teaser = null): ?string
    {
        $text = StichwortFilter::normalisieren($titel.' '.($teaser ?? ''));
        // Konkreter gastronomischer Inhalt hat Vorrang vor einer Sport-/Polizei-Erwähnung.
        if (preg_match('/\b(cater\w*|stadionverpflegung|speis\w*|getraenk\w*|gastronom\w*|restaurant\w*|kantin\w*|lebensmittel\w*|foodtruck\w*|koch\w*|kueche\w*)\b/u', $text)) {
            return null;
        }
        foreach ([
            'Sportmeldung ohne Gastronomiebezug' => '/\b(aufstellungen|startelf|transfergeruecht\w*|bundesliga.ergebnisse|champions.league.ergebnisse|elfmeter\w*|spielbericht\w*)\b/u',
            'Börsenanalyse ohne Gastronomiebezug' => '/\b(kursziel|aktienanalyse|aktienempfehlung|original.research)\b/u',
            'Polizeimeldung ohne Gastronomiebezug' => '/\b(fahndung|taschendieb\w*|verkehrsunfall|polizeieinsatz|festnahme)\b/u',
        ] as $grund => $muster) {
            if (preg_match($muster, $text)) {
                return $grund;
            }
        }

        return null;
    }
}
