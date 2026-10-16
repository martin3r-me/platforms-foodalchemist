<?php

namespace Platform\FoodAlchemist\Support;

/**
 * Die Seed-Gruppen der Lieferanten-Taxonomie und der Vorschlag aus Necta-Branche und Name.
 *
 * Der Vorschlag ist bewusst vorsichtig: trifft kein Muster, bleibt die Gruppe leer (Prüfliste) — Mischsortimenter wie Handelshof, Hanos oder
 * Chefs Culinar sind „Lebensmittel-Großhandel“, auch wenn sie Non-Food führen (das Non-Food-Urteil entscheidet je Artikel). Von Hand gesetzte
 * Gruppen überschreibt der Vorschlag nie.
 */
final class LieferantenGruppen
{
    public const LEBENSMITTEL = 'LM';

    public const FRISCH = 'FRISCH';

    public const GETRAENKE = 'GETRAENKE';

    public const NONFOOD = 'NONFOOD';

    public const VERLEIH = 'VERLEIH';

    public const SONSTIGES = 'SONSTIGES';

    /** @return array<string, string> code => Name (Reihenfolge = sort_order) */
    public static function seed(): array
    {
        return [
            self::LEBENSMITTEL => 'Lebensmittel-Großhandel',
            self::FRISCH => 'Frische & Fachhandel',
            self::GETRAENKE => 'Getränke',
            self::NONFOOD => 'Non-Food & Verbrauch',
            self::VERLEIH => 'Verleih & Eventausstattung',
            self::SONSTIGES => 'Sonstiges',
        ];
    }

    /** Gruppen, deren Artikel ohne Food-Daten als Non-Food gelten (Signal für die Non-Food-Erkennung). */
    public const NONFOOD_GRUPPEN = [self::NONFOOD, self::VERLEIH];

    /**
     * Reihenfolge ist Priorität: Verleih und Non-Food vor Getränken und Frische, damit „Getränke-Verpackung GmbH“ nicht als Getränk endet.
     *
     * @var array<string, string> code => Regex (u + i)
     */
    private const MUSTER = [
        self::VERLEIH => '/verleih|vermiet|eventausstatt|mietgesch|leihgesch|\bleih/iu',
        self::NONFOOD => '/hygien|reinig|einweg|verpack|büro|buero|technik|berufsbekleid|arbeitskleid|textil|non[\s-]?food|haushalt|papier|bunzl/iu',
        self::GETRAENKE => '/getränk|getraenk|brauerei|brunnen|\bbier|\bwein|spirituos|limonad|mineralwass|fass\s*&\s*flasche|safthandel|kaffee/iu',
        self::FRISCH => '/fleisch|metzger|fisch|geflügel|gefluegel|obst|gemüse|gemuese|frische|molkerei|käse|kaese|backwaren|bäcker|baecker|tiefkühl|tiefkuehl|frost|eier\b/iu',
        self::LEBENSMITTEL => '/gro(ß|ss)handel|cash\s*&\s*carry|handelshof|\bmetro\b|hanos|chefs\s*culinar|\bbos\b|food\b|gastro|delikatess|feinkost|zeelandia|epos|bio\s*partner/iu',
    ];

    /** @return ?string Gruppen-Code oder null (unklar → Prüfliste) */
    public static function vorschlag(?string $branche, ?string $name): ?string
    {
        $text = trim((string) $branche.' '.(string) $name);
        if ($text === '') {
            return null;
        }
        foreach (self::MUSTER as $code => $muster) {
            if (preg_match($muster, $text) === 1) {
                return $code;
            }
        }

        return null;
    }
}
