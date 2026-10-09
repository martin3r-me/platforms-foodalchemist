<?php

use Illuminate\Database\Migrations\Migration;
use Platform\FoodAlchemist\Models\FoodAlchemistRule;
use Platform\FoodAlchemist\Services\Regeln\RegelBuch;
use Platform\FoodAlchemist\Services\Regeln\RegelService;

/**
 * Spec 81 Paket 3 — erste Regeln als Daten.
 *
 * Diese Regeln ZIEHEN BESTEHENDES VERHALTEN UM (Code-Konstanten und Dossier-Listen, die der Code heute schon
 * durchsetzt). Darum starten sie AKTIV — sonst prüfte der Code nach dem Umzug weniger als vorher. Inhalte 1:1
 * aus dem Code bzw. dem Dossier-Export 05.10.; inhaltliche Änderungen (Spec 81 A4: Olivenöl kalt/heiß,
 * Gelatine nach Fertigungstiefe) kommen als eigene Regeländerung über die Pflegeseite, nicht hier.
 *
 * Quellen: §1.2 Typ-Vokabular = Dossier `…--1-2-typ-vokabular-kontrolliert` (+ „Matte", Freigabe Dominique
 * 09.10.); §2 = Dossier `…-2-verarbeitungs-reduktion…` + `TokenEngine::CUT_FORM_MARKERS`; §5 =
 * `MatchHeuristics::defaultGpAlias()`; §7.1/§10 = `GpNamingService::VERPACKUNGSWOERTER`/`GENERIK_MARKER`;
 * Matching-Marker = `TokenEngine::PROCESSED_MARKERS`; §8.3 = `RecipeConformanceAdapter`.
 */
return new class extends Migration
{
    public function up(): void
    {
        $svc = app(RegelService::class);
        foreach ($this->regeln() as $r) {
            $svc->speichere($r, null, null, true, 'seed');
        }
        RegelBuch::vergessen();
    }

    public function down(): void
    {
        $ids = FoodAlchemistRule::withTrashed()->whereNull('team_id')
            ->whereIn('schluessel', array_column($this->regeln(), 'schluessel'))->pluck('id');
        \Illuminate\Support\Facades\DB::table('foodalchemist_rule_versions')->whereIn('rule_id', $ids)->delete();
        FoodAlchemistRule::withTrashed()->whereIn('id', $ids)->forceDelete();
        RegelBuch::vergessen();
    }

    /** @return list<array<string, mixed>> */
    private function regeln(): array
    {
        $typen = [
            'Fonds & Reduktionen' => ['Fond', 'Jus', 'Demi-Glace', 'Glace', 'Sud', 'Reduktion', 'Essenz', 'Consommé', 'Brühe'],
            'Saucen' => ['Sauce', 'Schaumsauce', 'Buttersauce', 'Beurre Blanc', 'Hollandaise', 'Béarnaise', 'Velouté', 'Béchamel', 'Mayonnaise', 'Curry', 'Steakbutter'],
            'Dressings' => ['Dressing', 'Vinaigrette'],
            'Süße Saucen' => ['Coulis', 'Custard', 'Karamellsauce', 'Schokoladensauce', 'Sirup', 'Glasur'],
            'Beizen & Marinaden' => ['Beize', 'Marinade', 'Rub', 'Würzpaste', 'Pickle-Sud', 'Lake'],
            'Konservierungen' => ['Chutney', 'Kompott', 'Relish', 'Confit', 'Konfitüre', 'Pickle', 'Ferment'],
            'Pürees & Marken' => ['Püree', 'Mark', 'Coulis'],
            'Geleen & Gele' => ['Gel', 'Gelee', 'Aspik', 'Sphäre', 'Fruchtkaviar'],
            'Mousses & Espumas' => ['Mousse', 'Espuma', 'Schaum', 'Bavarois', 'Soufflé'],
            'Cremes & Cremaux' => ['Crème', 'Cremaux', 'Curd', 'Ganache', 'Buttercreme', 'Panna Cotta', 'Pudding', 'Flan', 'Brûlée'],
            'Aufstriche & Pestos' => ['Aufstrich', 'Pesto', 'Dip', 'Tapenade', 'Rillette'],
            'Suppen' => ['Suppe', 'Velouté', 'Crème-Suppe', 'Consommé', 'Eintopf', 'Kaltschale'],
            'Charcuterie & Wurst' => ['Wurst', 'Terrine', 'Pastete', 'Rillette'],
            'Sous-Vide & Garmethoden' => ['Sous-Vide', 'Confit', 'Schmorgericht', 'Garmethode'],
            'Beilagen' => ['Beilage', 'Risotto', 'Polenta', 'Gnocchi', 'Knödel'],
            'Salate' => ['Salat'],
            'Teige & Backwaren' => ['Teig', 'Biskuit', 'Brot', 'Focaccia', 'Brioche', 'Mürbteig', 'Blätterteig', 'Brandteig', 'Macaron'],
            'Knusprige Komponenten' => ['Crumble', 'Tuile', 'Chip', 'Krokant', 'Streusel', 'Crunch', 'Kruste', 'Baiser'],
            'Sorbets, Eis & Granité' => ['Sorbet', 'Eis', 'Granité', 'Parfait', 'Semifreddo'],
            'Pralinen & Petits Fours' => ['Praline', 'Petit Four', 'Marshmallow', 'Trüffel', 'Bonbon', 'Pâte de Fruits'],
            'Getränke' => ['Getränk', 'Sirup', 'Cordial', 'Shrub', 'Limonade', 'Cocktail', 'Mocktail', 'Smoothie'],
            'Aromen & Öle' => ['Aromaöl', 'Öl', 'Aroma', 'Extrakt', 'Tinktur', 'Matte'],
        ];
        $werte = [];
        foreach ($typen as $gruppe => $liste) {
            foreach ($liste as $t) {
                $werte[] = ['wert' => $t, 'gruppe' => $gruppe];
            }
        }

        $eier = 'Eier: frisch, Groesse L, Bodenhaltung';
        $roh = ['prefer_raw' => ['ja']];

        return [
            [
                'schluessel' => 'basisrezept.1.2.typ', 'regelwerk' => 'basisrezept', 'paragraph' => '§1.2', 'titel' => 'Typ-Vokabular',
                'art' => 'vokabular', 'ziel' => 'rezept.name', 'wirkung' => 'korrigieren', 'params' => ['werte' => $werte],
                'beispiele' => ['richtig' => ['Püree', 'Matte', 'Demi-Glace', 'Creme'], 'falsch' => ['Garnitur', 'Gemüsebeilage']],
                'dossier_slug' => 'regelwerk-basisrezepte-10-12-naming-grundprinzip-typ-vokabular--1-2-typ-vokabular-kontrolliert',
                'notiz' => 'Präfix vor dem Doppelpunkt. „Sonstiges" hat keinen festen Typ (Eigenname zulässig) und steht darum nicht in der Liste.',
            ],
            [
                'schluessel' => 'basisrezept.2.schnittform', 'regelwerk' => 'basisrezept', 'paragraph' => '§2', 'titel' => 'Schnittform',
                'art' => 'verbot', 'ziel' => 'gp.verarbeitung', 'wirkung' => 'blockieren',
                'params' => ['tokens' => ['brunoise', 'würfel', 'gehackt', 'geschnitten', 'gerieben', 'gestiftelt', 'stifte', 'scheiben', 'streifen', 'julienne'],
                    'modus' => 'teil', 'grund' => 'Schnittform gehört in die Küche, nicht ins Grundprodukt (§2).'],
                'beispiele' => ['richtig' => ['frisch, ganz'], 'falsch' => ['frisch, Wuerfel 5 mm', 'gewürfelt']],
                'dossier_slug' => 'regelwerk-basisrezepte-2-verarbeitungs-reduktion-brunoise-roh-form',
                'notiz' => 'Gemeinsame Liste: Matching (Schnittform-Malus) und Rohform-Tausch bei frischen GPs (§2).',
            ],
            [
                'schluessel' => 'basisrezept.2.verarbeitung_weitere', 'regelwerk' => 'basisrezept', 'paragraph' => '§2', 'titel' => 'Weitere Verarbeitung (Rohform-Tausch)',
                'art' => 'verbot', 'ziel' => 'gp.verarbeitung', 'wirkung' => 'blockieren',
                'params' => ['tokens' => ['geröstet', 'blanchiert', 'gemahlen', 'püriert', 'in scheiben', 'in streifen'], 'modus' => 'teil',
                    'grund' => 'Verarbeitung in der Küche gehört nicht ins frische Grundprodukt (§2).'],
                'beispiele' => ['richtig' => ['frisch, ganz'], 'falsch' => ['frisch, blanchiert']],
                'dossier_slug' => 'regelwerk-basisrezepte-2-verarbeitungs-reduktion-brunoise-roh-form',
                'notiz' => 'Nur für den §2-Rohform-Tausch frischer GPs; im Matching bewusst kein Malus (gemahlen/geröstet sind dort echte Produkte).',
            ],
            [
                'schluessel' => 'matching.verarbeitet', 'regelwerk' => 'matching', 'paragraph' => null, 'titel' => 'Verarbeitete Ware (Matching)',
                'art' => 'verbot', 'ziel' => 'matching.kandidat', 'wirkung' => 'warnen',
                'params' => ['tokens' => ['konzentrat', 'pulver', 'instant', 'portionsstick', 'fertig', 'vorgegart', 'vorgekocht', 'granulat'], 'modus' => 'teil',
                    'grund' => 'verarbeitete Ware — nur, wenn die Zeile sie verlangt.'],
                'beispiele' => ['richtig' => ['Brühe'], 'falsch' => ['Gemüsebrühe Instant']],
                'notiz' => 'Malus im Matching, wenn die Rezeptzeile keine verarbeitete Ware verlangt.',
            ],
            [
                'schluessel' => 'gp.7.1.gebinde', 'regelwerk' => 'gp', 'paragraph' => '§7.1', 'titel' => 'Verpackungswort im GP-Namen',
                'art' => 'verbot', 'ziel' => 'gp.name', 'wirkung' => 'blockieren',
                'params' => ['tokens' => ['Kiste', 'Karton', 'Beutel', 'Pkt', 'Btl', 'Geb', 'Tasse', 'Dose', 'Glas', 'Stange', 'Atmospack', 'Vac', 'Bund', 'Gebinde'],
                    'grund' => 'Verpackungswort gehört nie in den GP-Namen (§7.1).'],
                'beispiele' => ['richtig' => ['Dosentomate: konserviert, geschält'], 'falsch' => ['Tomaten: konserviert, Dose']],
                'notiz' => 'Ganzes Wort: „Dosentomate" ist erlaubt.',
            ],
            [
                'schluessel' => 'gp.10.generik', 'regelwerk' => 'gp', 'paragraph' => '§10', 'titel' => 'Platzhalter statt Produktname',
                'art' => 'verbot', 'ziel' => 'gp.name', 'wirkung' => 'blockieren',
                'params' => ['tokens' => ['generisch', 'generic'],
                    'grund' => 'kein Produktname — Spezifisches vor Generischem; die konkrete Sorte oder Variante benennen (»Apfel Royal Gala« statt »Apfel (generisch)«) (§10).'],
                'beispiele' => ['richtig' => ['Apfel Royal Gala: frisch, ganz'], 'falsch' => ['Apfel (generisch): frisch']],
            ],
            [
                'schluessel' => 'basisrezept.10.bio', 'regelwerk' => 'basisrezept', 'paragraph' => '§10', 'titel' => 'Bio-Kennzeichnung',
                'art' => 'verbot', 'ziel' => 'gp.attribut', 'wirkung' => 'blockieren',
                'params' => ['tokens' => ['bio'], 'grund' => 'Bio-Grundprodukt ohne Bio-Anspruch im Rezept — Standard vor Bio (§10).'],
                'beispiele' => ['richtig' => ['still'], 'falsch' => ['still, Bio']],
                'notiz' => 'Erkennt Bio im Attribut-Teil des GP-Namens (nach dem Doppelpunkt); das Feld `bio` zählt zusätzlich im Code.',
            ],
            [
                'schluessel' => 'basisrezept.8.3.saetze', 'regelwerk' => 'basisrezept', 'paragraph' => '§8.3', 'titel' => 'Sätze der Beschreibung',
                'art' => 'schwelle', 'ziel' => 'rezept.beschreibung', 'wirkung' => 'warnen',
                'params' => ['vergleich' => 'zwischen', 'min' => 3, 'max' => 5, 'einheit' => 'Sätze'],
                'beispiele' => ['richtig' => ['4'], 'falsch' => ['1']],
            ],
            [
                'schluessel' => 'basisrezept.5.default_gp', 'regelwerk' => 'basisrezept', 'paragraph' => '§5', 'titel' => 'Default-Grundprodukte',
                'art' => 'zuordnung', 'ziel' => 'rezeptzeile', 'wirkung' => 'korrigieren',
                'params' => ['vergleich' => 'tokens', 'eintraege' => [
                    ['begriff' => 'salz', 'ziel_typ' => 'gp', 'ziel_name' => 'Salz / Kochsalz: trocken, unjodiert, Raffinade'],
                    ['begriff' => 'wasser', 'aliase' => ['leitungswasser'], 'ziel_typ' => 'gp', 'ziel_name' => 'Leitungswasser: frisch'],
                    ['begriff' => 'zucker', 'aliase' => ['feinzucker', 'kristallzucker', 'streuzucker', 'raffinadezucker', 'haushaltszucker', 'weisszucker'],
                        'ziel_typ' => 'gp', 'ziel_name' => 'Zucker Raffinade: trocken, weiss'],
                    ['begriff' => 'eigelb', 'ziel_typ' => 'gp', 'ziel_name' => $eier, 'kontext' => $roh],
                    ['begriff' => 'eigelb', 'ziel_typ' => 'gp', 'ziel_name' => 'Eigelb: fluessig, pasteurisiert'],
                    ['begriff' => 'eiweiss', 'ziel_typ' => 'gp', 'ziel_name' => $eier, 'kontext' => $roh],
                    ['begriff' => 'eiweiss', 'ziel_typ' => 'gp', 'ziel_name' => 'Huehnereiweiss: fluessig, pasteurisiert'],
                    ['begriff' => 'ei', 'aliase' => ['eier'], 'ziel_typ' => 'gp', 'ziel_name' => $eier],
                    ['begriff' => 'sahne', 'aliase' => ['schlagsahne'], 'ziel_typ' => 'gp', 'ziel_name' => 'Sahne: konserviert, 30 % Fett'],
                    ['begriff' => 'milch', 'ziel_typ' => 'gp', 'ziel_name' => 'Milch: frisch, 3,5 % Fett'],
                    ['begriff' => 'mehl', 'ziel_typ' => 'gp', 'ziel_name' => 'Weizenmehl: trocken, Type 405'],
                    ['begriff' => 'gelatine', 'ziel_typ' => 'gp', 'ziel_name' => 'Gelatine: trocken, kaltloeslich'],
                    ['begriff' => 'weisswein', 'ziel_typ' => 'gp', 'ziel_name' => 'Weisswein: konserviert, zum Kochen'],
                    ['begriff' => 'olivenöl', 'ziel_typ' => 'gp', 'ziel_name' => 'Olivenoel: trocken, hochwertig'],
                    ['begriff' => 'honig', 'ziel_typ' => 'gp', 'ziel_name' => 'Honig: konserviert, Imker'],
                    ['begriff' => 'sojasauce', 'ziel_typ' => 'gp', 'ziel_name' => 'Sojasauce: konserviert, glutenfrei'],
                    ['begriff' => 'petersilie', 'ziel_typ' => 'gp', 'ziel_name' => 'Petersilie glatt: frisch, gehackt'],
                    ['begriff' => 'tomate', 'aliase' => ['tomaten'], 'ziel_typ' => 'gp', 'ziel_name' => 'Tomaten: frisch, ganz', 'kontext' => $roh],
                    ['begriff' => 'zwiebel', 'aliase' => ['zwiebeln'], 'ziel_typ' => 'gp', 'ziel_name' => 'Zwiebeln: frisch, ganz', 'kontext' => $roh],
                    ['begriff' => 'karotte', 'aliase' => ['karotten', 'möhre', 'möhren', 'mohrrübe', 'mohrrüben'], 'ziel_typ' => 'gp',
                        'ziel_name' => 'Karotten: frisch, ganz', 'kontext' => $roh],
                    ['begriff' => 'pfeffer weiss*', 'ziel_typ' => 'gp', 'ziel_name' => 'Pfeffer weiss: trocken, gemahlen', 'enthaelt' => true],
                    ['begriff' => 'pfeffer', 'ziel_typ' => 'gp', 'ziel_name' => 'Pfeffer schwarz: trocken, gemahlen',
                        'erlaubt' => ['schwarz', 'schwarzer', 'ganz', 'gemahlen']],
                ]],
                'beispiele' => ['richtig' => [['text' => 'salz', 'erwartet' => 'Salz / Kochsalz: trocken, unjodiert, Raffinade'],
                    ['text' => 'pfeffer schwarzer gemahlen', 'erwartet' => 'Pfeffer schwarz: trocken, gemahlen'],
                    ['text' => 'weisser pfeffer', 'erwartet' => 'Pfeffer weiss: trocken, gemahlen'],
                    ['text' => 'eigelb', 'kontext' => ['prefer_raw' => 'ja'], 'erwartet' => $eier]],
                    'falsch' => ['meersalz', 'tomate', 'pfeffer rosa', 'weisse pfefferkoerner']],
                'notiz' => '1:1 aus MatchHeuristics::defaultGpAlias (Stand 10.10.). `prefer_raw` = From Scratch. Basis-Gemüse nur From Scratch; Sellerie bewusst nicht (Knollen/Stauden).',
            ],
        ];
    }
};
