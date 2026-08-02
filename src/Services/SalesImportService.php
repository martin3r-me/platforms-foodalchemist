<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistSalesFact;
use RuntimeException;

/**
 * Spec 32 · C3 — Einlesen des Verkaufs-Ist (CSV) ins {@see FoodAlchemistSalesFact}-Journal.
 *
 * **Warum ein Spalten-Mapping statt eines festen Formats.** Es gibt kein „das" Kassen- oder
 * Abrechnungsformat; jeder Betrieb exportiert anders. Ein Import, der auf eine bestimmte
 * Kopfzeile wartet, wäre auf genau einen Kunden festgelegt und bis zu dessen Beispieldatei
 * blockiert. Stattdessen liest {@see self::kopf} die Datei, schlägt eine Zuordnung vor, und
 * der Mensch bestätigt sie. Das Format ist damit eine Eingabe, keine Annahme.
 *
 * **Warum nur CSV/TSV.** Ein xlsx-Reader hieße eine neue Composer-Abhängigkeit im Modul —
 * dieselbe bewusste Grenze wie in {@see FileArticleImportService::liesDatei}. Der Reader
 * nennt den Weg beim Namen („als CSV exportieren") statt zu scheitern.
 *
 * **Trockenlauf ist der Default.** `$apply = false` schreibt nichts und liefert denselben
 * Bericht, den der scharfe Lauf liefern würde (Create/Update/Skip/Konflikt) — ein Import,
 * dessen Wirkung man erst nach dem Schreiben sieht, ist keiner.
 *
 * **Ungematchte Zeilen bleiben liegen.** Eine Verkaufsposition ohne erkennbares Gericht wird
 * mit `recipe_id = null` und ihrem Roh-Text geschrieben und später von Hand zugeordnet. Sie
 * zu verwerfen hieße, Umsatz still aus der Auswertung fallen zu lassen — und genau dieser
 * Umsatz fehlt dann in der Ist-Wareneinsatzquote.
 */
class SalesImportService
{
    /**
     * Basis-Ablage relativ zu `storage/app`. Die tatsächliche Ablage ist **je Team** ein
     * eigener Unterordner ({@see self::ordnerFuer}).
     *
     * Der Artikel-Import teilt sich einen flachen Ordner über alle Teams. Für Verkaufsdaten
     * geht das nicht: ein geteilter Ordner hiesse, dass Betrieb A die Dateinamen von Betrieb B
     * sieht, dessen Datei überschreiben und — schlimmer — dessen UMSÄTZE ins eigene Journal
     * einlesen kann. Das ist über die UI und über `sales_import.POST` erreichbar, also wird
     * hier je Team getrennt.
     */
    public const ORDNER = 'foodalchemist/sales-import';

    /** Team-eigene Ablage — die einzige Stelle, die einen Pfad bildet. */
    public static function ordnerFuer(Team $team): string
    {
        return self::ORDNER . '/' . (int) $team->id;
    }

    /** Die Felder, auf die eine Spalte gelegt werden kann. `bereich` und `menge` sind optional. */
    public const FELDER = ['bezeichnung', 'menge', 'umsatz', 'datum', 'bereich'];

    /** Ab diesem Token-Überlappungswert gilt ein Gericht als getroffen. */
    private const TOKEN_SCHWELLE = 0.60;

    /**
     * Kopfzeile lesen + Zuordnung vorschlagen. Der Vorschlag ist eine Bequemlichkeit,
     * keine Wahrheit — bestätigt wird er von Hand.
     *
     * @return array{spalten: list<string>, vorschlag: array<string,int>, beispiel: list<array<int,string>>, trenner: string}
     */
    public function kopf(Team $team, string $dateiname, int $beispielZeilen = 3): array
    {
        [$kopfFelder, $zeilen, $trenner] = $this->lies($team, $dateiname);

        $vorschlag = [];
        foreach ($kopfFelder as $idx => $titel) {
            $feld = $this->rateFeld((string) $titel);
            // Erste Spalte gewinnt: eine Datei mit zwei „Umsatz"-Spalten ist keine Vorlage,
            // sondern ein Rätsel — das löst der Mensch, nicht die Heuristik.
            if ($feld !== null && ! isset($vorschlag[$feld])) {
                $vorschlag[$feld] = $idx;
            }
        }

        return [
            'spalten' => array_map(fn ($t) => trim((string) $t), $kopfFelder),
            'vorschlag' => $vorschlag,
            'beispiel' => array_slice($zeilen, 0, max(0, $beispielZeilen)),
            'trenner' => $trenner,
        ];
    }

    /**
     * Import-Lauf. Ohne `$apply` wird nichts geschrieben.
     *
     * @param  array<string,int>  $mapping  Feldname => Spalten-Index (mind. bezeichnung, umsatz, datum)
     * @return array{apply:bool,gelesen:int,neu:int,aktualisiert:int,uebersprungen:int,gematcht:int,ungematcht:int,umsatz:float,fehler:list<string>,batch_id:?string}
     */
    public function importiere(Team $team, string $dateiname, array $mapping, bool $apply = false, ?string $batchId = null): array
    {
        foreach (['bezeichnung', 'umsatz', 'datum'] as $pflicht) {
            if (! isset($mapping[$pflicht])) {
                throw new RuntimeException("Pflicht-Zuordnung fehlt: {$pflicht}.");
            }
        }

        [, $zeilen] = $this->lies($team, $dateiname);
        $batchId ??= 'sales-' . substr(sha1($dateiname . microtime(false)), 0, 12);

        $katalog = $this->katalog($team);

        $bericht = ['apply' => $apply, 'gelesen' => 0, 'neu' => 0, 'aktualisiert' => 0,
            'uebersprungen' => 0, 'gematcht' => 0, 'ungematcht' => 0, 'umsatz' => 0.0,
            'fehler' => [], 'batch_id' => $apply ? $batchId : null];

        foreach ($zeilen as $nr => $werte) {
            $bericht['gelesen']++;

            $label = trim((string) ($werte[$mapping['bezeichnung']] ?? ''));
            $datum = $this->datum((string) ($werte[$mapping['datum']] ?? ''));
            $umsatz = $this->zahl((string) ($werte[$mapping['umsatz']] ?? ''));

            if ($label === '' || $datum === null) {
                // Ohne Bezeichnung oder Datum ist die Zeile nicht auswertbar — gezählt und
                // benannt, nicht stillschweigend weggelassen.
                $bericht['uebersprungen']++;
                if (count($bericht['fehler']) < 20) {
                    $bericht['fehler'][] = 'Zeile ' . ($nr + 2) . ': '
                        . ($label === '' ? 'ohne Bezeichnung' : 'Datum nicht lesbar');
                }

                continue;
            }

            $menge = isset($mapping['menge']) ? $this->zahl((string) ($werte[$mapping['menge']] ?? '')) : null;
            $bereich = isset($mapping['bereich']) ? trim((string) ($werte[$mapping['bereich']] ?? '')) : null;

            [$recipeId, $methode, $konfidenz] = $this->matche($label, $katalog);
            $recipeId !== null ? $bericht['gematcht']++ : $bericht['ungematcht']++;
            $bericht['umsatz'] += $umsatz ?? 0.0;

            // Identität einer Zeile: Team + Tag + Bezeichnung + Bereich. Derselbe Export
            // zweimal eingelesen trifft denselben Hash und aktualisiert, statt zu verdoppeln.
            $hash = sha1(implode('|', [$team->id, $datum, mb_strtolower($label), (string) $bereich]));

            $vorhanden = FoodAlchemistSalesFact::where('team_id', $team->id)
                ->where('source_hash', $hash)->first();
            $vorhanden !== null ? $bericht['aktualisiert']++ : $bericht['neu']++;

            if (! $apply) {
                continue;
            }

            $daten = [
                'raw_label' => $label,
                'qty_sold' => $menge,
                'revenue_net' => $umsatz,
                'sold_at' => $datum,
                'match_method' => $methode,
                'match_confidence' => $konfidenz,
                'source' => 'csv_import',
                'source_scope_label' => $bereich !== '' ? $bereich : null,
                'import_batch_id' => $batchId,
            ];

            if ($vorhanden !== null) {
                // Eine von Hand gesetzte Zuordnung überlebt den Re-Import. Sonst würde jeder
                // erneute Lauf die Handarbeit des Menschen mit dem Fuzzy-Treffer überschreiben.
                if ($vorhanden->match_method !== 'manual') {
                    $daten['recipe_id'] = $recipeId;
                }
                $vorhanden->update($daten);
            } else {
                FoodAlchemistSalesFact::create($daten + [
                    'team_id' => $team->id,
                    'recipe_id' => $recipeId,
                    'source_hash' => $hash,
                ]);
            }
        }

        $bericht['umsatz'] = round($bericht['umsatz'], 2);

        return $bericht;
    }

    /** Eine Zeile von Hand einem Gericht zuordnen (oder die Zuordnung lösen). */
    public function zuordnen(Team $team, int $factId, ?int $recipeId): bool
    {
        $fact = FoodAlchemistSalesFact::where('team_id', $team->id)->whereKey($factId)->first();
        if ($fact === null) {
            return false;
        }
        if ($recipeId !== null && ! FoodAlchemistRecipe::visibleToTeam($team)->whereKey($recipeId)->exists()) {
            return false;
        }

        $fact->update([
            'recipe_id' => $recipeId,
            'match_method' => $recipeId === null ? 'none' : 'manual',
            'match_confidence' => $recipeId === null ? null : 100,
        ]);

        return true;
    }

    /** Dateien im TEAM-EIGENEN Ablage-Ordner (Dateinamen, keine Pfade). @return list<string> */
    public function dateien(Team $team): array
    {
        $dir = storage_path('app/' . self::ordnerFuer($team));
        if (! is_dir($dir)) {
            return [];
        }
        $out = [];
        foreach (scandir($dir) ?: [] as $f) {
            if (in_array(strtolower((string) pathinfo($f, PATHINFO_EXTENSION)), ['csv', 'tsv', 'txt'], true)) {
                $out[] = $f;
            }
        }
        sort($out);

        return $out;
    }

    // ── intern ────────────────────────────────────────────────────────────────

    /**
     * Datei lesen. Der Parameter ist ein DATEINAME, kein Pfad — ein Import, der freie Pfade
     * annimmt, ist ein Lesezugriff auf das Server-Dateisystem (Muster ArticleImportTrigger).
     *
     * @return array{0: list<string>, 1: list<array<int,string>>, 2: string}
     */
    private function lies(Team $team, string $dateiname): array
    {
        $name = basename(trim($dateiname));
        $endung = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));

        if (in_array($endung, ['xlsx', 'xls', 'xlsm', 'ods'], true)) {
            throw new RuntimeException(
                "Tabellen-Format [{$endung}] kann nicht gelesen werden (keine Spreadsheet-Abhängigkeit im Modul). "
                . 'Bitte als CSV (Trennzeichen ;) exportieren.'
            );
        }
        if (! in_array($endung, ['csv', 'tsv', 'txt'], true)) {
            throw new RuntimeException("Unbekannte Endung [{$endung}] — erwartet .csv oder .tsv.");
        }

        $pfad = storage_path('app/' . self::ordnerFuer($team) . '/' . $name);
        if (! is_file($pfad) || ! is_readable($pfad)) {
            throw new RuntimeException("Datei nicht gefunden: {$name}.");
        }

        $roh = file_get_contents($pfad);
        if ($roh === false || trim($roh) === '') {
            throw new RuntimeException('Datei ist leer.');
        }
        $roh = preg_replace('/^\xEF\xBB\xBF/', '', $roh) ?? $roh;   // BOM
        $rohZeilen = array_values(array_filter(preg_split("/\r\n|\n|\r/", $roh) ?: [], fn ($z) => trim($z) !== ''));
        if ($rohZeilen === []) {
            throw new RuntimeException('Keine Kopfzeile gefunden.');
        }

        $kopf = array_shift($rohZeilen);
        $trenner = $this->trenner($kopf);

        return [
            str_getcsv($kopf, $trenner, '"', '\\'),
            array_map(fn ($z) => str_getcsv($z, $trenner, '"', '\\'), $rohZeilen),
            $trenner,
        ];
    }

    /** Trennzeichen an der Kopfzeile erkennen — das häufigste gewinnt. */
    private function trenner(string $kopf): string
    {
        $kandidaten = [';' => substr_count($kopf, ';'), ',' => substr_count($kopf, ','),
            "\t" => substr_count($kopf, "\t"), '|' => substr_count($kopf, '|')];
        arsort($kandidaten);
        $bester = array_key_first($kandidaten);

        return $kandidaten[$bester] > 0 ? (string) $bester : ';';
    }

    /** Kopf-Titel → Feld raten. Nur offensichtliche Fälle; der Rest bleibt dem Menschen. */
    private function rateFeld(string $titel): ?string
    {
        $t = mb_strtolower(trim($titel));

        return match (true) {
            $t === '' => null,
            (bool) preg_match('/(artikel|bezeichn|gericht|speise|name|produkt|text)/', $t) => 'bezeichnung',
            (bool) preg_match('/(menge|anzahl|stück|stueck|portion|qty)/', $t) => 'menge',
            (bool) preg_match('/(umsatz|erlös|erloes|netto|betrag|summe|revenue)/', $t) => 'umsatz',
            (bool) preg_match('/(datum|tag|date|periode|monat)/', $t) => 'datum',
            (bool) preg_match('/(bereich|kostenstelle|betrieb|filiale|outlet|kasse)/', $t) => 'bereich',
            default => null,
        };
    }

    /**
     * Verkaufsgerichte des Teams als Match-Katalog.
     *
     * @return list<array{id:int,norm:string,tokens:list<string>}>
     */
    private function katalog(Team $team): array
    {
        return FoodAlchemistRecipe::visibleToTeam($team)->where('is_sales_recipe', true)
            ->get(['foodalchemist_recipes.id', 'name'])
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'norm' => $this->norm((string) $r->name),
                'tokens' => $this->tokens((string) $r->name),
            ])->all();
    }

    /**
     * Verkaufszeile → Gericht. Erst exakt (nach Normalisierung), dann Token-Überlappung.
     * Unter der Schwelle bleibt die Zeile bewusst ohne Zuordnung — ein schwacher Treffer
     * ist schlechter als gar keiner, weil er Umsatz auf das falsche Gericht bucht.
     *
     * @param  list<array{id:int,norm:string,tokens:list<string>}>  $katalog
     * @return array{0:?int,1:string,2:?float}
     */
    private function matche(string $label, array $katalog): array
    {
        $norm = $this->norm($label);
        foreach ($katalog as $k) {
            if ($k['norm'] !== '' && $k['norm'] === $norm) {
                return [$k['id'], 'exact', 100.0];
            }
        }

        $tokens = $this->tokens($label);
        if ($tokens === []) {
            return [null, 'none', null];
        }

        $bester = null;
        $bestScore = 0.0;
        foreach ($katalog as $k) {
            if ($k['tokens'] === []) {
                continue;
            }
            $treffer = count(array_intersect($tokens, $k['tokens']));
            if ($treffer === 0) {
                continue;
            }
            // F1 über die Token-Mengen: bestraft sowohl fehlende als auch überschüssige Wörter.
            $p = $treffer / count($k['tokens']);
            $r = $treffer / count($tokens);
            $f1 = 2 * $p * $r / ($p + $r);
            if ($f1 > $bestScore) {
                $bestScore = $f1;
                $bester = $k['id'];
            }
        }

        return $bestScore >= self::TOKEN_SCHWELLE
            ? [$bester, 'token', round($bestScore * 100, 2)]
            : [null, 'none', null];
    }

    private function norm(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = strtr($s, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        $s = preg_replace('/[^a-z0-9]+/', ' ', $s) ?? $s;

        return trim(preg_replace('/\s+/', ' ', $s) ?? $s);
    }

    /** @return list<string> */
    private function tokens(string $s): array
    {
        $t = array_filter(explode(' ', $this->norm($s)), fn ($w) => mb_strlen($w) > 2);

        return array_values(array_unique($t));
    }

    /** Zahl aus deutscher oder englischer Schreibweise („1.234,56" / „1234.56"). */
    private function zahl(string $s): ?float
    {
        $s = trim(str_replace(['€', ' ', "\u{00A0}"], '', $s));
        if ($s === '') {
            return null;
        }
        // Deutsches Format erkennt man am Komma als letztem Trenner.
        if (str_contains($s, ',') && (! str_contains($s, '.') || strrpos($s, ',') > strrpos($s, '.'))) {
            $s = str_replace('.', '', $s);
            $s = str_replace(',', '.', $s);
        } else {
            $s = str_replace(',', '', $s);
        }

        return is_numeric($s) ? (float) $s : null;
    }

    /** Datum aus den üblichen Schreibweisen; null wenn unlesbar (Zeile wird übersprungen). */
    private function datum(string $s): ?string
    {
        $s = trim($s);
        if ($s === '') {
            return null;
        }
        foreach (['d.m.Y', 'd.m.y', 'Y-m-d', 'd/m/Y', 'm/d/Y', 'Y-m', 'm.Y'] as $format) {
            $d = \DateTimeImmutable::createFromFormat('!' . $format, $s);
            if ($d !== false) {
                return $d->format('Y-m-d');
            }
        }
        try {
            return (new \DateTimeImmutable($s))->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }
}
