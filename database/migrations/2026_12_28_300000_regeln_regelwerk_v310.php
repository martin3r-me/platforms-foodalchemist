<?php

use Illuminate\Database\Migrations\Migration;
use Platform\FoodAlchemist\Models\FoodAlchemistRule;
use Platform\FoodAlchemist\Services\Regeln\RegelBuch;
use Platform\FoodAlchemist\Services\Regeln\RegelService;

/**
 * GP-/Basisrezept-Regelwerk v3.10.0 (Entscheid Dominique 13.11.2026) als Regel-DATEN (Spec 81). Nur bestehende globale Regeln werden geändert;
 * ihr Aktiv-Status bleibt, wie er ist (die §8-Pflichtangaben starteten 11.10. inaktiv, Aktivieren = Kuration; gp.7.1.gebinde, 7.1a und
 * basisrezept.1.2.typ sind aktiv). Jede Änderung = neue Regel-Version (RegelService), Beispiele werden beim Speichern geprüft.
 *
 *  - gp.8.4.garnelen (Regel 824 Nr. 9, F12): Kaliber NACKT als letzte Angabe vor den Klammern („…, 16/20“), ohne „Kaliber“, „Größe“ oder Bezugsgröße.
 *  - gp.7.1.gebinde + gp.7.1a.sammelgebinde (Regel 71a/829, D1): bei Getränken (WG 15) sind auch `Beutel` und `Kanister` Einzelgebinde — `Beutel`
 *    fällt aus der Getränke-Sperrliste; `Kanister` ist außerhalb von WG 15 ein Verpackungswort wie Dose und Glas.
 *  - gp.8.12.kartoffel (Regel 833, P2): Kochtyp nur bei roher ganzer Ware (Zustand frisch, keine Verarbeitung, Zuschnitt höchstens „ganz“;
 *    Kontext-Feld `verarbeitet` aus GpNamingService::validateGpName).
 *  - gp.8.1.fett (Regel 826, M2/M4): keine Fettklassen; der Wert steht exakt als „N % Fett“ bzw. „N % Fett i. Tr.“ — ein nacktes „N %“ reicht nicht.
 *    Butter trägt die Buttersorte statt des Fettwerts und fällt aus der Bedingung.
 *  - basisrezept.1.2.typ (Regel Basisrezepte §16, R3): 11 neue Typen; „Sous-Vide“ und „Garmethode“ sind keine Typen mehr. Die Hauptgruppe der neuen
 *    Typen ist offen (Dossier §1.2) — jeder steht in einer eigenen Gruppe „Hauptgruppe offen: <Typ>“, damit der Typ-Vergleich
 *    (RezeptTypVokabular::gruppenVon) Fleisch und Kuchen nicht für dieselbe Hauptgruppe hält.
 */
return new class extends Migration
{
    public const NEUE_TYPEN = ['Fleisch', 'Fisch', 'Geflügel', 'Gemüse', 'Füllung', 'Gewürzmischung', 'Buttermischung', 'Kuchen', 'Gebäck', 'Pasta', 'Topping'];

    public const KEINE_TYPEN = ['Sous-Vide', 'Garmethode'];

    public function up(): void
    {
        $this->aendere('gp.8.4.garnelen', fn (array $p) => [
            ...$p,
            'bedingung' => ['hauptzutat' => ['garnele', 'garnelen', '*garnele', '*garnelen', 'garnele *', 'garnelen *']],
            'muster' => ['/(?:^|[:,]\s*)\d{1,3}\s*\/\s*\d{1,3}\s*(?:\/\s*\(|$)/u'],
            'hinweis' => 'Kaliber fehlt oder steht nicht nackt am Ende — „16/20“ als letzte Angabe, ohne „Kaliber“, „Größe“ oder „pro Pfund“ (§8.24).',
        ], [
            'richtig' => [['text' => 'Garnele Black Tiger: roh, geschält, TK, 16/20', 'kontext' => ['hauptzutat' => 'Garnele Black Tiger']],
                ['text' => 'Garnele: gekocht, geschält, TK, 13/15 / (ASC)', 'kontext' => ['hauptzutat' => 'Garnele']]],
            'falsch' => [['text' => 'Garnele: gekocht, geschält, TK, Kaliber 13/15', 'kontext' => ['hauptzutat' => 'Garnele']],
                ['text' => 'Garnele: roh, TK, 16/20 pro lb', 'kontext' => ['hauptzutat' => 'Garnele']],
                ['text' => 'Garnele: TK, geschält', 'kontext' => ['hauptzutat' => 'Garnele']]],
        ], 'regelwerk-gp-824-fisch-detail', 'v3.10.0 (13.11.2026): Kaliber nackt als letzte Angabe (Regel 824 Nr. 9).');

        $this->aendere('gp.7.1.gebinde', function (array $p) {
            $p['tokens'] = array_values(array_unique([...(array) ($p['tokens'] ?? []), 'Kanister']));

            return $p;
        }, [
            // nur ein richtig-Beispiel: ein falsch-Beispiel mit „Kanister“ würde jede spätere Token-Pflege zwingen, Kanister mitzuführen
            'richtig' => [['text' => 'Apfelsaft: Kanister 5 l', 'kontext' => ['warengruppe' => '15']]],
        ], null, 'v3.10.0 (13.11.2026): Kanister ist Verpackungswort; bei Getränken (WG 15) Einzelgebinde (Regel 71a).', anhaengen: true);

        $this->aendere('gp.7.1a.sammelgebinde', function (array $p) {
            $p['tokens'] = array_values(array_filter((array) ($p['tokens'] ?? []), fn ($t) => ! in_array($t, ['Beutel', 'Kanister'], true)));
            $p['grund'] = 'Bei Getränken steht nur das Einzelgebinde im GP-Namen (Glasflasche, PET-Flasche, Tetra Pak, Dose, Fass, Bag-in-Box, Beutel, Kanister); '
                . 'Sammelgebinde und andere Verpackungswörter nie (§7.1a, Regel 71a).';

            return $p;
        }, [
            'richtig' => [['text' => 'Capri Orange: Beutel 0,2 l', 'kontext' => ['warengruppe' => '15']],
                ['text' => 'Jasmintee: Beutel, trocken / (Ronnefeldt)', 'kontext' => ['warengruppe' => '15']]],
        ], 'regelwerk-gp-71a-getraenke-gebinde-im-namen', 'v3.10.0 (13.11.2026): Beutel und Kanister sind Getränke-Einzelgebinde (Regel 71a/829).', anhaengen: true);

        $this->aendere('gp.8.12.kartoffel', fn (array $p) => [
            ...$p,
            'bedingung' => [...(array) ($p['bedingung'] ?? []), 'zustand' => ['frisch'], 'verarbeitet' => ['nein']],
            'hinweis' => 'Kochtyp fehlt (mehlig, vorwiegend festkochend, festkochend) — Pflicht nur bei roher ganzer Ware; nie annehmen, ohne Beleg Prüfliste (§8.33).',
        ], [
            'richtig' => [['text' => 'Kartoffel Linda: frisch, festkochend', 'kontext' => ['hauptzutat' => 'Kartoffel Linda', 'zustand' => 'frisch', 'verarbeitet' => 'nein']],
                ['text' => 'Kartoffel: Würfel 5 mm, frisch', 'kontext' => ['hauptzutat' => 'Kartoffel', 'zustand' => 'frisch', 'verarbeitet' => 'ja']],
                ['text' => 'Kartoffel: gegart, geschält, frisch', 'kontext' => ['hauptzutat' => 'Kartoffel', 'zustand' => 'frisch', 'verarbeitet' => 'ja']]],
            'falsch' => [['text' => 'Kartoffel: frisch', 'kontext' => ['hauptzutat' => 'Kartoffel', 'zustand' => 'frisch', 'verarbeitet' => 'nein']]],
        ], 'regelwerk-gp-833-gemuese-kartoffel-zuschnitt', 'v3.10.0 (13.11.2026): Kochtyp nur bei roher ganzer Ware (Regel 833, P2).');

        $this->aendere('gp.8.1.fett', fn (array $p) => [
            'bedingung' => ['hauptzutat' => ['milch', 'vollmilch', 'sahne', 'schlagsahne', 'joghurt', 'quark', 'frischkäse', 'schmand', 'saure sahne',
                'crème fraîche', 'creme fraiche', 'kondensmilch']],
            'muster' => ['/\d+(?:,\d+)?\s%\sFett(?![\p{L}])/u'],
            'hinweis' => 'Fettwert fehlt oder ohne „Fett“: exakt wie im Artikel als „3,5 % Fett“, bei Quark/Frischkäse „20 % Fett i. Tr.“ — keine Fettklassen, jeder Wert ein eigenes GP (§8.26).',
        ], [
            'richtig' => [['text' => 'Milch: frisch, 3,5 % Fett', 'kontext' => ['hauptzutat' => 'Milch']],
                ['text' => 'Quark: frisch, 20 % Fett i. Tr.', 'kontext' => ['hauptzutat' => 'Quark']],
                ['text' => 'Butter: frisch, Süßrahm', 'kontext' => ['hauptzutat' => 'Butter']]],
            'falsch' => [['text' => 'Milch: frisch', 'kontext' => ['hauptzutat' => 'Milch']],
                ['text' => 'Quark: frisch, 40 %', 'kontext' => ['hauptzutat' => 'Quark']]],
        ], 'regelwerk-gp-826-milch-sahne-butter-eier', 'v3.10.0 (13.11.2026): Fettwert exakt, keine Fettklassen, Schreibweise „N % Fett“ (Regel 826).', titel: 'Fettwert bei Milchprodukten');

        $this->aendere('basisrezept.1.2.typ', function (array $p) {
            $werte = array_values(array_filter((array) ($p['werte'] ?? []), fn ($w) => ! in_array((string) ($w['wert'] ?? ''), self::KEINE_TYPEN, true)));
            $vorhanden = array_map(fn ($w) => mb_strtolower((string) ($w['wert'] ?? '')), $werte);
            foreach (self::NEUE_TYPEN as $t) {
                if (! in_array(mb_strtolower($t), $vorhanden, true)) {
                    $werte[] = ['wert' => $t, 'gruppe' => 'Hauptgruppe offen: ' . $t];
                }
            }
            $p['werte'] = $werte;

            return $p;
        }, [
            'falsch' => ['Sous-Vide', 'Garmethode'],
            'richtig' => ['Fleisch', 'Buttermischung'],
        ], null, 'v3.10.0 (13.11.2026): 11 neue Typen (Hauptgruppe offen), Sous-Vide/Garmethode sind keine Typen (Basisrezepte §16).', anhaengen: true);

        RegelBuch::vergessen();
    }

    public function down(): void
    {
        // Bewusst leer: jede Änderung ist eine Regel-Version (foodalchemist_rule_versions); zurück über die Regel-Pflegeseite (Versionen).
    }

    /**
     * Bestehende globale Regel ändern (fehlt sie, nichts tun). Idempotent: ist das Ergebnis gleich dem Bestand, wird nicht gespeichert.
     *
     * @param  callable(array): array  $params
     * @param  array{richtig?: list<mixed>, falsch?: list<mixed>}  $beispiele
     */
    private function aendere(string $schluessel, callable $params, array $beispiele, ?string $dossier, string $notiz, bool $anhaengen = false, ?string $titel = null): void
    {
        $r = FoodAlchemistRule::query()->whereNull('team_id')->where('schluessel', $schluessel)->first();
        if ($r === null) {
            return;
        }
        $neuParams = $params((array) $r->params);
        $alt = (array) ($r->beispiele ?? []);
        $neuBsp = $anhaengen ? [
            'richtig' => $this->ohneDoppel([...(array) ($alt['richtig'] ?? []), ...(array) ($beispiele['richtig'] ?? [])]),
            'falsch' => $this->ohneDoppel([...(array) ($alt['falsch'] ?? []), ...(array) ($beispiele['falsch'] ?? [])]),
        ] : $beispiele + ['richtig' => [], 'falsch' => []];
        if ($neuParams == (array) $r->params && $neuBsp == $alt && ($dossier === null || $r->dossier_slug === $dossier)) {
            return;   // schon auf Stand v3.10.0
        }
        app(RegelService::class)->speichere([...$r->only(['schluessel', 'regelwerk', 'paragraph', 'art', 'ziel', 'wirkung']),
            'titel' => $titel ?? $r->titel, 'params' => $neuParams, 'beispiele' => $neuBsp, 'dossier_slug' => $dossier ?? $r->dossier_slug,
            'notiz' => str_contains((string) $r->notiz, $notiz) ? $r->notiz : trim(((string) $r->notiz) . ' ' . $notiz)],
            null, (bool) $r->aktiv, 'migration');
    }

    /** @param  list<mixed>  $liste */
    private function ohneDoppel(array $liste): array
    {
        $out = [];
        foreach ($liste as $b) {
            $out[json_encode($b, JSON_UNESCAPED_UNICODE)] = $b;
        }

        return array_values($out);
    }
};
