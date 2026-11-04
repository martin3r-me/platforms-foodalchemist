<?php

namespace Platform\FoodAlchemist\Support;

/** Gemeinsame Projektion eines Rechercheartikels; Trend-Relevanz und News-Relevanz bleiben unabhängig. */
final class ObservatoriumInhalt
{
    public const ANSICHTEN = [
        'entdecken' => ['Entdecken', 'heroicon-o-globe-alt'],
        'news' => ['Branchennews', 'heroicon-o-newspaper'],
        'ideen' => ['Ideenwelt', 'heroicon-o-light-bulb'],
        'radar' => ['Trendradar', 'heroicon-o-signal'],
        'markt' => ['Markt & Chancen', 'heroicon-o-building-office-2'],
        'merkliste' => ['Merkliste', 'heroicon-o-bookmark'],
    ];

    public const VERWENDUNGEN = ['news' => 'Branchennews', 'idee' => 'Food-Idee', 'markt' => 'Marktbewegung', 'chance' => 'Chance prüfen'];

    public const BRANCHEN = [
        'kita' => 'Kita & Krippe', 'schule' => 'Schule & Ganztag', 'hochschule' => 'Hochschule & Mensa',
        'betrieb' => 'Betriebsgastronomie', 'care' => 'Care & Senioren', 'restaurant' => 'Restaurant & Bistro',
        'premium' => 'Premiumküche', 'foodtruck' => 'Foodtruck & Streetfood', 'catering' => 'Catering',
        'event' => 'Events & Veranstaltungen', 'hotel' => 'Hotel & Resort', 'cafe' => 'Café & Bäckergastronomie',
        'system' => 'Systemgastronomie', 'handel' => 'Handelsgastronomie', 'reise' => 'Reisegastronomie',
        'freizeit' => 'Freizeitgastronomie', 'delivery' => 'Delivery & To-go',
    ];

    /** Begriffe stammen aus dem Artikel, nie allein aus dem Namen des Google-Suchauftrags. */
    private const BRANCHEN_MUSTER = [
        'kita' => '/\b(kita|kinderkrippe|kindergarten|krippenverpflegung|u3)\w*/u',
        'schule' => '/\b(schul|ganztag|pausenverpflegung)\w*/u',
        'hochschule' => '/\b(hochschul|campus|mensa|studierenden)\w*/u',
        'betrieb' => '/\b(betriebsrestaurant|betriebsgastronomie|kantine|mitarbeiterverpflegung|office lunch|schichtverpflegung)\w*/u',
        'care' => '/\b(care|klinik|krankenhaus|senior|pflege|dysphagie|essen auf raedern)\w*/u',
        'restaurant' => '/\b(restaurant|bistro|brasserie|dining)\w*/u',
        'premium' => '/\b(fine dining|premium|gourmet|sternekueche|spitzenkueche|degustation|haute cuisine|kaviar|trueffel)\w*/u',
        'foodtruck' => '/\b(foodtruck|food truck|foodtrailer|streetfood|street food)\w*/u',
        'catering' => '/\b(cater|buffet|flying dinner|walking dinner)\w*/u',
        'event' => '/\b(event|festival|messe|kongress|konzert|bankett|hochzeit|gala|hospitality)\w*/u',
        'hotel' => '/\b(hotel|resort|roomservice)\w*/u',
        'cafe' => '/\b(cafe|baecker|bakery|coffee shop|patisserie)\w*/u',
        'system' => '/\b(systemgastronomie|fastfood|fast food|fast casual|quick service)\w*/u',
        'handel' => '/\b(handelsgastronomie|food hall|food court|markthalle|supermarktgastronomie)\w*/u',
        'reise' => '/\b(flughafen|bahnhof|bordverpflegung|airline catering|rail catering|airport)\w*/u',
        'freizeit' => '/\b(freizeitpark|zoo|camping|ferienpark|baedergastronomie)\w*/u',
        'delivery' => '/\b(delivery|to.go|lieferkueche|liefergastronomie|dark kitchen|ghost kitchen)\w*/u',
    ];

    public static function einordnen(array $artikel, ?array $ki = null): array
    {
        $text = StichwortFilter::normalisieren(($artikel['titel'] ?? '').' '.($artikel['teaser'] ?? ''));
        $branchen = [];
        foreach (self::BRANCHEN_MUSTER as $branche => $muster) {
            if (preg_match($muster, $text) === 1) {
                $branchen[] = $branche;
            }
        }
        $sparten = ['bildung' => 'schule', 'betriebsgastronomie' => 'betrieb', 'care' => 'care',
            'restaurant_hotel' => 'restaurant', 'event_bankett' => 'event', 'delivery' => 'delivery'];
        if ($branchen === []) {
            foreach ((array) ($artikel['sparten'] ?? []) as $sparte) {
                if (isset($sparten[$sparte])) {
                    $branchen[] = $sparten[$sparte];
                }
            }
        }
        // Hohe Präzision vor Reichweite: eine Food-Messe macht eine Polizeimeldung nicht zu Gastronomie-News.
        $stoerung = preg_match('/\b(original.research|kursziel|aktienanalyse|aktienkurs|taschendieb|festnahme|polizeieinsatz|aufstellungen|bundesliga.ergebnisse|leberuntersuchung|gewebeentnahme|stellenanzeige|bewerbertag)\w*/u', $text) === 1;
        $food = $branchen !== [] || preg_match('/\b(food|gastronom|lebensmittel|speise|getraenk|ernaehr|rezept|zutat|kueche|cuisine|cocktail|ferment|vegan|protein|kulinar|coffee|beverage|menu|chef)\w*/u', $text) === 1;
        $idee = $food && preg_match('/\b(rezept|gericht|menue|zutat|geschmack|aroma|plating|pairing|dessert|cocktail|fermentation|fermentiert|gourmet|speisekonzept|speiseplan|kochverfahren|signature dish|kulinarisch|pflanzenbasiert)\w*/u', $text) === 1;
        $markt = $food && preg_match('/\b(uebernimmt|uebernehmen|uebernahme|betreiber|beauftragt|expansion|eroeffn|neuer standort|preise|kosten|umsatz|marktgroesse|partnerschaft|kooperation|insolvenz|personal|fachkraeft|auftrag)\w*/u', $text) === 1;
        $chance = $food && preg_match('/\b(ausschreibung|vergabeverfahren|teilnahmefrist|angebotsfrist)\w*/u', $text) === 1;
        $verwendungen = $food ? ['news'] : [];
        if ($idee) {
            $verwendungen[] = 'idee';
        }
        if ($markt) {
            $verwendungen[] = 'markt';
        }
        if ($chance) {
            $verwendungen[] = 'chance';
        }

        if ($ki !== null) {
            // Fehlende Felder aus älteren MCP-Antworten verwenden die reproduzierbare Bestandsprojektion.
            if (is_array($ki['verwendungen'] ?? null)) {
                $verwendungen = array_values(array_intersect(array_keys(self::VERWENDUNGEN), array_filter($ki['verwendungen'], 'is_string')));
            }
            if (is_array($ki['branchen'] ?? null)) {
                $branchen = array_values(array_unique(array_merge($branchen, array_intersect(array_keys(self::BRANCHEN), array_filter($ki['branchen'], 'is_string')))));
            }
        }
        if ($stoerung) {
            $verwendungen = [];
        }
        $region = null;
        foreach (['CH' => '/\b(schweiz|swiss|zuerich|basel|bern|luzern)\b/u', 'AT' => '/\b(oesterreich|austria|wien|salzburg|graz|linz)\b/u', 'DE' => '/\b(deutschland|germany|berlin|hamburg|muenchen|duesseldorf|koeln|bremen)\b/u'] as $code => $muster) {
            if (preg_match($muster, $text)) {
                $region = $code;
                break;
            }
        }

        return ['verwendungen' => array_values(array_unique($verwendungen)), 'branchen' => array_values(array_unique($branchen)),
            'region' => $region, 'herkunft' => $ki === null ? 'stichworte' : 'ki',
            'einordnung' => is_string($ki['einordnung'] ?? null) ? mb_substr(trim(strip_tags($ki['einordnung'])), 0, 400) : null,
            'impuls' => is_string($ki['impuls'] ?? null) ? mb_substr(trim(strip_tags($ki['impuls'])), 0, 400) : null];
    }

    public static function inhaltHash(string $titel, string $url, ?string $herausgeber = null): string
    {
        if ($herausgeber !== null && $herausgeber !== '') {
            $titel = preg_replace('/\s+[-–|]\s*'.preg_quote($herausgeber, '/').'\s*$/iu', '', $titel) ?? $titel;
        }
        $titel = preg_replace('/[^\p{L}\p{N}]+/u', ' ', StichwortFilter::normalisieren($titel)) ?? '';

        return hash('sha256', mb_strlen(trim($titel)) >= 40 ? trim($titel) : $url);
    }

    public static function herausgeberUrl(mixed $url): ?string
    {
        if (! is_string($url) || trim($url) === '') {
            return null;
        }
        try {
            return mb_substr(TrendUrl::normalisieren($url), 0, 2048);
        } catch (\RuntimeException) {
            return null;
        }
    }
}
