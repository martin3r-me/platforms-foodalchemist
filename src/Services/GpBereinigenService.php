<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\FoodAlchemist\Models\FoodAlchemistComponentEquivalent;

/**
 * GP-Stamm bereinigen (Dominique/Kurator 19.10.2026): Dubletten mergen, Namen nach Regelwerk korrigieren.
 *
 * Der Dienst RECHNET NICHTS SELBST AUS, was fachliches Urteil braucht: Er führt geprüfte Pläne aus (Sichtungs-CSV
 * `dubletten_fa.csv` / `benennung_fa.csv`) und prüft jede Zeile gegen den LIVE-Stand (GP existiert, gehört dem Team, Name seit der
 * Sichtung unverändert, Regelwerk-Validierung, Key-Kollision). Alles, was nicht eindeutig ist, landet als Konflikt in einer Liste
 * und wird NICHT angefasst.
 *
 * Schreibt per Query-Builder (keine Modell-Ereignisse → keine Embedding-/Activity-Stürme) und protokolliert JEDE Änderung so,
 * dass {@see zuruecknehmen} sie umkehren kann. Alle Tabellen mit GP-Verweis werden aus dem Schema ermittelt, nicht aus einer festen Liste.
 */
class GpBereinigenService
{
    /** Status, die ein Merge-Verlierer/-Gewinner tragen darf. */
    private const AKTIV = ['approved', 'tentative'];

    /** @var list<array{0:string,1:string}>|null */
    private ?array $verweise = null;

    public function __construct(private readonly GpNamingService $naming) {}

    // ── CSV ─────────────────────────────────────────────────────────────────────────────────────────

    /** @return list<array<string,string>> Kopfzeile = Schlüssel; Semikolon, UTF-8 (BOM wird entfernt). */
    public function leseCsv(string $pfad): array
    {
        if (! is_readable($pfad)) {
            throw new \RuntimeException("CSV nicht lesbar: {$pfad}");
        }
        $h = fopen($pfad, 'rb');
        $kopf = null;
        $zeilen = [];
        while (($z = fgetcsv($h, 0, ';', '"', '')) !== false) {
            if ($kopf === null) {
                $z[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $z[0]);
                $kopf = array_map('trim', $z);

                continue;
            }
            if ($z === [null] || count($z) < count($kopf)) {
                continue;
            }
            $zeilen[] = array_combine($kopf, array_slice($z, 0, count($kopf)));
        }
        fclose($h);

        return $zeilen;
    }

    /**
     * Review-CSVs (`<WG>_artikel.csv`) lesen: GP-IDs, bei denen ein Merge ausdrücklich NICHT erwünscht ist
     * (Spalte merge_hinweis enthält „kein Merge"). Link-Review kommt vor dem Merge (Kurator 19.10.).
     *
     * @return array<int,string> gp_id => Begründung
     */
    public function keinMergeAusReview(string $ordner): array
    {
        $out = [];
        foreach (glob(rtrim($ordner, '/') . '/*_artikel.csv') ?: [] as $datei) {
            foreach ($this->leseCsv($datei) as $z) {
                $hinweis = trim((string) ($z['merge_hinweis'] ?? ''));
                if ($hinweis !== '' && preg_match('/kein\s*merge/iu', $hinweis) === 1 && (int) ($z['gp_id'] ?? 0) > 0) {
                    $out[(int) $z['gp_id']] = $hinweis;
                }
            }
        }

        return $out;
    }

    // ── Verweise ────────────────────────────────────────────────────────────────────────────────────

    /**
     * Alle (Tabelle, Spalte) mit GP-Verweis, aus dem Schema: `gp_id` und `*_gp_id`, ohne die GP-Tabelle selbst
     * (deren Selbst-Verweise `derivat_von_gp_id`/`merged_into_id` laufen gesondert).
     *
     * @return list<array{0:string,1:string}>
     */
    public function verweisSpalten(): array
    {
        if ($this->verweise !== null) {
            return $this->verweise;
        }
        $out = [];
        $verbindung = DB::connection();
        $eigene = $verbindung->getDriverName() === 'sqlite' ? 'main' : $verbindung->getDatabaseName();
        foreach (Schema::getTableListing() as $t) {
            // getTableListing liefert bei MySQL schema-qualifiziert (`db.tabelle`) und ALLE Datenbanken des Servers
            // (lokal u. a. foodalchemist_pass, _platform, _server …). Nur die eigene Datenbank gehört dazu, ohne Präfix.
            if (str_contains($t, '.')) {
                [$schema, $t] = explode('.', $t, 2);
                if ($schema !== $eigene) {
                    continue;
                }
            }
            if (! str_starts_with($t, 'foodalchemist_') || $t === 'foodalchemist_gps') {
                continue;
            }
            foreach (Schema::getColumnListing($t) as $c) {
                if ($c === 'gp_id' || str_ends_with($c, '_gp_id')) {
                    $out[] = [$t, $c];
                }
            }
        }
        sort($out);
        // Fail loud: ein leerer/unvollständiger Befund würde einen Merge ohne Umhängen als „erfolgreich" melden.
        foreach (['foodalchemist_recipe_ingredients', 'foodalchemist_supplier_item_structures'] as $pflicht) {
            if (! in_array([$pflicht, 'gp_id'], $out, true)) {
                throw new \RuntimeException("Verweis-Erkennung unvollständig: {$pflicht}.gp_id fehlt — Abbruch.");
            }
        }

        return $this->verweise = $out;
    }

    /** Wie viele Zeilen hängen je Tabelle am GP? @return array<string,int> */
    public function verweisZahlen(int $gpId): array
    {
        $out = [];
        foreach ($this->verweisSpalten() as [$t, $c]) {
            $n = DB::table($t)->where($c, $gpId)->count();
            if ($n > 0) {
                $out["{$t}.{$c}"] = $n;
            }
        }
        $n = DB::table('foodalchemist_gps')->where('derivat_von_gp_id', $gpId)->count();
        if ($n > 0) {
            $out['foodalchemist_gps.derivat_von_gp_id'] = $n;
        }
        $n = DB::table('foodalchemist_gps')->where('merged_into_id', $gpId)->count();
        if ($n > 0) {
            $out['foodalchemist_gps.merged_into_id'] = $n;
        }
        foreach ([['source_kind', 'source_id'], ['alt_kind', 'alt_id']] as [$k, $i]) {
            $n = DB::table('foodalchemist_component_equivalents')->where($k, FoodAlchemistComponentEquivalent::KIND_GP)->where($i, $gpId)->count();
            if ($n > 0) {
                $out["foodalchemist_component_equivalents.{$i}"] = $n;
            }
        }

        return $out;
    }

    // ── Plan: Dubletten ─────────────────────────────────────────────────────────────────────────────

    /**
     * Merge-Gruppen aus der Sichtungs-CSV prüfen (nur Gruppen `N…` = Namens-Dubletten).
     *
     * @param  array<int,string>  $keinMerge  gp_id => Grund (aus dem Link-Review)
     * @return array{gruppen: list<array<string,mixed>>, konflikte: list<array<string,mixed>>}
     */
    public function planDubletten(int $teamId, string $csv, array $keinMerge = [], ?string $wg = null): array
    {
        $gruppen = [];
        foreach ($this->leseCsv($csv) as $z) {
            if (! str_starts_with((string) ($z['group_id'] ?? ''), 'N')) {
                continue;
            }
            $gruppen[$z['group_id']][] = $z;
        }
        $ids = [];
        foreach ($gruppen as $zeilen) {
            foreach ($zeilen as $z) {
                $ids[] = (int) $z['gp_id'];
            }
        }
        $gps = DB::table('foodalchemist_gps')->whereIn('id', $ids)->get()->keyBy('id');

        $plan = [];
        $konflikte = [];
        foreach ($gruppen as $gid => $zeilen) {
            $sieger = null;
            $verlierer = [];
            foreach ($zeilen as $z) {
                (str_starts_with((string) $z['keep_proposal'], 'behalten') ? $sieger = $z : $verlierer[] = $z);
            }
            $konflikt = function (string $grund, ?int $gp = null, ?string $name = null, ?string $wgCode = null) use (&$konflikte, $gid) {
                $konflikte[] = ['phase' => 'dubletten', 'gruppe' => $gid, 'gp_id' => $gp, 'name' => $name, 'wg' => $wgCode, 'grund' => $grund];
            };
            if ($sieger === null || $verlierer === []) {
                $konflikt('Gruppe ohne eindeutigen Sieger/Verlierer');

                continue;
            }
            $gw = $gps->get((int) $sieger['gp_id']);
            if ($wg !== null && ($gw->commodity_group_code ?? null) !== $wg) {
                continue;
            }
            $fehler = [];
            $paar = [];
            foreach (array_merge([$sieger], $verlierer) as $z) {
                $gp = $gps->get((int) $z['gp_id']);
                if ($gp === null || $gp->deleted_at !== null) {
                    $fehler[] = "#{$z['gp_id']} existiert nicht mehr";

                    continue;
                }
                if ((int) $gp->team_id !== $teamId) {
                    $fehler[] = "#{$gp->id} gehört nicht Team {$teamId}";
                }
                if (! in_array($gp->status, self::AKTIV, true)) {
                    $fehler[] = "#{$gp->id} hat Status {$gp->status}";
                }
                if (trim((string) $gp->name) !== trim((string) $z['name'])) {
                    $fehler[] = "#{$gp->id} Name seit der Sichtung geändert";
                }
                if (isset($keinMerge[(int) $gp->id])) {
                    $fehler[] = "#{$gp->id} Link-Review: {$keinMerge[(int) $gp->id]}";
                }
                $paar[] = $gp;
            }
            if ($fehler === []) {
                $gw = $gps->get((int) $sieger['gp_id']);
                foreach ($verlierer as $v) {
                    $gv = $gps->get((int) $v['gp_id']);
                    if ((string) $gv->commodity_group_code !== (string) $gw->commodity_group_code) {
                        $fehler[] = "#{$gv->id} andere Warengruppe als der Sieger ({$gv->commodity_group_code} ≠ {$gw->commodity_group_code})";
                    }
                    if ((bool) $gv->is_derivat !== (bool) $gw->is_derivat) {
                        $fehler[] = "#{$gv->id} Derivat-Eigenschaft verschieden vom Sieger";
                    }
                    if ($gw->is_derivat && DB::table('foodalchemist_gps')->where('derivat_von_gp_id', $gv->id)->exists()) {
                        $fehler[] = "#{$gv->id} hat Derivate, Sieger ist selbst Derivat (nur eine Ebene)";
                    }
                    if ((bool) $gv->is_platzhalter || (bool) $gw->is_platzhalter) {
                        $fehler[] = 'Platzhalter werden nicht gemerged';
                    }
                }
            }
            if ($fehler !== []) {
                foreach ($fehler as $f) {
                    $konflikt($f, (int) $sieger['gp_id'], (string) $sieger['name'], $gw->commodity_group_code ?? null);
                }

                continue;
            }
            $plan[] = [
                'gruppe' => $gid,
                'wg' => $gw->commodity_group_code,
                'sieger' => $gw,
                'verlierer' => array_map(fn ($v) => $gps->get((int) $v['gp_id']), $verlierer),
                'verweise' => array_combine(
                    array_map(fn ($v) => (int) $v['gp_id'], $verlierer),
                    array_map(fn ($v) => $this->verweisZahlen((int) $v['gp_id']), $verlierer),
                ),
            ];
        }

        return ['gruppen' => $plan, 'konflikte' => $konflikte];
    }

    // ── Plan: Namen ─────────────────────────────────────────────────────────────────────────────────

    /**
     * Deterministische Namensvorschläge aus der Sichtungs-CSV prüfen. Je GP genau EIN eindeutiger Vorschlag; mehrere
     * verschiedene Vorschläge, „unklar", „kein Eingriff", Warengruppe 15 (eigene Phase) und jede Regelwerk-Verletzung des
     * neuen Namens gehen als Konflikt in die Liste.
     *
     * Key-Politik (Entscheid 19.10.): Name immer; `main_ingredient_slug` und `gp_key` nur bei reinem Plural→Singular-Fix und nur,
     * wenn sie vorher aus dem Namen ableitbar waren. `$keyFolgtName` zieht den Key bei JEDEM Fix mit (nur auf Anweisung).
     *
     * @return array{aenderungen: list<array<string,mixed>>, konflikte: list<array<string,mixed>>, ohne_aenderung: int}
     */
    public function planNamen(int $teamId, string|array $csv, ?string $wg = null, bool $keyFolgtName = false): array
    {
        // Mehrere Pläne (z. B. Regelverstöße + Marke/Gebinde) werden je GP zusammengeführt: zwei VERSCHIEDENE Vorschläge für dasselbe GP
        // sind ein Konflikt (manuell), derselbe Vorschlag aus zwei Dateien zählt einmal.
        $je = [];
        foreach ((array) $csv as $datei) {
            foreach ($this->leseCsv((string) $datei) as $z) {
                $je[(int) $z['gp_id']][] = $z;
            }
        }
        $gps = DB::table('foodalchemist_gps')->whereIn('id', array_keys($je))->get()->keyBy('id');

        $aenderungen = [];
        $konflikte = [];
        $ohne = 0;
        $neueKeys = [];
        $neueNamen = [];
        foreach ($je as $gpId => $zeilen) {
            $gp = $gps->get($gpId);
            $konflikt = function (string $grund) use (&$konflikte, $gp, $gpId, $zeilen) {
                $konflikte[] = ['phase' => 'namen', 'gruppe' => null, 'gp_id' => $gpId, 'name' => $gp->name ?? $zeilen[0]['name'],
                    'wg' => $gp->commodity_group_code ?? null, 'grund' => $grund];
            };
            if ($gp === null || $gp->deleted_at !== null) {
                $konflikt('GP existiert nicht mehr');

                continue;
            }
            if ($wg !== null && (string) $gp->commodity_group_code !== $wg) {
                continue;
            }
            $vorschlaege = [];
            $regeln = [];
            foreach ($zeilen as $z) {
                $p = trim((string) ($z['proposal'] ?? ''));
                if ($p === '' || stripos($p, 'unklar') === 0 || stripos($p, 'kein Eingriff') === 0) {
                    continue;
                }
                $vorschlaege[$p] = true;
                $regeln[(string) $z['rule']] = true;
            }
            if ($vorschlaege === []) {
                $ohne++;

                continue;
            }
            if (count($vorschlaege) > 1) {
                $konflikt('Mehrere verschiedene Vorschläge (' . implode(' | ', array_keys($regeln)) . ') — manuell');

                continue;
            }
            $neu = (string) array_key_first($vorschlaege);
            if ((int) $gp->team_id !== $teamId || ! in_array($gp->status, self::AKTIV, true)) {
                $konflikt("Team/Status passt nicht (Team {$gp->team_id}, {$gp->status})");

                continue;
            }
            if (trim((string) $gp->name) !== trim((string) $zeilen[0]['name'])) {
                $konflikt('Name seit der Sichtung geändert');

                continue;
            }
            if ($neu === trim((string) $gp->name)) {
                $ohne++;

                continue;
            }
            if ((string) $gp->commodity_group_code === '15') {
                $konflikt('WG 15 (Getränke): eigene Phase (Gebinde/Zustandswort), hier nicht');

                continue;
            }
            // Zustandsfeld folgt dem Namen: steht im neuen Namen ein anderes Zustandswort (frisch↔trocken, neue Wörter wie gepoekelt), wird
            // `condition` mitgezogen. Ohne Zustandswort im neuen Namen bleibt das Feld unangetastet.
            $neuerZustand = $this->naming->felderAusName($neu)['condition'];
            $zustandWechsel = $neuerZustand !== '' && $neuerZustand !== (string) $gp->condition;
            $pruefung = $this->naming->validateGpName($neu, [
                'condition' => $zustandWechsel ? $neuerZustand : $gp->condition, 'warengruppe' => $gp->commodity_group_code, 'commodity_group_code' => $gp->commodity_group_code,
                'form' => $gp->form, 'hauptzutat' => trim((string) strstr($neu . ':', ':', true)),
            ]);
            if ($pruefung['errors'] !== []) {
                $konflikt('Neuer Name verletzt Regelwerk: ' . implode('; ', $pruefung['errors']));

                continue;
            }

            // Namens-Kollision: ein anderes aktives GP trägt den neuen Namen schon, oder ein früherer Vorschlag desselben Laufs (auch aus einer
            // anderen CSV) ergibt denselben Namen — beides ist ein Merge-Kandidat, kein Fall für automatisches Umbenennen.
            $nameKey = mb_strtolower(trim($neu));
            $nameBesetzt = DB::table('foodalchemist_gps')->where('team_id', $teamId)->whereNull('deleted_at')->whereIn('status', self::AKTIV)
                ->where('name', $neu)->where('id', '!=', $gp->id)->first();
            if ($nameBesetzt !== null) {
                $konflikt("Namens-Kollision: »{$neu}« trägt schon #{$nameBesetzt->id} ({$nameBesetzt->status}) — Merge-Kandidat?");

                continue;
            }
            if (isset($neueNamen[$nameKey])) {
                $konflikt("Namens-Kollision im selben Lauf mit #{$neueNamen[$nameKey]} (»{$neu}«) — Merge-Kandidat?");

                continue;
            }

            $nurPlural = array_keys($regeln) !== [] && count(array_filter(array_keys($regeln), fn ($r) => ! str_contains($r, '§6.1 Plural'))) === 0;
            $neuerKopf = trim((string) strstr($neu . ':', ':', true));
            $alterKopf = trim((string) strstr($gp->name . ':', ':', true));
            $keyAbleitbar = (string) $gp->gp_key === $this->naming->slugify((string) $gp->name);
            $slugAbleitbar = (string) $gp->main_ingredient_slug === $this->naming->slugify($alterKopf);
            $neuerKey = $gp->gp_key;
            $neuerSlug = $gp->main_ingredient_slug;
            $neueAnzeige = $gp->main_ingredient_display;
            if (($nurPlural || $keyFolgtName) && $keyAbleitbar) {
                $neuerKey = $this->naming->slugify($neu);
            }
            if (($nurPlural || $keyFolgtName) && $slugAbleitbar) {
                $neuerSlug = $this->naming->slugify($neuerKopf);
            }
            if (trim((string) $gp->main_ingredient_display) === $alterKopf) {
                $neueAnzeige = $neuerKopf;
            }
            if ($neuerKey !== $gp->gp_key) {
                // UNIQUE (team_id, gp_key) gilt für ALLE Zeilen (auch merged/soft-gelöscht) — mit-prüfen.
                $kollision = DB::table('foodalchemist_gps')->where('team_id', $teamId)->where('gp_key', $neuerKey)->where('id', '!=', $gp->id)->first();
                if ($kollision !== null) {
                    $konflikt("Key-Kollision: »{$neuerKey}« gehört schon #{$kollision->id} {$kollision->name} ({$kollision->status}) — Merge-Kandidat?");

                    continue;
                }
                if (isset($neueKeys[$neuerKey])) {
                    $konflikt("Key-Kollision im selben Lauf mit #{$neueKeys[$neuerKey]}");

                    continue;
                }
                $neueKeys[$neuerKey] = $gp->id;
            }
            $neueNamen[$nameKey] = $gp->id;
            // Felder folgen dem Namen: ist der Wert von Verarbeitung/Form im neuen Namen nicht mehr enthalten (z. B. Form »Pueree« nach
            // Regelwerk §8.17 in die Hauptzutat gewandert), wird das Feld geleert — sonst bliebe der Name vom Render der Felder abweichend (Drift I4).
            $feldWechsel = ['alt' => [], 'neu' => []];
            foreach (['processing', 'form'] as $feld) {
                $wert = trim((string) ($gp->{$feld} ?? ''));
                // Nur die Attribute hinter dem Doppelpunkt zählen (die Hauptzutat »Karottenpueree« enthält »Pueree« als Wortteil).
                $neuAttr = str_contains($neu, ':') ? (string) substr($neu, strpos($neu, ':') + 1) : '';
                $altAttr = str_contains((string) $gp->name, ':') ? (string) substr((string) $gp->name, strpos((string) $gp->name, ':') + 1) : '';
                if ($wert !== '' && mb_stripos($neuAttr, $wert) === false && mb_stripos($altAttr, $wert) !== false) {
                    $feldWechsel['alt'][$feld] = $gp->{$feld};
                    $feldWechsel['neu'][$feld] = null;
                }
            }
            $aenderungen[] = [
                'gp_id' => $gp->id, 'wg' => $gp->commodity_group_code, 'regeln' => array_keys($regeln),
                'alt' => ['name' => $gp->name, 'gp_key' => $gp->gp_key, 'main_ingredient_slug' => $gp->main_ingredient_slug, 'main_ingredient_display' => $gp->main_ingredient_display]
                    + ($zustandWechsel ? ['condition' => $gp->condition, 'condition_source' => $gp->condition_source] : []) + $feldWechsel['alt'],
                'neu' => ['name' => $neu, 'gp_key' => $neuerKey, 'main_ingredient_slug' => $neuerSlug, 'main_ingredient_display' => $neueAnzeige]
                    + ($zustandWechsel ? ['condition' => $neuerZustand, 'condition_source' => 'manual'] : []) + $feldWechsel['neu'],
                'zustand_wechsel' => $zustandWechsel,
                'felder_geleert' => array_keys($feldWechsel['neu']),
                'key_wechsel' => $neuerKey !== $gp->gp_key,
                'key_nicht_ableitbar' => ! $keyAbleitbar,
                'warnungen' => $pruefung['warnings'],
            ];
        }

        return ['aenderungen' => $aenderungen, 'konflikte' => $konflikte, 'ohne_aenderung' => $ohne];
    }

    // ── Ausführen ───────────────────────────────────────────────────────────────────────────────────

    /**
     * Einen Merge ausführen (Verlierer → Sieger). Eine Transaktion; Protokoll wird ergänzt.
     *
     * @param  array<string,mixed>  $protokoll
     * @return array{recipe_ids: list<int>}
     */
    public function merge(object $sieger, object $verlierer, array &$protokoll): array
    {
        $von = (int) $verlierer->id;
        $nach = (int) $sieger->id;
        $rezepte = DB::table('foodalchemist_recipe_ingredients')->where('gp_id', $von)->distinct()->pluck('recipe_id')->map(fn ($i) => (int) $i)->all();

        DB::transaction(function () use ($von, $nach, $verlierer, $sieger, &$protokoll) {
            foreach ($this->verweisSpalten() as [$t, $c]) {
                $this->verschiebe($t, $c, $von, $nach, $protokoll);
            }
            foreach ([['source_kind', 'source_id'], ['alt_kind', 'alt_id']] as [$k, $i]) {
                $q = DB::table('foodalchemist_component_equivalents')->where($k, FoodAlchemistComponentEquivalent::KIND_GP)->where($i, $von);
                foreach ($q->get() as $row) {
                    DB::table('foodalchemist_component_equivalents')->where('id', $row->id)->update([$i => $nach]);
                    $protokoll['moves'][] = ['table' => 'foodalchemist_component_equivalents', 'id' => $row->id, 'col' => $i, 'von' => $von, 'nach' => $nach];
                }
            }
            foreach (['derivat_von_gp_id', 'merged_into_id'] as $c) {
                foreach (DB::table('foodalchemist_gps')->where($c, $von)->get() as $row) {
                    DB::table('foodalchemist_gps')->where('id', $row->id)->update([$c => $nach]);
                    $protokoll['moves'][] = ['table' => 'foodalchemist_gps', 'id' => $row->id, 'col' => $c, 'von' => $von, 'nach' => $nach];
                }
            }
            $protokoll['merges'][] = [
                'von' => $von, 'nach' => $nach,
                'verlierer_alt' => ['status' => $verlierer->status, 'merged_into_id' => $verlierer->merged_into_id, 'n_las_total' => $verlierer->n_las_total],
                'sieger_alt' => ['n_las_total' => $sieger->n_las_total],
            ];
            DB::table('foodalchemist_gps')->where('id', $von)->update(['status' => 'merged', 'merged_into_id' => $nach, 'n_las_total' => 0, 'updated_at' => now()]);
            DB::table('foodalchemist_gps')->where('id', $nach)->update([
                'n_las_total' => DB::table('foodalchemist_supplier_item_structures')->where('gp_id', $nach)->count(), 'updated_at' => now(),
            ]);
        });

        return ['recipe_ids' => $rezepte];
    }

    /** Zeilen einer Tabelle von $von auf $nach umhängen; bei UNIQUE-Konflikt die Verlierer-Zeile verwerfen (voll protokolliert). */
    private function verschiebe(string $tabelle, string $spalte, int $von, int $nach, array &$protokoll): void
    {
        if (! DB::table($tabelle)->where($spalte, $von)->exists()) {
            return;
        }
        if (! Schema::hasColumn($tabelle, 'id')) {
            throw new \RuntimeException("Tabelle {$tabelle} hat keine id-Spalte — Merge hier nicht automatisierbar, bitte manuell.");
        }
        foreach (DB::table($tabelle)->where($spalte, $von)->get() as $row) {
            try {
                DB::transaction(fn () => DB::table($tabelle)->where('id', $row->id)->update([$spalte => $nach]));
                $protokoll['moves'][] = ['table' => $tabelle, 'id' => $row->id, 'col' => $spalte, 'von' => $von, 'nach' => $nach];
            } catch (UniqueConstraintViolationException) {
                DB::table($tabelle)->where('id', $row->id)->delete();
                $protokoll['deleted'][] = ['table' => $tabelle, 'row' => (array) $row, 'grund' => "doppelt beim Sieger #{$nach}"];
            }
        }
    }

    /** @param array<string,mixed> $a eine Änderung aus {@see planNamen}  @param array<string,mixed> $protokoll */
    public function benenneUm(array $a, array &$protokoll): void
    {
        DB::table('foodalchemist_gps')->where('id', $a['gp_id'])->update([...$a['neu'], 'updated_at' => now()]);
        $protokoll['renames'][] = ['id' => $a['gp_id'], 'alt' => $a['alt'], 'neu' => $a['neu']];
    }

    /**
     * Ein Protokoll zurücknehmen (umgekehrte Reihenfolge). Geändert wird nur, was noch genau dem protokollierten Stand entspricht.
     *
     * @param  array<string,mixed>  $p
     * @return array{zurueck: int, uebersprungen: int}
     */
    public function zuruecknehmen(array $p): array
    {
        $zurueck = 0;
        $skip = 0;
        DB::transaction(function () use ($p, &$zurueck, &$skip) {
            foreach (array_reverse($p['renames'] ?? []) as $r) {
                $ist = DB::table('foodalchemist_gps')->where('id', $r['id'])->first();
                if ($ist !== null && $ist->name === $r['neu']['name'] && $ist->gp_key === $r['neu']['gp_key']) {
                    DB::table('foodalchemist_gps')->where('id', $r['id'])->update([...$r['alt'], 'updated_at' => now()]);
                    $zurueck++;
                } else {
                    $skip++;
                }
            }
            foreach (array_reverse($p['merges'] ?? []) as $m) {
                $ist = DB::table('foodalchemist_gps')->where('id', $m['von'])->first();
                if ($ist !== null && $ist->status === 'merged' && (int) $ist->merged_into_id === (int) $m['nach']) {
                    DB::table('foodalchemist_gps')->where('id', $m['von'])->update(['status' => $m['verlierer_alt']['status'],
                        'merged_into_id' => $m['verlierer_alt']['merged_into_id'], 'n_las_total' => $m['verlierer_alt']['n_las_total'], 'updated_at' => now()]);
                    DB::table('foodalchemist_gps')->where('id', $m['nach'])->update(['n_las_total' => $m['sieger_alt']['n_las_total'], 'updated_at' => now()]);
                    $zurueck++;
                } else {
                    $skip++;
                }
            }
            foreach (array_reverse($p['moves'] ?? []) as $mv) {
                $ist = DB::table($mv['table'])->where('id', $mv['id'])->first();
                if ($ist !== null && (int) $ist->{$mv['col']} === (int) $mv['nach']) {
                    DB::table($mv['table'])->where('id', $mv['id'])->update([$mv['col'] => $mv['von']]);
                    $zurueck++;
                } else {
                    $skip++;
                }
            }
            foreach (array_reverse($p['deleted'] ?? []) as $d) {
                if (! DB::table($d['table'])->where('id', $d['row']['id'])->exists()) {
                    DB::table($d['table'])->insert($d['row']);
                    $zurueck++;
                } else {
                    $skip++;
                }
            }
        });

        return ['zurueck' => $zurueck, 'uebersprungen' => $skip];
    }
}
