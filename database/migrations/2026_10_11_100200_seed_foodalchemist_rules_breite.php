<?php

use Illuminate\Database\Migrations\Migration;
use Platform\FoodAlchemist\Models\FoodAlchemistRule;
use Platform\FoodAlchemist\Services\Regeln\RegelBuch;
use Platform\FoodAlchemist\Services\Regeln\RegelService;

/**
 * Spec 81 Paket 7 — Breite: Verkaufsgerichte, Kostformen, GP-Pflichtangaben (§8), GP-Anti-Patterns (§12),
 * Mengen im Schritt-Text (VK §3.8).
 *
 * NEUE Prüfungen starten AUS (Aktivieren = Kuration nach Probelauf, Spec 81 Teil G). Aktiv nur, was heute
 * schon fest im Code steht und hier nur umzieht: VK-Marker-Codes und Grammatur im Namen
 * (`DataQualityService::VK_MARKER`/`GRAMMATUR_MUSTER`).
 *
 * Quellen: Regelwerk Verkaufsgerichte §1.1/§1.2/§3.8, Regelwerk Grundprodukte §8/§12 (Vault v3.4.1),
 * Dossier `ernaehrung.kostformen_diaetformen--ethisch-weltanschauliche-formen` (Export 05.10.).
 * Halal/koscher bewusst nicht: es gibt am Rezept kein Feld, das sie auslobt — eine Regel ohne Leser.
 * Käse §8.8 bewusst nicht: drei Merkmale mit offenem Vokabular, als Muster nicht verlässlich.
 */
return new class extends Migration
{
    public function up(): void
    {
        $svc = app(RegelService::class);
        foreach ($this->regeln() as [$aktiv, $r]) {
            $svc->speichere($r, null, $aktiv, 'seed');
        }
        RegelBuch::vergessen();
    }

    public function down(): void
    {
        $ids = FoodAlchemistRule::withTrashed()->whereNull('team_id')
            ->whereIn('schluessel', array_column(array_column($this->regeln(), 1), 'schluessel'))->pluck('id');
        \Illuminate\Support\Facades\DB::table('foodalchemist_rule_versions')->whereIn('rule_id', $ids)->delete();
        FoodAlchemistRule::withTrashed()->whereIn('id', $ids)->forceDelete();
        RegelBuch::vergessen();
    }

    /** @return list<array{0: bool, 1: array<string, mixed>}> */
    private function regeln(): array
    {
        $tierisch = ['fleisch', 'speck', 'schinken', 'wurst', 'salami', 'huhn', 'hähnchen', 'hühnchen', 'geflügel', 'rind', 'kalb',
            'schwein', 'lamm', 'ente', 'gans', 'pute', 'wild', 'reh', 'hirsch', 'kaninchen', 'leber', 'fisch', 'lachs', 'thunfisch',
            'forelle', 'zander', 'dorsch', 'kabeljau', 'garnele', 'krabbe', 'scampi', 'hummer', 'muschel', 'tintenfisch', 'calamar',
            'sardelle', 'anchovis', 'gelatine', 'schmalz', 'aspik', 'fischsauce', 'worcester'];
        $ausnahmenVeg = ['rinde', 'lammsalat', 'muschelnudeln', 'muschelpasta', 'wildkräuter', 'wildreis', 'wildkraeuter', 'vegan', 'vegane',
            'veganer', 'veganes', 'vegetarisch', 'vegetarische', 'veggie', 'mikrobielles', 'kalbsfond vegan'];

        return [
            // — Verkaufsgerichte —
            [false, [
                'schluessel' => 'vk.1.1.hg', 'regelwerk' => 'vk', 'paragraph' => '§1.1', 'titel' => 'Hauptgruppen-Kürzel',
                'art' => 'vokabular', 'ziel' => 'vk.name.hg', 'wirkung' => 'blockieren',
                'params' => ['werte' => array_map(fn ($c) => ['wert' => $c], ['HG', 'DES', 'FIN', 'VOR', 'SUP', 'ZWG', 'BEI', 'AMU', 'KAE', 'BRO', 'GET'])],
                'beispiele' => ['richtig' => ['HG', 'DES'], 'falsch' => ['APE', 'SNK']],
                'dossier_slug' => 'regelwerk.regelwerk_verkaufsgerichte--1-naming-hg-praefix-pipe-skelett-verbindlich-use',
                'notiz' => 'Kürzel in eckigen Klammern vor dem Namen, z. B. „[HG] Rinderfilet | …".',
            ]],
            [false, [
                'schluessel' => 'vk.1.1.bausteine', 'regelwerk' => 'vk', 'paragraph' => '§1.1', 'titel' => 'Anzahl Bausteine',
                'art' => 'schwelle', 'ziel' => 'vk.name.bausteine', 'wirkung' => 'warnen',
                'params' => ['vergleich' => 'zwischen', 'min' => 3, 'max' => 5, 'einheit' => 'Bausteine'],
                'beispiele' => ['richtig' => ['4'], 'falsch' => ['2', '7']],
                'dossier_slug' => 'regelwerk.regelwerk_verkaufsgerichte--1-naming-hg-praefix-pipe-skelett-verbindlich-use',
            ]],
            [true, [
                'schluessel' => 'vk.1.2.marker', 'regelwerk' => 'vk', 'paragraph' => '§1.2', 'titel' => 'Katalog-Codes im Namen',
                'art' => 'verbot', 'ziel' => 'vk.name', 'wirkung' => 'blockieren',
                'params' => ['tokens' => ['CC:', 'STF:', 'MS:', '(SG)', '(BOX)', 'ADD ON', '[FC]'], 'modus' => 'teil',
                    'grund' => 'Marker-/Katalog-Code gehört nicht in den Namen (§1.2).'],
                'beispiele' => ['richtig' => ['[HG] Rinderfilet | Jus | Püree'], 'falsch' => ['CC: Lachs | Dill | Kartoffel']],
                'notiz' => 'Broich-Alt-Artefakte, ersatzlos streichen.',
            ]],
            [true, [
                'schluessel' => 'vk.1.2.grammatur', 'regelwerk' => 'vk', 'paragraph' => '§1.2', 'titel' => 'Gramm-/Größenangabe im Namen',
                'art' => 'verbot', 'ziel' => 'rezept.name.grammatur', 'wirkung' => 'warnen',
                'params' => ['muster' => ['/\(\s*\d+(?:[.,]\d+)?\s*(?:[x×]\s*\d+(?:[.,]\d+)?\s*)?(?:g|kg|mg|ml|cl|l|cm|mm|stk|st)\s*\)/iu'],
                    'grund' => 'Grammatur gehört ins Datenfeld, außer sie unterscheidet zwei sonst gleiche Gerichte (§1.2a).'],
                'beispiele' => ['richtig' => ['[SAN] Mehrkornbrötchen | Käse'], 'falsch' => ['Brötchen (65g)']],
                'notiz' => 'Die §1.2a-Ausnahme (Diskriminator) entscheidet die Datenqualitäts-Prüfung im Code.',
            ]],
            [false, [
                'schluessel' => 'vk.1.2.diaet', 'regelwerk' => 'vk', 'paragraph' => '§1.2', 'titel' => 'Diät-Tag im Namen',
                'art' => 'verbot', 'ziel' => 'vk.name', 'wirkung' => 'warnen',
                'params' => ['muster' => ['/\(\s*(?:vegan|vegetarisch|veggie|glutenfrei|laktosefrei)\s*\)/iu'],
                    'grund' => 'Diät ist Klasse und Pill, nicht Teil des Namens (§1.2).'],
                'beispiele' => ['richtig' => ['[HG] Falafel | Hummus | Couscous'], 'falsch' => ['[HG] Falafel | Hummus (vegan)']],
            ]],
            [false, [
                'schluessel' => 'vk.1.2.fuellwoerter', 'regelwerk' => 'vk', 'paragraph' => '§1.2', 'titel' => 'Verbindungswörter im Namen',
                'art' => 'verbot', 'ziel' => 'vk.name', 'wirkung' => 'warnen',
                'params' => ['tokens' => ['mit', 'auf', 'an', 'dazu'], 'grund' => 'Reine Bausteinliste, keine Sätze (§1.2).'],
                'beispiele' => ['richtig' => ['[HG] Zander | Beurre Blanc | Spinat'], 'falsch' => ['Zander auf Spinat mit Beurre Blanc']],
            ]],
            [false, [
                'schluessel' => 'vk.3.8.schritt_mengen', 'regelwerk' => 'vk', 'paragraph' => '§3.8', 'titel' => 'Absolute Mengen im Schritt-Text',
                'art' => 'verbot', 'ziel' => 'rezept.schritt', 'wirkung' => 'warnen',
                'params' => ['muster' => [
                    '/\b\d+(?:[.,]\d+)?\s*(?:g|kg|mg|ml|cl|dl|l|liter|gramm|stk\.?|stück|el|tl|prise|prisen|bund|zehen?)(?![\p{L}])/iu',
                    '/\b\d+\s+(?:eier|eigelb|eiweiß|eiweiss|zitronen|orangen|limetten)\b/iu',
                ], 'grund' => 'Die Zutatenliste ist die einzige Mengen-Wahrheit — im Schritt Verweis oder Anteil (§3.8). Zeiten, Temperaturen und Größen bleiben erlaubt.'],
                'beispiele' => ['richtig' => ['Das Salz untermischen und 10 Min. bei 180 °C backen.', 'In 5 mm Würfel schneiden.'],
                    'falsch' => ['Mit 10 g Salz mischen.', '2 Eier unterrühren.']],
                'dossier_slug' => 'regelwerk.regelwerk_verkaufsgerichte--3-5-3-8-temperatur-ausgabe-briefing-schritte-ohne-mengen',
            ]],

            // — Kostformen —
            [false, [
                'schluessel' => 'ernaehrung.vegan', 'regelwerk' => 'ernaehrung', 'paragraph' => null, 'titel' => 'Vegan: ausgeschlossene Zutaten',
                'art' => 'verbot', 'ziel' => 'rezeptzeile.vegan', 'wirkung' => 'blockieren',
                'params' => ['teile' => [...$tierisch, 'milch', 'butter', 'sahne', 'rahm', 'käse', 'quark', 'joghurt', 'molke', 'casein', 'kasein',
                    'laktose', 'honig', 'bienenwachs', 'eigelb', 'eiweiß', 'lab'],
                    'tokens' => ['ei', 'eier', 'e120', 'e904', 'karmin', 'schellack'],
                    'ausnahmen' => [...$ausnahmenVeg, 'kokosmilch', 'hafermilch', 'mandelmilch', 'sojamilch', 'reismilch', 'cashewmilch', 'erbsenmilch',
                        'dinkelmilch', 'haselnussmilch', 'kokossahne', 'hafersahne', 'sojasahne', 'pflanzensahne', 'kakaobutter', 'erdnussbutter',
                        'mandelbutter', 'cashewbutter', 'sheabutter', 'butternut', 'butternutkürbis', 'honigmelone', 'sojajoghurt', 'kokosjoghurt'],
                    'grund' => 'Rezept ist als vegan ausgelobt.'],
                'beispiele' => ['richtig' => ['Kokosmilch', 'Butternutkürbis', 'Reis'], 'falsch' => ['Vollmilch 3,5 %', 'Honig', 'Eier: frisch, Groesse L']],
                'dossier_slug' => 'ernaehrung.kostformen_diaetformen--ethisch-weltanschauliche-formen',
                'notiz' => 'Geprüft wird nur bei Rezepten mit „vegan" als Eigenschaft. Versteckte Zutaten (Gelatine, Lab, Fischsauce, Worcester) zählen mit.',
            ]],
            [false, [
                'schluessel' => 'ernaehrung.vegetarisch', 'regelwerk' => 'ernaehrung', 'paragraph' => null, 'titel' => 'Vegetarisch: ausgeschlossene Zutaten',
                'art' => 'verbot', 'ziel' => 'rezeptzeile.vegetarisch', 'wirkung' => 'blockieren',
                'params' => ['teile' => [...$tierisch, 'lab'], 'ausnahmen' => $ausnahmenVeg, 'grund' => 'Rezept ist als vegetarisch ausgelobt.'],
                'beispiele' => ['richtig' => ['Feldsalat', 'Wildkräuter', 'Vollmilch 3,5 %'], 'falsch' => ['Gelatine: trocken, Blatt', 'Speck', 'Fischsauce']],
                'dossier_slug' => 'ernaehrung.kostformen_diaetformen--ethisch-weltanschauliche-formen',
                'notiz' => 'Geprüft wird nur bei Rezepten mit „vegetarisch" als Eigenschaft. Lab im Hartkäse: mikrobielles Lab ist erlaubt.',
            ]],

            // — Grundprodukte §8 Pflichtangaben —
            [false, $this->pflicht('gp.8.1.fett', '§8.1', 'Fettstufe bei Milchprodukten',
                ['milch', 'vollmilch', 'sahne', 'schlagsahne', 'joghurt', 'quark', 'schmand', 'saure sahne', 'crème fraîche', 'kondensmilch'],
                ['muster' => ['/\d+(?:[.,]\d+)?\s*%/u']], 'Fettstufe fehlt, z. B. „3,5 %" (§8.1).', 'Milch: frisch, 3,5 % Fett', 'Milch: frisch')],
            [false, $this->pflicht('gp.8.2.eier', '§8.2', 'Größe bei Eiern', ['ei', 'eier', 'hühnerei', 'hühnereier'],
                ['muster' => ['/(?:^|[\s,:])(?:Gr(?:oe|ö)sse\s+)?(?:S|M|L|XL)(?:$|[\s,\/])/u']], 'Größe fehlt (S, M, L, XL) (§8.2).',
                'Eier: frisch, Groesse L, Bodenhaltung', 'Eier: frisch, Bodenhaltung')],
            [false, $this->pflicht('gp.8.4.garnelen', '§8.4', 'Kaliber bei Garnelen', ['garnele', 'garnelen', '*garnele', '*garnelen'],
                ['muster' => ['/\b\d{1,2}\s*\/\s*\d{1,3}\b/u']], 'Kaliber fehlt, z. B. „16/20" (§8.4).', 'Garnele: TK, geschält, 16/20', 'Garnele: TK, geschält')],
            [false, $this->pflicht('gp.8.9.reis', '§8.9', 'Sorte bei Reis', ['reis', '*reis'],
                ['tokens' => ['basmati', 'jasmin', 'arborio', 'sushi', 'wildreis', 'vollkorn'], 'modus' => 'teil'], 'Sorte fehlt (Basmati, Jasmin, Arborio, Sushi, Wildreis, Vollkorn) (§8.9).',
                'Basmatireis: trocken', 'Reis: trocken')],
            [false, $this->pflicht('gp.8.10.schokolade', '§8.10', 'Kakaoanteil bei Schokolade',
                ['*schokolade', 'schokolade *', 'kuvertüre', '*kuvertüre', 'kuvertüre *', 'couverture', '*couverture', 'couverture *'],
                ['muster' => ['/\d+(?:[.,]\d+)?\s*%/u']], 'Kakaoanteil fehlt, z. B. „70 %" (§8.10).', 'Kuvertüre zartbitter: trocken, 70 %', 'Kuvertüre zartbitter: trocken')],
            [false, $this->pflicht('gp.8.11.mehl', '§8.11', 'Type bei Mehl', ['mehl', '*mehl'],
                ['muster' => ['/\b(?:type\s*)?(?:405|550|630|812|997|1050|1150|1370|1600|1700|00|0)\b/iu'], 'tokens' => ['vollkorn']],
                'Type fehlt (405, 550, 1050, 00, Vollkorn) (§8.11).', 'Weizenmehl: trocken, Type 405', 'Weizenmehl: trocken')],
            [false, $this->pflicht('gp.8.12.kartoffel', '§8.12', 'Kochtyp bei Kartoffeln', ['kartoffel', 'kartoffeln', 'kartoffel *', 'drilling', 'drillinge'],
                ['tokens' => ['mehlig', 'festkochend'], 'modus' => 'teil'], 'Kochtyp fehlt (mehlig, vorwiegend festkochend, festkochend) (§8.12).',
                'Kartoffel Linda: frisch, festkochend', 'Kartoffel: frisch, ganz')],

            // — Grundprodukte §12 Anti-Patterns (der per Muster prüfbare Teil) —
            [false, [
                'schluessel' => 'gp.12.antipattern', 'regelwerk' => 'gp', 'paragraph' => '§12', 'titel' => 'Anti-Patterns im GP-Namen',
                'art' => 'verbot', 'ziel' => 'gp.name', 'wirkung' => 'warnen',
                'params' => ['muster' => ['/_/u', '/\bgetr\./iu', '/\bzum\s+(?:abschmecken|kochen|braten|backen|garnieren|verfeinern)\b/iu', '/\bvon\s+\d/iu'],
                    'grund' => 'Unterstrich, Abkürzung, Verwendungszweck oder Verarbeitungsanweisung gehört nicht in den GP-Namen (§12).'],
                'beispiele' => ['richtig' => ['Aprikose: getrocknet, ganz'], 'falsch' => ['getr._Aprikosen', 'Saft von 3 Zitronen', 'Currypulver zum Abschmecken']],
            ]],
        ];
    }

    private function pflicht(string $schluessel, string $par, string $titel, array $hauptzutat, array $pruefung, string $hinweis, string $ok, string $fehlt): array
    {
        $ztHaupt = trim((string) strstr($ok . ':', ':', true));
        $fhHaupt = trim((string) strstr($fehlt . ':', ':', true));

        return [
            'schluessel' => $schluessel, 'regelwerk' => 'gp', 'paragraph' => $par, 'titel' => $titel,
            'art' => 'pflichtangabe', 'ziel' => 'gp.name', 'wirkung' => 'warnen',
            'params' => ['bedingung' => ['hauptzutat' => $hauptzutat], 'hinweis' => $hinweis] + $pruefung,
            'beispiele' => ['richtig' => [['text' => $ok, 'kontext' => ['hauptzutat' => $ztHaupt]]],
                'falsch' => [['text' => $fehlt, 'kontext' => ['hauptzutat' => $fhHaupt]]]],
            'notiz' => 'Nur flaggen — den Wert erfindet der Code nie.',
        ];
    }
};
