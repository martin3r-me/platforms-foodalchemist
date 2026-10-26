<?php

namespace Platform\FoodAlchemist\Services\GpPaket;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\FoodAlchemist\Exceptions\GpPaketAbgelehntException;
use Platform\FoodAlchemist\Services\GpNamingService;

/**
 * GP-Paket-Import · Paket lesen, Whitelist/Manifest/SHA prüfen, GP-Einzelprüfungen, Kontext/Vokabular aus io, Wert-/Hash-Hilfen
 * (aus {@see \Platform\FoodAlchemist\Services\GpPaketImportService} herausgelöst, Spec 85 §4 — Verhalten unverändert).
 */
class GpPaketValidator
{
    /** Pflicht-/Daten-Dateien des Pakets (Skript 300). `gps.jsonl` und `gp_artikel.jsonl` müssen da sein. */
    public const DATEIEN = ['gps.jsonl', 'gp_artikel.jsonl', 'gp_forms.jsonl', 'gp_textures.jsonl', 'gp_einheiten.jsonl', 'gp_aequivalente.jsonl'];

    /** Whitelist der GP-Spalten im Paket (Plan §14, = GP_SPALTEN des Export-Skripts). */
    public const GP_SPALTEN = [
        'uuid', 'gp_key', 'name', 'main_ingredient_slug', 'main_ingredient_display', 'processing', 'form', 'flavor', 'zusatz', 'pflichtangabe', 'portion',
        'commodity_group_code', 'sub_category', 'condition', 'condition_source', 'bio', 'namens_vegan', 'namens_glutenfrei', 'namens_laktosefrei', 'requires_la', 'is_platzhalter', 'is_derivat', 'status',
        'rezepturware', 'marke_offen',   // §5 Ausnahme 3 (v3.7.1); auch im Export-Skript (Schritt 4) nachziehen
        'cooking_loss_default_pct', 'trimming_loss_default_pct', 'piece_default_g', 'piece_default_g_source',
        'diameter_mm', 'length_mm', 'width_mm', 'height_mm', 'volume_ml',
        'tag_is_vegan', 'tag_is_vegetarian', 'tag_is_halal', 'tag_contains_pork', 'tag_contains_beef', 'tag_is_organic', 'tag_is_regional',
        'tag_is_staple_food', 'tag_is_convenience', 'tag_is_lactose_free', 'tag_is_gluten_free', 'tag_source', 'is_favorite', 'favorite_rank',
    ];

    /** Zusatzfelder je GP-Zeile (Schlüssel statt IDs). */
    public const GP_ZUSATZ = ['derivat_von_gp_key', 'preferred_count_unit_slug', 'serving_vehicle_slug'];

    public const STRUKTUR_SPALTEN = [
        'is_food', 'exclusion_reason', 'main_ingredient_slug', 'main_ingredient_display', 'main_ingredient_confidence', 'is_primary_flavor_carrier',
        'flavor_ingredient_slugs', 'flavor_ingredients_confidence', 'processing', 'processing_confidence', 'form', 'groesse', 'convenience_host',
        'is_bio', 'is_halal', 'is_vegan', 'commodity_group_suggestion', 'commodity_group_confidence', 'gp_key', 'gp_name_derived', 'condition',
        'classifier', 'classifier_version', 'needs_review', 'review_reason',
    ];

    private const ARTIKEL_SPALTEN = ['gp_key', 'necta_legacy_id', 'lead', 'rang', 'struktur'];

    private const KIND_SPALTEN = [
        'gp_forms.jsonl' => ['gp_key', 'form_slug', 'gramm', 'source'],
        'gp_textures.jsonl' => ['gp_key', 'texture_slug', 'intensity', 'source'],
        'gp_einheiten.jsonl' => ['gp_key', 'unit_slug', 'default_g', 'is_primary', 'sort_order', 'source'],
        'gp_aequivalente.jsonl' => ['source_gp_key', 'alt_gp_key', 'umrechnungsfaktor', 'standard_seite', 'match_confidence', 'notes'],
    ];

    private const ANKER_SPALTEN = ['gp_key', 'anchor_slug', 'role', 'source', 'ai_confidence', 'ai_reasoning'];

    /** Im Paket NIE erlaubt (Plan §14): aus Artikeln abgeleitet, KI-/Technikspalten, vom Importer gesetzt. */
    private const VERBOTEN = '/^(allergen_|allergens_|nutri_|ai_|food_domain_|primary_food_domain)|_ai_confidence$|_ai_reasoning$|_aggregated_at$|^(id|team_id|stamm_class|merged_into_id|derivat_von_gp_id|lead_la_supplier_item_id|lead_la_supplier_item_legacy_id|n_las_total|first_seen_at|last_review_at|first_supplier_legacy_id|reviewer_note|created_at|updated_at|deleted_at)$/';

    /** Pflege-Felder, die geschrieben und abgeglichen werden (nur Spalten, die es im Ziel gibt). Favoriten sind seit FAV-3 Team-Overlay und werden nie geschrieben. */
    public const WRITE_SPALTEN = [
        'name', 'main_ingredient_slug', 'main_ingredient_display', 'processing', 'form', 'flavor', 'zusatz', 'pflichtangabe', 'portion', 'commodity_group_code', 'sub_category',
        'condition', 'condition_source', 'bio', 'namens_vegan', 'namens_glutenfrei', 'namens_laktosefrei', 'requires_la', 'is_platzhalter', 'is_derivat',
        'rezepturware', 'marke_offen',
        'cooking_loss_default_pct', 'trimming_loss_default_pct', 'piece_default_g', 'piece_default_g_source',
        'diameter_mm', 'length_mm', 'width_mm', 'height_mm', 'volume_ml',
        'tag_is_vegan', 'tag_is_vegetarian', 'tag_is_halal', 'tag_contains_pork', 'tag_contains_beef', 'tag_is_organic', 'tag_is_regional',
        'tag_is_staple_food', 'tag_is_convenience', 'tag_is_lactose_free', 'tag_is_gluten_free', 'tag_source',
        'preferred_count_unit_id', 'serving_vehicle_vocab_id', 'derivat_von_gp_id',
    ];

    private const BOOL_SPALTEN = ['requires_la', 'is_platzhalter', 'is_derivat', 'namens_vegan', 'namens_glutenfrei', 'namens_laktosefrei', 'rezepturware', 'marke_offen', 'tag_is_vegan', 'tag_is_vegetarian', 'tag_is_halal', 'tag_contains_pork',
        'tag_contains_beef', 'tag_is_organic', 'tag_is_regional', 'tag_is_staple_food', 'tag_is_convenience', 'tag_is_lactose_free', 'tag_is_gluten_free'];

    public function __construct(private readonly GpNamingService $naming) {}

    // ── Paket lesen + Eingangsprüfung (ganzes Paket) ───────────────────────────────────────────────

    /**
     * @return array{manifest: array<string,mixed>, gps: list<array>, artikel: list<array>, forms: list<array>, textures: list<array>, einheiten: list<array>, aequi: list<array>, anker: ?list<array>, manifest_sha: string}
     *
     * @throws GpPaketAbgelehntException
     */
    public function liesPaket(string $dir, bool $mitAnker = true): array
    {
        if (! is_dir($dir)) {
            throw new GpPaketAbgelehntException(["Paketverzeichnis nicht gefunden: {$dir}"]);
        }
        $fehler = [];
        $mfPfad = rtrim($dir, '/') . '/manifest.json';
        $manifest = is_file($mfPfad) ? json_decode((string) file_get_contents($mfPfad), true) : null;
        if (! is_array($manifest) || ! is_array($manifest['sha256'] ?? null)) {
            throw new GpPaketAbgelehntException(['manifest.json fehlt oder ist nicht lesbar (Feld sha256 erwartet).']);
        }
        $zeilenSoll = is_array($manifest['zeilen'] ?? null) ? $manifest['zeilen'] : [];

        $daten = [];
        foreach (self::DATEIEN as $datei) {
            $pfad = rtrim($dir, '/') . '/' . $datei;
            $pflicht = in_array($datei, ['gps.jsonl', 'gp_artikel.jsonl'], true);
            if (! is_file($pfad)) {
                if ($pflicht) {
                    $fehler[] = "{$datei} fehlt.";
                } elseif (isset($manifest['sha256'][$datei])) {
                    $fehler[] = "{$datei} steht im Manifest, fehlt aber im Verzeichnis.";
                }
                $daten[$datei] = [];

                continue;
            }
            if (! isset($manifest['sha256'][$datei])) {
                $fehler[] = "{$datei} ist nicht im Manifest (sha256).";
            } elseif (! hash_equals((string) $manifest['sha256'][$datei], hash_file('sha256', $pfad))) {
                $fehler[] = "{$datei}: SHA256 stimmt nicht mit dem Manifest überein.";
            }
            $daten[$datei] = $this->liesJsonl($pfad, $fehler);
            if (isset($zeilenSoll[$datei]) && (int) $zeilenSoll[$datei] !== count($daten[$datei])) {
                $fehler[] = "{$datei}: " . count($daten[$datei]) . " Zeilen, Manifest sagt {$zeilenSoll[$datei]}.";
            }
        }

        $anker = null;
        $ankerPfad = rtrim($dir, '/') . '/gp_anker.jsonl';
        if ($mitAnker && is_file($ankerPfad)) {
            $amPfad = rtrim($dir, '/') . '/gp_anker_manifest.json';
            $am = is_file($amPfad) ? json_decode((string) file_get_contents($amPfad), true) : null;
            if (! is_array($am) || ! isset($am['sha256'])) {
                $fehler[] = 'gp_anker.jsonl ohne lesbares gp_anker_manifest.json (sha256) — mit --ohne-anker überspringen.';
            } elseif (! hash_equals((string) $am['sha256'], hash_file('sha256', $ankerPfad))) {
                $fehler[] = 'gp_anker.jsonl: SHA256 stimmt nicht mit gp_anker_manifest.json überein.';
            }
            $anker = $this->liesJsonl($ankerPfad, $fehler);
            if (is_array($am) && isset($am['mappings']) && (int) $am['mappings'] !== count($anker)) {
                $fehler[] = 'gp_anker.jsonl: ' . count($anker) . " Zeilen, Anker-Manifest sagt {$am['mappings']}.";
            }
        }

        // Whitelist: unbekannte oder verbotene Spalten lehnen das ganze Paket ab
        $this->pruefeSpalten('gps.jsonl', $daten['gps.jsonl'], array_merge(self::GP_SPALTEN, self::GP_ZUSATZ), $fehler);
        $this->pruefeSpalten('gp_artikel.jsonl', $daten['gp_artikel.jsonl'], self::ARTIKEL_SPALTEN, $fehler);
        foreach ($daten['gp_artikel.jsonl'] as $i => $a) {
            if (isset($a['struktur']) && ! is_array($a['struktur'])) {
                $fehler[] = 'gp_artikel.jsonl Zeile ' . ($i + 1) . ': struktur ist kein Objekt.';
            } elseif (is_array($a['struktur'] ?? null)) {
                $this->pruefeSpalten('gp_artikel.jsonl[struktur]', [$a['struktur']], self::STRUKTUR_SPALTEN, $fehler, 'struktur');
            }
        }
        foreach (self::KIND_SPALTEN as $datei => $erlaubt) {
            $this->pruefeSpalten($datei, $daten[$datei], $erlaubt, $fehler);
        }
        if ($anker !== null) {
            $this->pruefeSpalten('gp_anker.jsonl', $anker, self::ANKER_SPALTEN, $fehler, 'anker');
        }

        // Schlüssel eindeutig (Dubletten-Gate — der Zweck der ganzen Aktion)
        $keys = [];
        $uuids = [];
        foreach ($daten['gps.jsonl'] as $i => $g) {
            $k = trim((string) ($g['gp_key'] ?? ''));
            if ($k === '') {
                continue;                                    // fehlender Schlüssel = Zeile abgelehnt (siehe plane)
            }
            if (isset($keys[$k])) {
                $fehler[] = "gp_key doppelt im Paket: {$k}";
            }
            $keys[$k] = true;
            $u = strtolower(trim((string) ($g['uuid'] ?? '')));
            if ($u !== '') {
                if (isset($uuids[$u])) {
                    $fehler[] = "uuid doppelt im Paket: {$u} ({$k})";
                }
                $uuids[$u] = true;
            }
        }

        if ($fehler !== []) {
            throw new GpPaketAbgelehntException(array_values(array_unique($fehler)));
        }

        return ['manifest' => $manifest, 'gps' => $daten['gps.jsonl'], 'artikel' => $daten['gp_artikel.jsonl'], 'forms' => $daten['gp_forms.jsonl'],
            'textures' => $daten['gp_textures.jsonl'], 'einheiten' => $daten['gp_einheiten.jsonl'], 'aequi' => $daten['gp_aequivalente.jsonl'],
            'anker' => $anker, 'manifest_sha' => hash('sha256', (string) file_get_contents($mfPfad))];
    }

    /** @param list<string> $fehler */
    private function liesJsonl(string $pfad, array &$fehler): array
    {
        $out = [];
        $nr = 0;
        $h = fopen($pfad, 'rb');
        while (($z = fgets($h)) !== false) {
            $nr++;
            $z = trim($z);
            if ($z === '') {
                continue;
            }
            $j = json_decode($z, true);
            if (! is_array($j)) {
                $fehler[] = basename($pfad) . ": Zeile {$nr} ist kein gültiges JSON-Objekt.";
                if (count($fehler) > 200) {
                    break;
                }

                continue;
            }
            $out[] = $j;
        }
        fclose($h);

        return $out;
    }

    /**
     * @param  list<array>  $zeilen
     * @param  list<string>  $erlaubt
     * @param  list<string>  $fehler
     */
    private function pruefeSpalten(string $datei, array $zeilen, array $erlaubt, array &$fehler, string $art = 'gp'): void
    {
        $unbekannt = [];
        foreach ($zeilen as $z) {
            foreach (array_keys($z) as $k) {
                if (! in_array($k, $erlaubt, true)) {
                    $unbekannt[$k] = ($unbekannt[$k] ?? 0) + 1;
                }
            }
        }
        foreach ($unbekannt as $k => $n) {
            // Anker-Datei darf ai_confidence/ai_reasoning (eigene Whitelist) — die steht in $erlaubt, hier landet nur Fremdes
            $verboten = $art !== 'anker' && preg_match(self::VERBOTEN, (string) $k) === 1;
            $fehler[] = ($verboten ? 'VERBOTENE' : 'unbekannte') . " Spalte „{$k}“ in {$datei} ({$n}×)" . ($verboten ? ' — Allergen-/Nährwert-/KI-/Technikspalten gehören nicht ins Paket.' : ' — nicht in der Whitelist (Plan §14).');
        }
    }

    /** @return ?array{code: string, text: string} */
    public function pruefeGp(array $p, string $key, string $name, ?string $wg, array $ctx): ?array
    {
        if ($key === '' || $name === '') {
            return ['code' => 'pflichtfeld', 'text' => 'gp_key oder name fehlt'];
        }
        $status = (string) ($p['status'] ?? '');
        if (! in_array($status, ['approved', 'tentative'], true)) {
            return ['code' => 'status', 'text' => "Status „{$status}“ ist nicht importierbar (nur approved/tentative)"];
        }
        if ($wg === null || ! isset($ctx['wg'][$wg])) {
            return ['code' => 'wg_unbekannt', 'text' => 'Warengruppe „' . ($p['commodity_group_code'] ?? '∅') . '“ gibt es auf io nicht'];
        }
        $platz = (bool) ($p['is_platzhalter'] ?? false);
        if (! $this->keyStabil($key, $name, $platz)) {
            return ['code' => 'key_instabil', 'text' => "gp_key „{$key}“ ist nicht re-slugify-stabil zum Namen („" . $this->naming->slugify($name) . '“) und kein Pipe-/Platzhalter-Schlüssel'];
        }
        $cond = $p['condition'] ?? null;
        if ($cond !== null && $cond !== '' && ! in_array($cond, GpNamingService::ZUSTAND_VOCAB, true)) {
            return ['code' => 'zustand', 'text' => "Zustand „{$cond}“ nicht im §9-Vokabular"];
        }
        $sub = $p['sub_category'] ?? null;
        if ($sub !== null && trim((string) $sub) !== '' && ! $this->subOk($wg, trim((string) $sub), $ctx)) {
            return ['code' => 'sub_unbekannt', 'text' => "Sub-Kategorie „{$sub}“ gibt es für WG {$wg} auf io weder als Tabellenname noch als Bestandswert"];
        }
        $u = $p['preferred_count_unit_slug'] ?? null;
        if ($u !== null && $u !== '' && ! isset($ctx['einheiten'][$u])) {
            return ['code' => 'einheit_unbekannt', 'text' => "Einheit „{$u}“ gibt es auf io nicht (vocab_units.slug)"];
        }
        $v = $p['serving_vehicle_slug'] ?? null;
        if ($v !== null && $v !== '' && ! isset($ctx['fahrzeuge'][$v])) {
            return ['code' => 'fahrzeug_unbekannt', 'text' => "Servierfahrzeug „{$v}“ gibt es auf io nicht"];
        }
        $derivat = (bool) ($p['is_derivat'] ?? false);
        $mutter = trim((string) ($p['derivat_von_gp_key'] ?? ''));
        if ($derivat && $mutter === '') {
            return ['code' => 'derivat_ohne_mutter', 'text' => 'Derivat ohne derivat_von_gp_key'];
        }
        if (! $derivat && $mutter !== '') {
            return ['code' => 'mutter_ohne_derivat', 'text' => 'derivat_von_gp_key gesetzt, aber is_derivat=0'];
        }
        if ($derivat && (bool) ($p['requires_la'] ?? false)) {
            return ['code' => 'derivat_requires_la', 'text' => 'Derivat muss requires_la=0 haben (Regelwerk §11.2)'];
        }
        if (! $platz) {                                                    // Platzhalter sind bewusst generisch („… (neutral)“): §10-Prüfung entfällt für sie
            $wgn = $wg;
            $pruefung = $this->naming->validateGpName($name, ['condition' => $cond, 'warengruppe' => $wgn, 'commodity_group_code' => $wgn,
                'form' => $p['form'] ?? null, 'hauptzutat' => $p['main_ingredient_display'] ?? null]);
            if ($pruefung['errors'] !== []) {
                return ['code' => 'name_ungueltig', 'text' => implode(' ', $pruefung['errors'])];
            }
            if ($this->naming->enthaeltPlatzhalter($name)) {
                return ['code' => 'name_ungueltig', 'text' => 'Name enthält „unbekannt“'];
            }
        }
        if (in_array($key, $ctx['doppelt'], true)) {
            return ['code' => 'io_doppelt', 'text' => 'gp_key existiert auf io mehrfach (team_id NULL) — erst bereinigen'];
        }
        $uuid = strtolower(trim((string) ($p['uuid'] ?? '')));
        if ($uuid !== '' && isset($ctx['uuids'][$uuid]) && $ctx['uuids'][$uuid] !== $key) {
            return ['code' => 'uuid_vergeben', 'text' => "uuid gehört auf io dem GP {$ctx['uuids'][$uuid]}"];
        }
        $n = $this->normName($name);
        if (isset($ctx['namen'][$n]) && array_diff($ctx['namen'][$n], [$key]) !== []) {
            return ['code' => 'name_vergeben', 'text' => 'Name gehört auf io dem GP ' . implode(', ', array_diff($ctx['namen'][$n], [$key]))];
        }

        return null;
    }

    /** Schreibwerte (nur Spalten, die es im Ziel gibt) + aufgelöste IDs. @return array{wert: array<string,mixed>} */
    public function werte(array $p, ?string $wg, array $ctx): array
    {
        $w = [];
        foreach (self::WRITE_SPALTEN as $c) {
            if (! isset($ctx['gpSpalten'][$c])) {
                continue;
            }
            if ($c === 'commodity_group_code') {
                $w[$c] = $wg;
            } elseif ($c === 'preferred_count_unit_id') {
                if (array_key_exists('preferred_count_unit_slug', $p)) {
                    $w[$c] = ($p['preferred_count_unit_slug'] ?? '') !== '' ? $ctx['einheiten'][$p['preferred_count_unit_slug']] : null;
                }
            } elseif ($c === 'serving_vehicle_vocab_id') {
                if (array_key_exists('serving_vehicle_slug', $p)) {
                    $w[$c] = ($p['serving_vehicle_slug'] ?? '') !== '' ? $ctx['fahrzeuge'][$p['serving_vehicle_slug']] : null;
                }
            } elseif ($c === 'derivat_von_gp_id') {
                continue;                                                  // Mutter-ID erst beim Schreiben
            } elseif (array_key_exists($c, $p)) {
                $v = $p[$c];
                $v = $v === '' ? null : $v;
                if ($v !== null && in_array($c, self::BOOL_SPALTEN, true)) {
                    $v = (int) (bool) $v;
                }
                $w[$c] = $v;
            }
        }

        return ['wert' => $w];
    }

    public function wgCode(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        $s = trim((string) $v);

        return ctype_digit($s) && strlen($s) < 2 ? '0' . $s : $s;
    }

    public function normName(string $n): string
    {
        $n = mb_strtolower(preg_replace('/\s+/u', ' ', trim($n)));

        return strtr($n, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
    }

    private function keyStabil(string $key, string $name, bool $platzhalter): bool
    {
        if ($key === $this->naming->slugify($name)) {
            return true;
        }
        if (str_contains($key, '|')) {                                     // Pipe-Schlüssel: hauptzutat|processing|form[|zusatz], jedes Segment slugify-stabil
            $teile = explode('|', $key);
            // 4. Slot = Klammer-Zusatz bzw. Marke (buildGpKey seit #578/v3.7.1), nur wenn gesetzt — sonst wären Marken-GPs nicht importierbar (io 26.10.)
            $vierterOk = count($teile) === 3 || (count($teile) === 4 && $teile[3] !== '');

            return $vierterOk && $teile[0] !== '' && array_reduce($teile, fn ($ok, $t) => $ok && $this->naming->slugify($t) === $t, true);
        }

        return $platzhalter && preg_match('/^[A-Za-z0-9_]+$/', $key) === 1;
    }

    private function subNorm(string $s): string
    {
        return mb_strtolower(trim((string) preg_replace('/^\s*\d{1,2}\.\d+\s*/u', '', trim($s))));
    }

    private function subOk(string $wg, string $wert, array $ctx): bool
    {
        if (isset($ctx['subBestand'][$wg][$wert])) {
            return true;
        }
        foreach ($ctx['subTabelle'][$wg] ?? [] as $n) {
            if ($this->subNorm($n) === $this->subNorm($wert)) {
                return true;
            }
        }

        return false;
    }

    /** Normalisierter Vergleichswert (Zahlen als Zahlen, Bools als 0/1, leer = null). */
    public function norm(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_bool($v)) {
            return $v ? '1' : '0';
        }
        if (is_int($v) || is_float($v) || (is_string($v) && is_numeric($v))) {
            $s = rtrim(rtrim(number_format((float) $v, 4, '.', ''), '0'), '.');

            return $s === '' || $s === '-0' ? '0' : $s;
        }

        return (string) $v;
    }

    /** Hash der Pflege-Felder einer GP-Zeile (aus der DB gelesen) — Grundlage der Hand-Änderungs-Erkennung. */
    public function hashZeile(array $row, array $ctx): string
    {
        $teile = [];
        foreach (self::WRITE_SPALTEN as $c) {
            if (isset($ctx['gpSpalten'][$c])) {
                $teile[$c] = $this->norm($row[$c] ?? null);
            }
        }
        ksort($teile);

        return md5((string) json_encode($teile));
    }

    /** Alles, was der Plan aus io braucht (nur lesend). @return array<string,mixed> */
    public function kontext(): array
    {
        $hinweise = [];
        $gpSpalten = array_flip(Schema::getColumnListing('foodalchemist_gps'));
        foreach (['team_id', 'stamm_class', 'gp_key', 'name', 'uuid'] as $pflicht) {
            if (! isset($gpSpalten[$pflicht])) {
                throw new \RuntimeException("foodalchemist_gps hat keine Spalte {$pflicht} — Migrationen laufen lassen.");
            }
        }
        $fehlt = array_values(array_filter(array_merge(self::WRITE_SPALTEN, ['status', 'n_las_total', 'first_seen_at']), fn ($c) => ! isset($gpSpalten[$c])));
        if ($fehlt !== []) {
            $hinweise[] = 'Spalten in foodalchemist_gps nicht vorhanden (werden nicht geschrieben): ' . implode(', ', $fehlt);
        }
        $hinweise[] = 'is_favorite/favorite_rank werden nie geschrieben (Favoriten sind seit FAV-3 Team-Overlay).';

        $wg = $this->weich('foodalchemist_lookup_commodity_groups')->whereNull('team_id')->pluck('name', 'code')->all();
        $wg = array_combine(array_map(fn ($c) => (string) $c, array_keys($wg)), array_values($wg));

        $subTabelle = [];
        if (Schema::hasTable('foodalchemist_commodity_group_subcategories')) {
            foreach ($this->weich('foodalchemist_commodity_group_subcategories')->whereNull('team_id')->get(['commodity_group_code', 'name']) as $r) {
                $subTabelle[(string) $r->commodity_group_code][] = (string) $r->name;
            }
        }
        $subBestand = [];
        foreach (DB::table('foodalchemist_gps')->whereNotNull('sub_category')->distinct()->get(['commodity_group_code', 'sub_category']) as $r) {
            $subBestand[(string) $r->commodity_group_code][(string) $r->sub_category] = true;
        }

        $bestand = [];
        $doppelt = [];
        $idZuKey = [];
        $namen = [];
        $uuids = [];
        foreach (DB::table('foodalchemist_gps')->whereNull('team_id')->whereNull('deleted_at')->orderBy('id')->cursor() as $r) {
            $r = (array) $r;
            if (isset($bestand[$r['gp_key']])) {
                $doppelt[] = $r['gp_key'];
            }
            $bestand[$r['gp_key']] = $r;
            $idZuKey[(int) $r['id']] = $r['gp_key'];
            if (($r['status'] ?? '') !== 'merged') {
                $namen[$this->normName((string) $r['name'])][] = $r['gp_key'];
            }
            $uuids[strtolower((string) $r['uuid'])] = $r['gp_key'];
        }

        return ['hinweise' => $hinweise, 'gpSpalten' => $gpSpalten, 'wg' => $wg, 'subTabelle' => $subTabelle, 'subBestand' => $subBestand,
            'einheiten' => $this->weich('foodalchemist_vocab_units')->whereNull('team_id')->pluck('id', 'slug')->all(),
            'texturen' => DB::table('foodalchemist_vocab_textures')->pluck('id', 'slug')->all(),
            'fahrzeuge' => $this->weich('foodalchemist_vocab_serving_vehicles')->whereNull('team_id')->pluck('id', 'slug')->all(),
            'bestand' => $bestand, 'doppelt' => array_values(array_unique($doppelt)), 'idZuKey' => $idZuKey, 'namen' => $namen, 'uuids' => $uuids];
    }

    private function weich(string $tabelle): \Illuminate\Database\Query\Builder
    {
        $q = DB::table($tabelle);

        return Schema::hasColumn($tabelle, 'deleted_at') ? $q->whereNull('deleted_at') : $q;
    }
}
