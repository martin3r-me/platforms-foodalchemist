<?php

namespace Platform\FoodAlchemist\Support;

/**
 * Regelstufe der Non-Food-Erkennung am Lieferantenartikel (Stufe 1 von 3: Regel → KI → Prüfliste).
 *
 * Drei Ergebnisse: `nonfood` (sicher), `food` (kein Signal) und `unklar` (geht an die KI). Wichtigste Leitplanke: FEHLENDE
 * Allergene/Nährwerte allein heissen NICHT Non-Food — gemessen haben 121.424 von 264.514 Artikeln keinerlei Food-Daten, die
 * allermeisten sind Getränke und Lebensmittel ohne Detailpflege. Ein Non-Food-Urteil braucht deshalb ein POSITIVES Signal
 * (starkes Wort in der Bezeichnung oder Non-Food-Lieferant) UND das Fehlen von Food-Daten. Starkes Wort MIT Food-Daten
 * (Allergen-, Nährwert-, Deklarationszeile, Zutatenliste, Alkohol) ist unklar, nie Non-Food.
 *
 * Wörter sind GANZE Wörter mit Beugungsendung (Serviette/Servietten, aber nicht Serviettenknödel), nur die gekennzeichneten
 * Stämme dürfen vorn oder hinten weiterlaufen (Backofenreiniger, Einweghandschuhe). Mehrdeutige Gebinde-Wörter (Becher,
 * Schale, Eimer, Dose, Karton, Spray …) sind nie allein ein Urteil: sie machen den Artikel „unklar“.
 */
final class NonFoodRegeln
{
    public const SUB_REINIGUNG = 'Reinigung & Hygiene';

    public const SUB_EINWEG = 'Einweg & Verpackung';

    public const SUB_KUECHE = 'Küchenbedarf & Kleingeräte';

    public const SUB_KLEIDUNG = 'Arbeitskleidung & Schutz';

    public const SUB_DEKO = 'Deko & Tischkultur';

    public const SUB_BUERO = 'Büro & Sonstiges';

    /** Gekauftes UND gemietetes Geschirr hängen am selben Non-Food-GP (Dominique 2026-10-16): Teller, Tassen, Gläser, Besteck, Platten. */
    public const SUB_GESCHIRR = 'Geschirr & Ausstattung';

    /** Warengruppen-Code und Name der Non-Food-Warengruppe. */
    public const WG_CODE = '16';

    public const WG_NAME = '16 Non-Food';

    /** @return list<string> */
    public static function unterkategorien(): array
    {
        return [self::SUB_REINIGUNG, self::SUB_EINWEG, self::SUB_KUECHE, self::SUB_KLEIDUNG, self::SUB_DEKO, self::SUB_GESCHIRR, self::SUB_BUERO];
    }

    /**
     * Starke Wörter je Unterkategorie. Regex-Bausteine OHNE Begrenzer; `*` am Anfang/Ende = darf weiterlaufen.
     * Groß/klein und Umlaute regelt der Aufrufer (u + i).
     *
     * @var array<string, list<string>>
     */
    private const STARK = [
        self::SUB_REINIGUNG => [
            '*reiniger', 'desinfek*', 'spülmittel', 'geschirrspül*', 'klarspüler', 'entkalker', 'seife(?:n)?', 'handwasch*', 'waschmittel',
            'müllsack', 'müllsäcke', 'müllbeutel', 'abfallsack', 'abfallsäcke', 'toilettenpapier', 'handtuchpapier', 'papierhandtuch', 'papierhandtücher',
            'putztuch', 'putztücher', 'wischtuch', 'wischtücher', 'geschirrtuch', 'geschirrtücher', 'spülschwamm', 'topfschwamm', 'reinigungsschwamm',
            'mopp(?:s)?', 'wischmopp', 'besen', 'kehrschaufel', 'kehrblech', 'bürste(?:n)?', 'mikrofaser*', 'sprühflasche', 'deo', 'deodorant', 'w\.?\s?gr\.?\s?38',
        ],
        self::SUB_EINWEG => [
            'einweg*', 'bagasse', 'alufolie', 'frischhaltefolie', 'backpapier', 'pergament*', 'gefrierbeutel', 'vakuumbeutel', 'gefrierbox', 'frischhaltebox',
            'to[\s-]?go[\s-]?box', 'burgerbox', 'menübox', 'pizzakarton', 'strohhalm*', 'trinkhalm*', 'pappbecher', 'pappteller', 'partyteller',
            'holzspieß(?:e)?', 'zahnstocher', 'etikett(?:en)?', 'klebeband',
        ],
        self::SUB_KLEIDUNG => [
            'handschuh(?:e)?', '*handschuh(?:e)?', 'kochjacke(?:n)?', 'kochhose(?:n)?', 'schürze(?:n)?', 'kochmütze(?:n)?', 'haarnetz(?:e)?', 'schutzbrille', 'mundschutz',
            'nitril*', 'kleidung',
        ],
        self::SUB_DEKO => [
            'kerze(?:n)?', 'teelicht(?:er)?', 'lampion(?:s)?', 'tischdecke(?:n)?', 'tischset(?:s)?', 'tischläufer', 'serviette(?:n)?', 'luftballon(?:s)?', 'girlande(?:n)?', 'konfetti',
            'led-lampe(?:n)?', 'leuchtmittel', 'glühbirne(?:n)?',
            // 16.10.2026, an den echten Bezeichnungen belegt (Zahlen: docs/PLANUNG/_NonFood_Regeln_Belege_2026-10-16.md): reine Deko-Wörter ohne GP-Zuordnung
            'windlicht(?:er)?', 'vase(?:n)?', 'untersetzer', 'kerzenhalter',
        ],
        self::SUB_GESCHIRR => [
            'speiseteller', 'suppenteller', 'dessertteller', 'frühstücksteller', 'platzteller', 'kaffeetasse(?:n)?', 'teetasse(?:n)?', 'espressotasse(?:n)?', 'cappuccinotasse(?:n)?',
            'weinglas', 'weingläser', 'sektglas', 'sektgläser', 'wasserglas', 'wassergläser', 'biergl(?:as|äser)', 'whiskyglas', 'longdrinkglas', 'besteck(?:e|s)?', 'servierplatte(?:n)?',
            // 16.10.2026 belegt (siehe Doku): Material-/Gerätewörter ohne GP-Zuordnung
            'melamin', 'porzellan', 'tablett(?:s)?', 'schüssel(?:n)?',
        ],
        self::SUB_KUECHE => [
            'schneebesen', 'thermometer', 'messbecher', 'backblech(?:e)?', 'dosenöffner', 'korkenzieher', 'küchenmesser', 'kochlöffel', 'sparschäler', 'gn-behälter', 'gastronormbehälter',
            'edelstahl', 'kunststoff', 'schneidebrett(?:er)?', 'schneidbrett(?:er)?', 'backform(?:en)?', 'lampe(?:n)?',
        ],
        self::SUB_BUERO => [
            'kugelschreiber', 'bleistift(?:e)?', 'batterie(?:n)?', 'feuerzeug(?:e)?', 'streichholz', 'streichhölzer', 'kassenrolle(?:n)?', 'thermorolle(?:n)?', 'schreibblock',
        ],
    ];

    /** Mehrdeutige Wörter: Gebinde oder Geschirr — allein nie ein Urteil, nur „unklar“. */
    private const MEHRDEUTIG = [
        'becher', 'teller', 'schale', 'löffel', 'gabel', 'messer', 'glas', 'gläser', 'flasche', 'dose', 'tüte', 'beutel', 'folie', 'papier', 'tuch', 'tücher',
        'vase', 'box', 'karton', 'korb', 'körbe', 'krug', 'kanne', 'tasse', 'pfanne', 'topf', 'form', 'gitter', 'zange', 'sieb', 'brett', 'eimer', 'spray', 'deckel',
        'behälter', 'kiste', 'platte', 'halter', 'ständer', 'spender', 'etikett', 'spieß', 'spieße',
    ];

    /**
     * Wörter, die ein starkes Non-Food-Wort ENTWERTEN: das starke Wort steht dann an einer Ware (Koppert „Venus Vase“ = Kresse in der Glasvase,
     * Belege in den io-Daten). Der Artikel fällt auf „mehrdeutig/unklar“ zurück und geht an die KI statt automatisch auf Non-Food.
     */
    private const AUSNAHMEN = ['koppert', 'kresse', 'microgreens'];

    /** Lieferanten-Branche bzw. -Name, die für sich Non-Food bedeutet, mit Unterkategorie (null = nur Non-Food, Unterkategorie offen). */
    private const BRANCHE = [
        '/hygien|reinig/iu' => self::SUB_REINIGUNG,
        '/einweg|verpack/iu' => self::SUB_EINWEG,
        '/berufsbekleid|arbeitskleid|textil/iu' => self::SUB_KLEIDUNG,
        '/büro|buero|technik/iu' => self::SUB_BUERO,
        '/non[\s-]?food|haushalt/iu' => null,
    ];

    /** @var array<string, string>|null */
    private static ?array $starkRegex = null;

    private static ?string $mehrdeutigRegex = null;

    /**
     * @param  array{bezeichnung:?string, verkaufsname?:?string, branche?:?string, lieferant?:?string, gruppe?:?string, hat_food_daten:bool, hat_echte_food_daten?:bool}  $a
     * @return array{klasse:'nonfood'|'food'|'unklar', subkategorie:?string, konfidenz:?float, grund:?string}
     */
    public static function bewerte(array $a): array
    {
        $text = trim((string) ($a['bezeichnung'] ?? '').' '.(string) ($a['verkaufsname'] ?? ''));
        $hatFood = (bool) $a['hat_food_daten'];
        // Zwei Schwellen: `hat_food_daten` (weit: irgendeine Zeile) hält mehrdeutige Wörter bei Food-Artikeln aus der KI; `hat_echte_food_daten` (eng: GP-Zuordnung,
        // positives Allergen, Nährwerte > 0, Zutatenliste, Alkohol) entscheidet bei einem STARKEN Wort zwischen Non-Food und unklar. Leere Import-Zeilen
        // (Allergene/Nährwerte/Deklarationen ohne Wert) sind kein Food-Beleg: sie hingen an 2.748 offensichtlichen Non-Food-Artikeln und schickten sie an die KI.
        $hatEchteFood = array_key_exists('hat_echte_food_daten', $a) ? (bool) $a['hat_echte_food_daten'] : $hatFood;

        $brancheSub = null;
        $brancheTreffer = in_array((string) ($a['gruppe'] ?? ''), LieferantenGruppen::NONFOOD_GRUPPEN, true);   // gepflegte Lieferanten-Gruppe (Non-Food/Verleih) vor dem Muster
        foreach (self::BRANCHE as $muster => $sub) {
            if (preg_match($muster, (string) ($a['branche'] ?? '').' '.(string) ($a['lieferant'] ?? '')) === 1) {
                $brancheTreffer = true;
                $brancheSub ??= $sub;
            }
        }

        $stark = self::starkTreffer($text);
        if ($stark !== null && preg_match('/(?<![\p{L}\p{N}])(?:'.implode('|', self::AUSNAHMEN).')(?![\p{L}\p{N}])/iu', $text) === 1) {
            $stark = null;   // starkes Wort an einer Ware: nicht automatisch Non-Food
        }
        $mehrdeutig = $stark === null && self::mehrdeutigTreffer($text);

        if ($stark !== null || $brancheTreffer) {
            if ($hatEchteFood) {
                return ['klasse' => 'unklar', 'subkategorie' => $stark ?? $brancheSub, 'konfidenz' => null,
                    'grund' => 'Non-Food-Signal, aber Allergen-/Nährwert-/Zutatendaten vorhanden'];
            }

            return ['klasse' => 'nonfood', 'subkategorie' => $stark ?? $brancheSub, 'konfidenz' => $stark !== null ? 0.95 : 0.9,
                'grund' => $stark !== null ? 'Bezeichnung: Non-Food-Begriff' : 'Lieferanten-Branche Non-Food'];
        }
        if ($mehrdeutig && ! $hatFood) {
            return ['klasse' => 'unklar', 'subkategorie' => null, 'konfidenz' => null, 'grund' => 'mehrdeutiges Gebinde-/Geschirrwort ohne Food-Daten'];
        }

        return ['klasse' => 'food', 'subkategorie' => null, 'konfidenz' => null, 'grund' => null];
    }

    /** Unterkategorie des ersten starken Treffers oder null. */
    private static function starkTreffer(string $text): ?string
    {
        self::$starkRegex ??= self::baueStarkRegex();
        foreach (self::$starkRegex as $sub => $regex) {
            if (preg_match($regex, $text) === 1) {
                return $sub;
            }
        }

        return null;
    }

    private static function mehrdeutigTreffer(string $text): bool
    {
        self::$mehrdeutigRegex ??= '/(?<![\p{L}\p{N}])(?:'.implode('|', array_map(fn (string $w) => preg_quote($w, '/'), self::MEHRDEUTIG)).')(?![\p{L}\p{N}])/iu';

        return preg_match(self::$mehrdeutigRegex, $text) === 1;
    }

    /** @return array<string, string> */
    private static function baueStarkRegex(): array
    {
        $links = '(?<![\p{L}\p{N}])';
        $rechts = '(?![\p{L}\p{N}])';
        $ergebnis = [];
        foreach (self::STARK as $sub => $woerter) {
            $teile = [];
            foreach ($woerter as $w) {
                $vorn = str_starts_with($w, '*');
                $hinten = str_ends_with($w, '*');
                $kern = trim($w, '*');
                $teile[] = ($vorn ? '[\p{L}\p{N}]*' : $links).$kern.($hinten ? '[\p{L}\p{N}]*' : $rechts);
            }
            $ergebnis[$sub] = '/(?:'.implode('|', $teile).')/iu';
        }

        return $ergebnis;
    }
}
