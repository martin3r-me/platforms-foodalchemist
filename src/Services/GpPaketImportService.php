<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\Core\Models\Team;
use Platform\Core\Models\User;
use Platform\FoodAlchemist\Exceptions\GpPaketAbgelehntException;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistItemAllergen;
use Platform\FoodAlchemist\Models\FoodAlchemistItemDeclaration;
use Platform\FoodAlchemist\Support\DeklarationsProfil;
use Platform\FoodAlchemist\Support\FaTeam;
use Platform\FoodAlchemist\Support\GpPaketZaehler;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Support\StammPaket;
use Platform\FoodAlchemist\Support\TeamScope;
use Symfony\Component\Uid\UuidV7;

/**
 * GP-Paket → io (Plan `_GP_Import_Plan_io.md` §0, §3–§8, §12–§14; Kurator/Dominique 23.10.2026).
 *
 * Liest ein Paket (JSON-Lines + Manifest, erzeugt von Skript 300) und legt GPs global (`team_id NULL`, `stamm_class = stamm_paket`) an
 * bzw. gleicht sie ab. Identität: GP = `gp_key` (per Code geprüft, es gibt bei team_id NULL keinen DB-Unique), Artikel = Necta-`legacy_id`.
 *
 * Zwei Betriebsarten über denselben Plan: der Trockenlauf rechnet alles (inkl. Allergen-Guard-Simulation) und schreibt NICHTS;
 * `--apply` führt den Plan in EINER Transaktion aus. Verknüpft wird über den NORMALEN Weg {@see LeadLaService::verknuepfen()}
 * (dort greift {@see DeklarationsProfil}); lehnt der Guard ab, wird die Verknüpfung NICHT gesetzt und in `guard_abgelehnt` gelistet —
 * nie automatisch umgehängt oder ein neues GP angelegt. Massen-Events (Embedding-Observer, Aktivitätsprotokoll, Kosten-Recompute)
 * werden unterdrückt; nach dem Lauf kommt höchstens EIN gesammelter Recompute für Leads bestehender GPs.
 *
 * Nie überschrieben wird, was auf io nach dem letzten Lauf von Hand geändert wurde: je GP merkt das Protokoll einen Hash der
 * Pflege-Felder (`gp_stand`); stimmt der Ist-Stand nicht mehr damit überein (oder fehlt der Vorlauf), landet jede Abweichung
 * in der Konfliktliste statt im Update. Der Importer befördert nie `tentative` → `approved`.
 */
class GpPaketImportService
{
    use PlattformAdminPruefung;

    /** Pflicht-/Daten-Dateien des Pakets (Skript 300). `gps.jsonl` und `gp_artikel.jsonl` müssen da sein. */
    public const DATEIEN = ['gps.jsonl', 'gp_artikel.jsonl', 'gp_forms.jsonl', 'gp_textures.jsonl', 'gp_einheiten.jsonl', 'gp_aequivalente.jsonl'];

    /** Whitelist der GP-Spalten im Paket (Plan §14, = GP_SPALTEN des Export-Skripts). */
    public const GP_SPALTEN = [
        'uuid', 'gp_key', 'name', 'main_ingredient_slug', 'main_ingredient_display', 'processing', 'form', 'commodity_group_code', 'sub_category',
        'condition', 'condition_source', 'bio', 'requires_la', 'is_platzhalter', 'is_derivat', 'status',
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
    private const WRITE_SPALTEN = [
        'name', 'main_ingredient_slug', 'main_ingredient_display', 'processing', 'form', 'commodity_group_code', 'sub_category',
        'condition', 'condition_source', 'bio', 'requires_la', 'is_platzhalter', 'is_derivat',
        'cooking_loss_default_pct', 'trimming_loss_default_pct', 'piece_default_g', 'piece_default_g_source',
        'diameter_mm', 'length_mm', 'width_mm', 'height_mm', 'volume_ml',
        'tag_is_vegan', 'tag_is_vegetarian', 'tag_is_halal', 'tag_contains_pork', 'tag_contains_beef', 'tag_is_organic', 'tag_is_regional',
        'tag_is_staple_food', 'tag_is_convenience', 'tag_is_lactose_free', 'tag_is_gluten_free', 'tag_source',
        'preferred_count_unit_id', 'serving_vehicle_vocab_id', 'derivat_von_gp_id',
    ];

    private const BOOL_SPALTEN = ['requires_la', 'is_platzhalter', 'is_derivat', 'tag_is_vegan', 'tag_is_vegetarian', 'tag_is_halal', 'tag_contains_pork',
        'tag_contains_beef', 'tag_is_organic', 'tag_is_regional', 'tag_is_staple_food', 'tag_is_convenience', 'tag_is_lactose_free', 'tag_is_gluten_free'];

    public function __construct(
        private readonly GpNamingService $naming,
        private readonly LeadLaService $lead,
        private readonly PriceService $preise,
        private readonly PairingService $pairing,
        private readonly GpBereinigenService $verweise,
    ) {}

    // ── Master-Kontext ─────────────────────────────────────────────────────────────────────────────

    /**
     * Nur das Master-Team, nur ein Plattform-Admin (Plan §4.1 Punkt 1). Der Aufruf aus der Konsole gibt den Admin ausdrücklich an
     * (`--admin=<E-Mail>`), weil es dort keine Anmeldung gibt.
     *
     * @throws \Platform\FoodAlchemist\Exceptions\FaRechtFehltException
     */
    public function master(?User $user, string $wofuer): Team
    {
        $this->nurPlattformAdmin($user, $wofuer);
        $id = MasterTeam::id();
        $team = $id !== null ? Team::find($id) : null;
        if ($team === null) {
            throw new \RuntimeException('Kein Master-Team konfiguriert (FA-Plattform-Einstellung oder FOODALCHEMIST_MASTER_TEAM_ID) — Abbruch.');
        }

        return $team;
    }

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

    // ── Vorlauf (Hand-Änderungen erkennen) ─────────────────────────────────────────────────────────

    /** @return array<string,string> gp_key → Hash der Pflege-Felder nach dem letzten Lauf */
    public function ladeVorlauf(?string $pfad): array
    {
        if ($pfad === null || $pfad === '' || ! is_file($pfad)) {
            return [];
        }
        $p = json_decode((string) file_get_contents($pfad), true);

        return is_array($p) && is_array($p['gp_stand'] ?? null) ? $p['gp_stand'] : [];
    }

    // ── Lauf ───────────────────────────────────────────────────────────────────────────────────────

    /**
     * @param  array{paket: string, apply?: bool, user?: ?User, umhaengen?: bool, nur_wg?: ?string, ohne_anker?: bool, vorlauf?: ?string, ohne_recompute?: bool}  $o
     * @return array{modus: string, zaehler: GpPaketZaehler, listen: array<string,list<array>>, gp_key_zu_id: array<string,?int>, protokoll: ?array, dauer_s: float, hinweise: list<string>}
     *
     * @throws GpPaketAbgelehntException
     */
    public function lauf(array $o): array
    {
        $t0 = microtime(true);
        $apply = (bool) ($o['apply'] ?? false);
        $master = $this->master($o['user'] ?? null, $apply ? 'GP-Paket importieren' : 'GP-Paket prüfen');
        $this->preisTeam = $master;
        $this->preisCache = [];
        $paket = $this->liesPaket((string) $o['paket'], ! ($o['ohne_anker'] ?? false));
        $plan = $this->plane($paket, $o);
        $protokoll = null;
        if ($apply) {
            $protokoll = FaTeam::imTeam($master, function () use (&$plan, $paket, $master, $o) {
                return $this->fuehreAus($plan, $paket, $master, $o);
            });
        }

        $zuId = [];
        foreach ($plan['gps'] as $key => $g) {
            $zuId[$key] = $g['id'];
        }
        ksort($zuId);

        return ['modus' => $apply ? 'apply' : 'dry-run', 'zaehler' => $plan['z'], 'listen' => $plan['listen'], 'gp_key_zu_id' => $zuId,
            'protokoll' => $protokoll, 'dauer_s' => round(microtime(true) - $t0, 2), 'hinweise' => $plan['hinweise']];
    }

    /**
     * Soll/Ist nach einem Lauf (schreibt nichts): derselbe Plan gegen den aktuellen io-Stand — alles, was der Importer noch tun
     * würde (neu, geändert, Verknüpfung fehlt …), ist eine Abweichung. Erwartete Lücken (kein Treffer, Guard, mehrdeutig) sind keine.
     *
     * @param  array{paket: string, user?: ?User, nur_wg?: ?string, ohne_anker?: bool, umhaengen?: bool}  $o
     * @return array{abweichungen: list<array<string,mixed>>, erwartet: list<array<string,mixed>>, soll_ist: list<array<string,mixed>>, zaehler: GpPaketZaehler}
     */
    public function verifiziere(array $o): array
    {
        $this->preisTeam = $this->master($o['user'] ?? null, 'GP-Paket prüfen');
        $this->preisCache = [];
        $paket = $this->liesPaket((string) $o['paket'], ! ($o['ohne_anker'] ?? false));
        $plan = $this->plane($paket, ['vorlauf_stand' => [], 'umhaengen' => true] + $o);
        $z = $plan['z'];
        $abw = [];
        $erw = [];
        foreach ($plan['gps'] as $key => $g) {
            $wg = $g['wg'];
            if ($g['aktion'] === 'neu') {
                $abw[] = ['wg' => $wg, 'gp_key' => $key, 'art' => 'GP fehlt auf io', 'detail' => $g['name']];

                continue;
            }
            if ($g['aktion'] !== 'unveraendert') {
                $abw[] = ['wg' => $wg, 'gp_key' => $key, 'art' => 'GP weicht vom Paket ab', 'detail' => implode('; ', array_map(fn ($c, $d) => "{$c}: io=" . ($d[0] ?? '∅') . " paket=" . ($d[1] ?? '∅'), array_keys($g['diff']), $g['diff']))];
            }
            $set = $g['set'];
            if ((int) ($g['bestand']['n_las_total'] ?? 0) !== count($set)) {
                $abw[] = ['wg' => $wg, 'gp_key' => $key, 'art' => 'n_las_total weicht ab', 'detail' => 'io=' . (int) ($g['bestand']['n_las_total'] ?? 0) . ' soll=' . count($set)];
            }
            foreach ($g['links'] as $l) {
                $info = ['wg' => $wg, 'gp_key' => $key, 'detail' => 'legacy_id ' . $l['legacy'] . ($l['grund'] !== '' ? ' — ' . $l['grund'] : '')];
                match ($l['status']) {
                    'neu' => $abw[] = $info + ['art' => 'Verknüpfung fehlt'],
                    'umhaengen', 'uebersprungen' => $abw[] = $info + ['art' => 'Artikel hängt an anderem GP'],
                    'ohne_match', 'mehrdeutig', 'guard_abgelehnt' => $erw[] = $info + ['art' => 'erwartet: ' . $l['status']],
                    default => null,
                };
            }
            if ($g['bestand'] !== null && (int) $g['bestand']['requires_la'] === 1 && $set !== []) {
                $lead = $g['lead_alt'];
                $hatBepreisten = false;
                foreach ($set as $i) {
                    if ($this->hatPreis($i)) {
                        $hatBepreisten = true;

                        break;
                    }
                }
                if ($hatBepreisten && ($lead === null || ! in_array($lead, $set, true) || ! $this->hatPreis($lead))) {
                    $abw[] = ['wg' => $wg, 'gp_key' => $key, 'art' => 'Lead fehlt oder löst nicht auf', 'detail' => 'Lead=' . ($lead ?? '∅')];
                }
                if (! $hatBepreisten && $lead !== null && $this->hatPreis($lead) === false && in_array($lead, $set, true)) {
                    $erw[] = ['wg' => $wg, 'gp_key' => $key, 'art' => 'erwartet: Lead Park', 'detail' => 'kein bepreister Artikel'];
                }
            }
            if ($g['bestand'] !== null && $g['bestand']['status'] === 'approved' && (int) $g['bestand']['requires_la'] === 1 && ! (int) $g['bestand']['is_derivat'] && ! (int) $g['bestand']['is_platzhalter'] && $set === []) {
                $abw[] = ['wg' => $wg, 'gp_key' => $key, 'art' => 'approved ohne Artikel (§13.4)', 'detail' => $g['name']];
            }
        }
        foreach ($plan['listen']['gp_abgelehnt'] as $r) {
            $erw[] = ['wg' => $r['wg'], 'gp_key' => $r['gp_key'], 'art' => 'erwartet: GP abgelehnt', 'detail' => $r['grund']];
        }
        foreach (['kind_neu' => 'Kind-Zeilen fehlen auf io', 'anker_neu' => 'Anker fehlen/weichen ab'] as $k => $text) {
            if ($z->summe($k) > 0) {
                $abw[] = ['wg' => '*', 'gp_key' => '', 'art' => $text, 'detail' => $z->summe($k) . ' Zeile(n)'];
            }
        }
        $soll = [];
        $g = $z->gesamt();
        foreach (['gp_paket' => 'GPs im Paket', 'v_bestehend' => 'Verknüpfungen bestehend (Ist)', 'n_las_erwartet' => 'Σ n_las_total (Soll)'] as $k => $t) {
            $soll[] = ['kennzahl' => $t, 'wert' => $g[$k]];
        }
        $soll[] = ['kennzahl' => 'erwartete Lücken (ohne Treffer / mehrdeutig / Guard / abgelehnt)', 'wert' => count($erw)];
        $soll[] = ['kennzahl' => 'Abweichungen', 'wert' => count($abw)];

        return ['abweichungen' => $abw, 'erwartet' => $erw, 'soll_ist' => $soll, 'zaehler' => $z];
    }

    // ── Plan (liest nur) ───────────────────────────────────────────────────────────────────────────

    /**
     * @param  array<string,mixed>  $paket
     * @param  array<string,mixed>  $o
     * @return array{gps: array<string,array<string,mixed>>, z: GpPaketZaehler, listen: array<string,list<array>>, hinweise: list<string>, ctx: array<string,mixed>, ankerGp: array<string,array<string,mixed>>}
     */
    private function plane(array $paket, array $o): array
    {
        $nurWg = ($o['nur_wg'] ?? null) !== null && $o['nur_wg'] !== '' ? $this->wgCode($o['nur_wg']) : null;
        $umhaengen = (bool) ($o['umhaengen'] ?? false);
        $vorlauf = $o['vorlauf_stand'] ?? $this->ladeVorlauf($o['vorlauf'] ?? null);
        $z = new GpPaketZaehler;
        $listen = ['gp_abgelehnt' => [], 'konflikte' => [], 'review_ohne_match' => [], 'guard_abgelehnt' => [], 'lead_park' => [],
            'anker_abgelehnt' => [], 'kinder_abgelehnt' => [], 'tentative_mit_artikel' => [], 'ohne_artikel' => []];
        $hinweise = [];
        $ctx = $this->kontext();

        // ── 1. GP-Zeilen prüfen ──────────────────────────────────────────────────────────────────
        $alleKeys = [];
        foreach ($paket['gps'] as $p) {
            $alleKeys[trim((string) ($p['gp_key'] ?? ''))] = $p;
        }
        $P = [];
        $abg = [];
        foreach ($paket['gps'] as $p) {
            $key = trim((string) ($p['gp_key'] ?? ''));
            $wg = $this->wgCode($p['commodity_group_code'] ?? null);
            if ($nurWg !== null && $wg !== $nurWg) {
                continue;                                                // Teillauf: andere Warengruppen bleiben unberührt
            }
            $name = trim((string) ($p['name'] ?? ''));
            $wgAnzeige = $wg ?? '--';
            $z->inc($wgAnzeige, 'gp_paket');
            $g = ['key' => $key, 'name' => $name, 'wg' => $wgAnzeige, 'p' => $p, 'derivat' => (bool) ($p['is_derivat'] ?? false)];

            $grund = $this->pruefeGp($p, $key, $name, $wg, $ctx);
            if ($grund !== null) {
                $abg[$key] = $grund + ['wg' => $wgAnzeige, 'name' => $name];
                $P[$key] = $g;

                continue;
            }
            $g += $this->werte($p, $wg, $ctx);
            $P[$key] = $g;
        }

        // Dubletten im Paket (umlaut-normalisiert): alle Beteiligten raus — das ist der Zweck der Aktion
        $namen = [];
        foreach ($P as $key => $g) {
            if (! isset($abg[$key])) {
                $namen[$this->normName($g['name'])][] = $key;
            }
        }
        foreach ($namen as $n => $keys) {
            if (count($keys) > 1) {
                foreach ($keys as $k) {
                    $abg[$k] = ['code' => 'name_doppelt', 'text' => 'Name doppelt im Paket (umlaut-normalisiert): ' . implode(', ', $keys), 'wg' => $P[$k]['wg'], 'name' => $P[$k]['name']];
                }
            }
        }
        // Derivate: Mutter im Paket (angenommen, kein Derivat) oder schon auf io; eine Ebene
        foreach ($P as $key => $g) {
            if (isset($abg[$key]) || ! $g['derivat']) {
                continue;
            }
            $m = trim((string) ($g['p']['derivat_von_gp_key'] ?? ''));
            $mutterOk = false;
            if (isset($P[$m])) {
                $mutterOk = ! isset($abg[$m]) && ! $P[$m]['derivat'];
                if ($P[$m]['derivat']) {
                    $abg[$key] = ['code' => 'derivat_von_derivat', 'text' => "Derivat von Derivat verboten (Mutter {$m})", 'wg' => $g['wg'], 'name' => $g['name']];

                    continue;
                }
                if (! $mutterOk) {
                    $abg[$key] = ['code' => 'mutter_abgelehnt', 'text' => "Mutter {$m} ist abgelehnt: " . ($abg[$m]['text'] ?? '?'), 'wg' => $g['wg'], 'name' => $g['name']];

                    continue;
                }
            } elseif (isset($alleKeys[$m])) {                              // Mutter im Paket, aber ausserhalb des Teillaufs → muss auf io stehen
                $b = $ctx['bestand'][$m] ?? null;
                if ($b === null || (int) $b['is_derivat'] === 1) {
                    $abg[$key] = ['code' => 'mutter_ausserhalb', 'text' => "Mutter {$m} liegt ausserhalb des Teillaufs und ist noch nicht auf io", 'wg' => $g['wg'], 'name' => $g['name']];
                }
            } else {
                $abg[$key] = ['code' => 'mutter_fehlt', 'text' => "Mutter {$m} ist nicht im Paket", 'wg' => $g['wg'], 'name' => $g['name']];
            }
        }
        foreach ($abg as $key => $a) {
            $z->inc($a['wg'], 'gp_abgelehnt');
            $listen['gp_abgelehnt'][] = ['wg' => $a['wg'], 'gp_key' => $key, 'name' => $a['name'], 'code' => $a['code'], 'grund' => $a['text']];
            unset($P[$key]);
        }

        // ── 2. Abgleich mit io: neu / unverändert / geändert / Konflikt ─────────────────────────
        foreach ($P as $key => &$g) {
            $b = $ctx['bestand'][$key] ?? null;
            $g['bestand'] = $b;
            $g['id'] = $b !== null ? (int) $b['id'] : null;
            $g['diff'] = [];
            $g['frei'] = true;
            if ($b === null) {
                $g['aktion'] = 'neu';

                continue;
            }
            if (! in_array($b['status'], ['approved', 'tentative'], true)) {
                $g['aktion'] = 'konflikt';
                $listen['konflikte'][] = ['wg' => $g['wg'], 'gp_key' => $key, 'feld' => 'status', 'io' => $b['status'], 'paket' => $g['p']['status'] ?? '', 'hinweis' => 'GP steht auf io nicht auf approved/tentative'];
                $z->inc($g['wg'], 'gp_konflikt');
                $g['frei'] = false;

                continue;
            }
            $diff = [];
            foreach ($g['wert'] as $c => $v) {
                if ($c === 'derivat_von_gp_id') {
                    continue;
                }
                if ($this->norm($v) !== $this->norm($b[$c] ?? null)) {
                    $diff[$c] = [$b[$c] ?? null, $v];
                }
            }
            $mutterIo = ($b['derivat_von_gp_id'] ?? null) !== null ? ($ctx['idZuKey'][(int) $b['derivat_von_gp_id']] ?? '?') : null;
            $mutterPaket = $g['derivat'] ? trim((string) ($g['p']['derivat_von_gp_key'] ?? '')) : null;
            if ($mutterIo !== $mutterPaket) {
                $diff['derivat_von_gp_key'] = [$mutterIo, $mutterPaket];
            }
            $g['diff'] = $diff;
            $stand = $vorlauf[$key] ?? null;
            $g['frei'] = $stand !== null && $stand === $this->hashZeile($b, $ctx);
            if ($diff === []) {
                $g['aktion'] = 'unveraendert';
            } elseif ($g['frei']) {
                $g['aktion'] = 'geaendert';
            } else {
                $g['aktion'] = 'konflikt';
                foreach ($diff as $c => [$io, $pk]) {
                    $listen['konflikte'][] = ['wg' => $g['wg'], 'gp_key' => $key, 'feld' => $c, 'io' => $io, 'paket' => $pk,
                        'hinweis' => $stand === null ? 'kein Vorlauf-Protokoll für diesen GP — nichts überschrieben' : 'auf io nach dem letzten Lauf von Hand geändert — nichts überschrieben'];
                }
                $z->inc($g['wg'], 'gp_konflikt');
            }
            if ($g['aktion'] === 'unveraendert') {
                $z->inc($g['wg'], 'gp_unveraendert');
            } elseif ($g['aktion'] === 'geaendert') {
                $z->inc($g['wg'], 'gp_geaendert');
            }
        }
        unset($g);

        // Zustand NULL: gültig bei Getränken (WG 15), Öle/Fette (11), Sirupe/Dicksäfte/Melassen (10/12) — sonst nur Zählung im Bericht (Entscheid Dominique 23.10.)
        $ohneZustand = [];
        foreach ($P as $key => $g) {
            $c = $g['p']['condition'] ?? null;
            if (($c === null || $c === '') && ! in_array($g['wg'], ['10', '11', '12', '15', '16'], true) && ! (bool) ($g['p']['is_platzhalter'] ?? false)) {
                $ohneZustand[] = $key;
            }
        }
        if ($ohneZustand !== []) {
            $hinweise[] = count($ohneZustand) . ' Lebensmittel-GP(s) ohne Zustand (NULL) ausserhalb WG 10/11/12/15 — angenommen, aber zu prüfen, z. B. ' . implode(', ', array_slice($ohneZustand, 0, 5));
        }
        // ── 3. Kind-Tabellen ─────────────────────────────────────────────────────────────────────
        $this->planeKinder($P, $paket, $ctx, $z, $listen);

        // ── 4. Artikel-Verknüpfung (Treffer, Guard-Simulation) ───────────────────────────────────
        $this->planeLinks($P, $paket, $ctx, $z, $listen, $umhaengen);

        // ── 5. Status (§13.4), Lead, n_las_total ────────────────────────────────────────────────
        foreach ($P as $key => &$g) {
            $wg = $g['wg'];
            $set = $g['set'];
            $paketStatus = (string) ($g['p']['status'] ?? 'tentative');
            $braucht = (bool) ($g['wert']['requires_la'] ?? true);
            $deriv = (bool) ($g['wert']['is_derivat'] ?? false);
            $platz = (bool) ($g['wert']['is_platzhalter'] ?? false);
            $g['status_soll'] = ($paketStatus === 'approved' && (! $braucht || $deriv || $platz || $set !== [])) ? 'approved' : 'tentative';
            if ($g['aktion'] === 'neu') {
                $z->inc($wg, 'gp_neu');
                $z->inc($wg, $g['status_soll'] === 'approved' ? 'gp_neu_approved' : 'gp_neu_tentative');
            }
            if ($g['derivat']) {
                $z->inc($wg, 'derivate');
            }
            if ($platz) {
                $z->inc($wg, 'platzhalter');
            }
            if ($set === []) {
                $z->inc($wg, 'gp_ohne_artikel');
                if ($braucht) {
                    $z->inc($wg, 'gp_ohne_artikel_requires_la');
                }
                $listen['ohne_artikel'][] = ['wg' => $wg, 'gp_key' => $key, 'name' => $g['name'], 'requires_la' => $braucht ? 1 : 0, 'status_neu' => $g['status_soll']];
            }
            if ($g['bestand'] !== null && $g['bestand']['status'] === 'tentative' && $set !== []) {
                $z->inc($wg, 'tentative_nun_mit_artikel');
                $listen['tentative_mit_artikel'][] = ['wg' => $wg, 'gp_key' => $key, 'name' => $g['name'], 'artikel' => count($set)];
            }
            $z->inc($wg, 'n_las_erwartet', count($set));

            // Lead (Schätzung): bestehender gültiger Lead bleibt, sonst Paket-Lead mit Preis, sonst gewählt (Obergrenze) oder Park
            if ($braucht && ! $deriv && $set !== []) {
                $alt = $g['lead_alt'];
                if ($alt !== null && in_array($alt, $set, true) && $this->hatPreis($alt)) {
                    $z->inc($wg, 'lead_unveraendert');
                } elseif ($g['paket_lead'] !== null && in_array($g['paket_lead'], $set, true) && $this->hatPreis($g['paket_lead'])) {
                    $z->inc($wg, 'lead_uebernommen');
                } elseif (array_filter($set, fn ($i) => $this->hatPreis($i)) !== []) {
                    $z->inc($wg, 'lead_neu');
                } else {
                    $z->inc($wg, 'lead_park');
                    $listen['lead_park'][] = ['wg' => $wg, 'gp_key' => $key, 'name' => $g['name'], 'artikel' => count($set)];
                }
            }
        }
        unset($g);

        // ── 6. Anker ─────────────────────────────────────────────────────────────────────────────
        $ankerGp = [];
        if ($paket['anker'] !== null) {
            $ankerGp = $this->planeAnker($P, $paket, $ctx, $z, $listen, $nurWg, $alleKeys);
        }

        return ['gps' => $P, 'z' => $z, 'listen' => $listen, 'hinweise' => array_merge($hinweise, $ctx['hinweise']), 'ctx' => $ctx, 'ankerGp' => $ankerGp, 'abg' => $abg];
    }

    /** @return ?array{code: string, text: string} */
    private function pruefeGp(array $p, string $key, string $name, ?string $wg, array $ctx): ?array
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
    private function werte(array $p, ?string $wg, array $ctx): array
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

    private function wgCode(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        $s = trim((string) $v);

        return ctype_digit($s) && strlen($s) < 2 ? '0' . $s : $s;
    }

    private function normName(string $n): string
    {
        $n = mb_strtolower(preg_replace('/\s+/u', ' ', trim($n)));

        return strtr($n, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
    }

    private function keyStabil(string $key, string $name, bool $platzhalter): bool
    {
        if ($key === $this->naming->slugify($name)) {
            return true;
        }
        if (str_contains($key, '|')) {                                     // Pipe-Schlüssel: hauptzutat|processing|form, jedes Segment slugify-stabil
            $teile = explode('|', $key);

            return count($teile) === 3 && $teile[0] !== '' && array_reduce($teile, fn ($ok, $t) => $ok && $this->naming->slugify($t) === $t, true);
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
    private function norm(mixed $v): ?string
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
    private function hashZeile(array $row, array $ctx): string
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

    private array $preisCache = [];

    /** Sicht für Preise: das Master-Team. Globale Stamm-Artikel (stamm_paket) haben ohne Team KEINE sichtbaren Preise (PriceService::stammFilter). */
    private ?Team $preisTeam = null;

    private function hatPreis(int $itemId): bool
    {
        if (! array_key_exists($itemId, $this->preisCache)) {
            $p = $this->preise->activeFor($itemId, $this->preisTeam);
            $this->preisCache[$itemId] = $p !== null && (float) $p->price > 0;
        }

        return $this->preisCache[$itemId];
    }

    /** Alles, was der Plan aus io braucht (nur lesend). @return array<string,mixed> */
    private function kontext(): array
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

    // ── Kind-Tabellen ──────────────────────────────────────────────────────────────────────────────

    /**
     * Fehlende Zeilen werden ergänzt; abweichende bestehende Zeilen nur aktualisiert, wenn der GP „frei“ ist (neu oder unverändert seit dem
     * letzten Lauf) — sonst als abweichend gezählt und nicht überschrieben.
     *
     * @param  array<string,array<string,mixed>>  $P
     */
    private function planeKinder(array &$P, array $paket, array $ctx, GpPaketZaehler $z, array &$listen): void
    {
        foreach ($P as &$g) {
            $g['kinder'] = ['forms' => [], 'textures' => [], 'einheiten' => [], 'aequi' => []];
        }
        unset($g);
        $abgelehnt = function (string $datei, string $key, string $grund) use (&$P, $z, &$listen) {
            $wg = $P[$key]['wg'] ?? '--';
            $z->inc($wg, 'kind_abgelehnt');
            $listen['kinder_abgelehnt'][] = ['wg' => $wg, 'datei' => $datei, 'gp_key' => $key, 'grund' => $grund];
        };
        $gesehen = [];
        foreach ($paket['forms'] as $r) {
            $k = (string) ($r['gp_key'] ?? '');
            if (! isset($P[$k])) {
                continue;                                                    // GP abgelehnt oder ausserhalb des Teillaufs
            }
            $slug = (string) ($r['form_slug'] ?? '');
            if (! isset($ctx['einheiten'][$slug])) {
                $abgelehnt('gp_forms', $k, "form_slug „{$slug}“ gibt es auf io nicht (vocab_units.slug)");

                continue;
            }
            if (! is_numeric($r['gramm'] ?? null) || (float) $r['gramm'] <= 0) {
                $abgelehnt('gp_forms', $k, "gramm für „{$slug}“ ungültig");

                continue;
            }
            if (isset($gesehen['f'][$k][$slug])) {
                $abgelehnt('gp_forms', $k, "form_slug „{$slug}“ doppelt im Paket");

                continue;
            }
            $gesehen['f'][$k][$slug] = true;
            $P[$k]['kinder']['forms'][$slug] = ['gramm' => (float) $r['gramm'], 'source' => $this->kuerze($r['source'] ?? 'manual', 16)];
        }
        foreach ($paket['textures'] as $r) {
            $k = (string) ($r['gp_key'] ?? '');
            if (! isset($P[$k])) {
                continue;
            }
            $slug = (string) ($r['texture_slug'] ?? '');
            if (! isset($ctx['texturen'][$slug])) {
                $abgelehnt('gp_textures', $k, "texture_slug „{$slug}“ gibt es auf io nicht");

                continue;
            }
            if (isset($P[$k]['kinder']['textures'][$slug])) {
                $abgelehnt('gp_textures', $k, "texture_slug „{$slug}“ doppelt im Paket");

                continue;
            }
            $P[$k]['kinder']['textures'][$slug] = ['intensity' => is_numeric($r['intensity'] ?? null) ? (float) $r['intensity'] : 1.0, 'source' => $this->kuerze($r['source'] ?? null, 16)];
        }
        foreach ($paket['einheiten'] as $r) {
            $k = (string) ($r['gp_key'] ?? '');
            if (! isset($P[$k])) {
                continue;
            }
            $slug = (string) ($r['unit_slug'] ?? '');
            if (! isset($ctx['einheiten'][$slug])) {
                $abgelehnt('gp_einheiten', $k, "unit_slug „{$slug}“ gibt es auf io nicht (vocab_units.slug)");

                continue;
            }
            if (! is_numeric($r['default_g'] ?? null) || isset($P[$k]['kinder']['einheiten'][$slug])) {
                $abgelehnt('gp_einheiten', $k, "default_g für „{$slug}“ ungültig oder Einheit doppelt");

                continue;
            }
            $P[$k]['kinder']['einheiten'][$slug] = ['default_g' => (float) $r['default_g'], 'is_primary' => (int) (bool) ($r['is_primary'] ?? false),
                'sort_order' => (int) ($r['sort_order'] ?? 0), 'source' => $this->kuerze($r['source'] ?? null, 16)];
        }
        foreach ($paket['aequi'] as $r) {
            $a = (string) ($r['source_gp_key'] ?? '');
            $b = (string) ($r['alt_gp_key'] ?? '');
            if (! isset($P[$a])) {
                continue;
            }
            if (! isset($P[$b]) && ! isset($ctx['bestand'][$b])) {
                $abgelehnt('gp_aequivalente', $a, "Gegen-GP {$b} ist nicht im Lauf und nicht auf io");

                continue;
            }
            $P[$a]['kinder']['aequi'][$b] = ['faktor' => is_numeric($r['umrechnungsfaktor'] ?? null) ? (float) $r['umrechnungsfaktor'] : 1.0,
                'seite' => in_array($r['standard_seite'] ?? 'source', ['source', 'alt'], true) ? $r['standard_seite'] ?? 'source' : 'source',
                'conf' => is_numeric($r['match_confidence'] ?? null) ? (float) $r['match_confidence'] : null, 'notes' => $r['notes'] ?? null];
        }

        // Abgleich gegen io (nur für bestehende GPs)
        $ids = array_values(array_filter(array_map(fn ($g) => $g['id'], $P)));
        $io = ['forms' => [], 'textures' => [], 'einheiten' => [], 'aequi' => []];
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach (DB::table('foodalchemist_gp_forms')->whereIn('gp_id', $chunk)->whereNull('deleted_at')->get() as $r) {
                $io['forms'][$r->gp_id][$r->form_slug] = (array) $r;
            }
            foreach (DB::table('foodalchemist_gp_textures')->whereIn('gp_id', $chunk)->get() as $r) {
                $io['textures'][$r->gp_id][$r->texture_vocab_id] = (array) $r;
            }
            foreach (DB::table('foodalchemist_gp_count_unit_defaults')->whereIn('gp_id', $chunk)->whereNull('deleted_at')->get() as $r) {
                $io['einheiten'][$r->gp_id][$r->unit_vocab_id] = (array) $r;
            }
            foreach (DB::table('foodalchemist_component_equivalents')->whereNull('team_id')->where('source_kind', 'gp')->where('alt_kind', 'gp')
                ->whereIn('source_id', $chunk)->whereNull('deleted_at')->get() as $r) {
                $io['aequi'][$r->source_id][$r->alt_id] = (array) $r;
            }
        }
        foreach ($P as $key => &$g) {
            $id = $g['id'];
            $frei = $g['aktion'] === 'neu' || ($g['frei'] ?? false);
            $wg = $g['wg'];
            $plan = ['forms' => [], 'textures' => [], 'einheiten' => [], 'aequi' => []];
            foreach ($g['kinder']['forms'] as $slug => $w) {
                $ist = $id !== null ? ($io['forms'][$id][$slug] ?? null) : null;
                if ($ist === null) {
                    $plan['forms'][] = ['aktion' => 'neu', 'slug' => $slug] + $w;
                    $z->inc($wg, 'kind_neu');
                } elseif ($this->norm($ist['gramm']) === $this->norm($w['gramm']) && ($ist['source'] ?? null) === $w['source']) {
                    $z->inc($wg, 'kind_unveraendert');
                } elseif ($frei) {
                    $plan['forms'][] = ['aktion' => 'aendern', 'slug' => $slug, 'id' => (int) $ist['id'], 'vorher' => ['gramm' => $ist['gramm'], 'source' => $ist['source']]] + $w;
                    $z->inc($wg, 'kind_neu');
                } else {
                    $z->inc($wg, 'kind_abweichend');
                }
            }
            foreach ($g['kinder']['textures'] as $slug => $w) {
                $tid = $ctx['texturen'][$slug];
                $ist = $id !== null ? ($io['textures'][$id][$tid] ?? null) : null;
                if ($ist === null) {
                    $plan['textures'][] = ['aktion' => 'neu', 'slug' => $slug, 'texture_id' => $tid] + $w;
                    $z->inc($wg, 'kind_neu');
                } elseif ($this->norm($ist['intensity']) === $this->norm($w['intensity']) && ($ist['source'] ?? null) === $w['source']) {
                    $z->inc($wg, 'kind_unveraendert');
                } elseif ($frei) {
                    $plan['textures'][] = ['aktion' => 'aendern', 'slug' => $slug, 'texture_id' => $tid, 'id' => (int) $ist['id'], 'vorher' => ['intensity' => $ist['intensity'], 'source' => $ist['source']]] + $w;
                    $z->inc($wg, 'kind_neu');
                } else {
                    $z->inc($wg, 'kind_abweichend');
                }
            }
            foreach ($g['kinder']['einheiten'] as $slug => $w) {
                $uid = $ctx['einheiten'][$slug];
                $ist = $id !== null ? ($io['einheiten'][$id][$uid] ?? null) : null;
                if ($ist === null) {
                    $plan['einheiten'][] = ['aktion' => 'neu', 'slug' => $slug, 'unit_id' => $uid] + $w;
                    $z->inc($wg, 'kind_neu');
                } elseif ($this->norm($ist['default_g']) === $this->norm($w['default_g']) && (int) $ist['is_primary'] === $w['is_primary'] && (int) $ist['sort_order'] === $w['sort_order']) {
                    $z->inc($wg, 'kind_unveraendert');
                } elseif ($frei) {
                    $plan['einheiten'][] = ['aktion' => 'aendern', 'slug' => $slug, 'unit_id' => $uid, 'id' => (int) $ist['id'],
                        'vorher' => ['default_g' => $ist['default_g'], 'is_primary' => $ist['is_primary'], 'sort_order' => $ist['sort_order'], 'source' => $ist['source']]] + $w;
                    $z->inc($wg, 'kind_neu');
                } else {
                    $z->inc($wg, 'kind_abweichend');
                }
            }
            foreach ($g['kinder']['aequi'] as $altKey => $w) {
                $altId = isset($P[$altKey]) ? $P[$altKey]['id'] : ($ctx['bestand'][$altKey]['id'] ?? null);
                $ist = $id !== null && $altId !== null ? ($io['aequi'][$id][$altId] ?? null) : null;
                if ($ist === null) {
                    $plan['aequi'][] = ['aktion' => 'neu', 'alt_key' => $altKey] + $w;
                    $z->inc($wg, 'kind_neu');
                } elseif ($this->norm($ist['umrechnungsfaktor']) === $this->norm($w['faktor'])) {
                    $z->inc($wg, 'kind_unveraendert');
                } else {
                    $z->inc($wg, 'kind_abweichend');                              // Äquivalente: nur ergänzen, nie überschreiben
                }
            }
            $g['kind_plan'] = $plan;
        }
        unset($g);
    }

    private function kuerze(mixed $v, int $max): ?string
    {
        return $v === null || $v === '' ? null : mb_substr((string) $v, 0, $max);
    }

    // ── Verknüpfungen: Treffer über legacy_id, Reihenfolge, Guard-Simulation ──────────────────────

    /**
     * @param  array<string,array<string,mixed>>  $P
     */
    private function planeLinks(array &$P, array $paket, array $ctx, GpPaketZaehler $z, array &$listen, bool $umhaengen): void
    {
        // Artikel je GP, in fester Reihenfolge: Lead zuerst, dann nach legacy_id (Plan §0.7 — reproduzierbarer Guard)
        $je = [];
        $legacyZahl = [];
        foreach ($paket['artikel'] as $a) {
            $k = (string) ($a['gp_key'] ?? '');
            $legacy = $a['necta_legacy_id'] ?? null;
            if (! is_numeric($legacy)) {
                $z->inc($P[$k]['wg'] ?? '--', 'v_ohne_treffer');
                $listen['review_ohne_match'][] = ['wg' => $P[$k]['wg'] ?? '--', 'gp_key' => $k, 'gp_name' => $P[$k]['name'] ?? '', 'legacy_id' => (string) $legacy, 'grund' => 'necta_legacy_id fehlt oder ist keine Zahl', 'lead' => ''];

                continue;
            }
            $legacyZahl[(int) $legacy] = ($legacyZahl[(int) $legacy] ?? 0) + 1;
            if (isset($P[$k])) {
                $je[$k][] = $a;
            }
        }
        foreach ($je as &$liste) {
            usort($liste, fn ($x, $y) => [(int) !($x['lead'] ?? false), (int) $x['necta_legacy_id']] <=> [(int) !($y['lead'] ?? false), (int) $y['necta_legacy_id']]);
        }
        unset($liste);

        $legacies = [];
        foreach ($je as $liste) {
            foreach ($liste as $a) {
                $legacies[(int) $a['necta_legacy_id']] = true;
            }
        }
        $items = [];
        foreach (array_chunk(array_keys($legacies), 1000) as $chunk) {
            foreach (DB::table('foodalchemist_supplier_items')->whereIn('legacy_id', $chunk)->get(['id', 'legacy_id', 'team_id', 'deleted_at', 'designation', 'supplier_id']) as $r) {
                $items[(int) $r->legacy_id][] = $r;
            }
        }
        $itemIds = [];
        foreach ($items as $rows) {
            foreach ($rows as $r) {
                $itemIds[] = (int) $r->id;
            }
        }
        $structs = [];
        foreach (array_chunk($itemIds, 1000) as $chunk) {
            foreach (DB::table('foodalchemist_supplier_item_structures')->whereIn('supplier_item_id', $chunk)->get() as $r) {
                $structs[(int) $r->supplier_item_id] = (array) $r;
            }
        }
        // bestehende Verknüpfungen der bestehenden GPs (Ausgangsmenge für den Guard)
        $sets = [];
        $leadAlt = [];
        $ids = array_values(array_filter(array_map(fn ($g) => $g['id'], $P)));
        foreach (array_chunk($ids, 1000) as $chunk) {
            foreach (DB::table('foodalchemist_supplier_item_structures')->whereIn('gp_id', $chunk)->whereNull('deleted_at')->get(['gp_id', 'supplier_item_id']) as $r) {
                $sets[(int) $r->gp_id][] = (int) $r->supplier_item_id;
            }
        }
        $prof = $this->profile(array_merge($itemIds, array_merge(...array_values($sets ?: [[]]))));
        $lieferanten = DB::table('foodalchemist_suppliers')->pluck('name', 'id')->all();

        foreach ($P as $key => &$g) {
            $wg = $g['wg'];
            $set = $g['id'] !== null ? array_values(array_unique($sets[$g['id']] ?? [])) : [];
            $g['lead_alt'] = $g['bestand'] !== null && $g['bestand']['lead_la_supplier_item_id'] !== null ? (int) $g['bestand']['lead_la_supplier_item_id'] : null;
            $g['paket_lead'] = null;
            $g['links'] = [];
            foreach ($je[$key] ?? [] as $a) {
                $legacy = (int) $a['necta_legacy_id'];
                $l = ['legacy' => $legacy, 'status' => '', 'item_id' => null, 'struct' => null, 'gp_alt' => null, 'grund' => '', 'lead' => (bool) ($a['lead'] ?? false),
                    'struktur' => is_array($a['struktur'] ?? null) ? $a['struktur'] : [], 'abw' => []];
                $rows = $items[$legacy] ?? [];
                $aktiv = array_values(array_filter($rows, fn ($r) => $r->deleted_at === null && $r->team_id === null));
                $listeOhne = function (string $grund) use (&$l, &$listen, $z, $wg, $key, $g, $legacy) {
                    $l['status'] = 'ohne_match';
                    $l['grund'] = $grund;
                    $z->inc($wg, 'v_ohne_treffer');
                    $listen['review_ohne_match'][] = ['wg' => $wg, 'gp_key' => $key, 'gp_name' => $g['name'], 'legacy_id' => $legacy, 'grund' => $grund, 'lead' => $l['lead'] ? 1 : 0];
                };
                if (($legacyZahl[$legacy] ?? 0) > 1) {
                    $l['status'] = 'mehrdeutig';
                    $l['grund'] = 'Artikel mehrfach im Paket';
                    $z->inc($wg, 'v_mehrdeutig');
                    $listen['review_ohne_match'][] = ['wg' => $wg, 'gp_key' => $key, 'gp_name' => $g['name'], 'legacy_id' => $legacy, 'grund' => 'mehrdeutig: ' . $l['grund'], 'lead' => $l['lead'] ? 1 : 0];
                } elseif ($rows === []) {
                    $listeOhne('Artikel (legacy_id) gibt es auf io nicht');
                } elseif ($aktiv === []) {
                    $listeOhne(array_filter($rows, fn ($r) => $r->deleted_at === null) === [] ? 'Artikel auf io soft-gelöscht' : 'Artikel gehört einem Team (nicht global)');
                } elseif (count($aktiv) > 1) {
                    $l['status'] = 'mehrdeutig';
                    $l['grund'] = 'legacy_id auf io mehrfach (global)';
                    $z->inc($wg, 'v_mehrdeutig');
                    $listen['review_ohne_match'][] = ['wg' => $wg, 'gp_key' => $key, 'gp_name' => $g['name'], 'legacy_id' => $legacy, 'grund' => 'mehrdeutig: ' . $l['grund'], 'lead' => $l['lead'] ? 1 : 0];
                } else {
                    $item = $aktiv[0];
                    $iid = (int) $item->id;
                    $l['item_id'] = $iid;
                    $st = $structs[$iid] ?? null;
                    $l['struct'] = $st;
                    $anderes = $st !== null && $st['deleted_at'] === null && $st['gp_id'] !== null && ($g['id'] === null || (int) $st['gp_id'] !== $g['id']);
                    if ($st !== null && $st['deleted_at'] === null && $st['gp_id'] !== null && $g['id'] !== null && (int) $st['gp_id'] === $g['id']) {
                        $l['status'] = 'bestehend';
                        $z->inc($wg, 'v_bestehend');
                        if (! in_array($iid, $set, true)) {
                            $set[] = $iid;
                        }
                    } elseif ($anderes) {
                        $l['gp_alt'] = (int) $st['gp_id'];
                        $z->inc($wg, 'v_umhaengen_noetig');
                        if (! $umhaengen) {
                            $l['status'] = 'uebersprungen';
                            $l['grund'] = 'hängt an GP #' . $st['gp_id'] . ' (ohne --umhaengen übersprungen)';
                            $z->inc($wg, 'v_uebersprungen');
                        } else {
                            $this->simuliereGuard($l, $set, $prof, $g, $z, $listen, $item, $lieferanten, 'umhaengen');
                        }
                    } else {
                        $this->simuliereGuard($l, $set, $prof, $g, $z, $listen, $item, $lieferanten, 'neu');
                    }
                    if ($l['status'] === 'neu' || $l['status'] === 'umhaengen') {
                        $set[] = $iid;
                        if ($st === null) {
                            $z->inc($wg, 'struktur_neu');
                        }
                    }
                    if ($l['lead'] && $g['paket_lead'] === null && in_array($iid, $set, true)) {
                        $g['paket_lead'] = $iid;
                    }
                }
                $g['links'][] = $l;
            }
            $g['set'] = array_values(array_unique($set));
        }
        unset($g);
    }

    /** Spiegelt {@see LeadLaService::guardDeklarationsprofil()}: neuer Artikel gegen alle schon verknüpften. */
    private function simuliereGuard(array &$l, array $set, array $prof, array $g, GpPaketZaehler $z, array &$listen, object $item, array $lieferanten, string $erfolg): void
    {
        $neu = $prof[(int) $item->id] ?? [null, null];
        foreach ($set as $altId) {
            $alt = $prof[$altId] ?? [null, null];
            $abw = DeklarationsProfil::abweichungen($alt[0], $alt[1], $neu[0], $neu[1]);
            if ($abw !== []) {
                $l['status'] = 'guard_abgelehnt';
                $l['abw'] = $abw;
                $l['grund'] = implode('; ', $abw);
                $z->inc($g['wg'], 'v_guard_abgelehnt');
                $listen['guard_abgelehnt'][] = $this->guardZeile($g, $l, $item, $lieferanten, $altId);

                return;
            }
        }
        $l['status'] = $erfolg;
        $z->inc($g['wg'], $erfolg === 'neu' ? 'v_neu' : 'v_umgehaengt');
    }

    /** @return array<string,mixed> */
    private function guardZeile(array $g, array $l, object $item, array $lieferanten, int $gegenItem): array
    {
        return ['wg' => $g['wg'], 'gp_key' => $g['key'], 'gp_name' => $g['name'], 'legacy_id' => $l['legacy'], 'artikel_id' => (int) $item->id,
            'lieferant' => $lieferanten[$item->supplier_id] ?? '', 'artikel' => $item->designation, 'abweichend_von_artikel_id' => $gegenItem,
            'abweichungen' => implode('; ', $l['abw']),
            'vorschlag' => 'neues GP: „' . (trim((string) ($g['p']['main_ingredient_display'] ?? '')) ?: trim((string) strtok($g['name'], ':'))) . '“ + trennendes Merkmal (' . implode('; ', $l['abw']) . ') — nur Text, nichts angelegt'];
    }

    /**
     * Allergen-/Zusatzstoff-Profile je Artikel (wie `->with(['allergens','declarations'])` im Guard).
     *
     * @param  list<int>  $itemIds
     * @return array<int, array{0: ?FoodAlchemistItemAllergen, 1: ?FoodAlchemistItemDeclaration}>
     */
    private function profile(array $itemIds): array
    {
        $out = [];
        $itemIds = array_values(array_unique($itemIds));
        foreach (array_chunk($itemIds, 500) as $chunk) {
            $al = FoodAlchemistItemAllergen::query()->whereIn('supplier_item_id', $chunk)->get()->keyBy('supplier_item_id');
            $de = FoodAlchemistItemDeclaration::query()->whereIn('supplier_item_id', $chunk)->get()->keyBy('supplier_item_id');
            foreach ($chunk as $id) {
                $out[$id] = [$al->get($id), $de->get($id)];
            }
        }

        return $out;
    }

    // ── Anker ──────────────────────────────────────────────────────────────────────────────────────

    /**
     * Gleiche Regeln wie `foodalchemist.gp_anchors.IMPORT`: Slug muss auflösen, source ≤ GP_ANKER_SOURCE_MAX, höchstens CAP_GP je GP,
     * `ersetze_alle` je GP (die Paket-Zeilen ersetzen den Bestand).
     *
     * @param  array<string,array<string,mixed>>  $P
     * @return array<string,array<string,mixed>>
     */
    private function planeAnker(array &$P, array $paket, array $ctx, GpPaketZaehler $z, array &$listen, ?string $nurWg, array $alleKeys): array
    {
        $master = MasterTeam::id();
        $ketteIds = $master !== null && ($t = Team::find($master)) !== null ? TeamScope::ancestryIds($t) : [];
        $ankerIds = DB::table('foodalchemist_vocab_pairing_anchors')->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereNull('team_id')->orWhereIn('team_id', $ketteIds))->pluck('id', 'slug')->all();
        $je = [];
        foreach ($paket['anker'] as $r) {
            $k = (string) ($r['gp_key'] ?? '');
            $slug = trim((string) ($r['anchor_slug'] ?? ''));
            if ($nurWg !== null && isset($alleKeys[$k]) && $this->wgCode($alleKeys[$k]['commodity_group_code'] ?? null) !== $nurWg) {
                continue;
            }
            $wg = $P[$k]['wg'] ?? '--';
            $ab = function (string $grund) use ($z, &$listen, $wg, $k, $slug) {
                $z->inc($wg, 'anker_abgelehnt');
                $listen['anker_abgelehnt'][] = ['wg' => $wg, 'gp_key' => $k, 'anchor_slug' => $slug, 'grund' => $grund];
            };
            if (! isset($P[$k])) {
                $ab(isset($alleKeys[$k]) ? 'GP ist abgelehnt (siehe gp_abgelehnt.csv)' : 'GP nicht im Paket');

                continue;
            }
            if (! isset($ankerIds[$slug])) {
                $ab('Anker-Slug unbekannt/nicht sichtbar');

                continue;
            }
            $source = trim((string) ($r['source'] ?? ''));
            if ($source === '') {
                $ab('source ist Pflicht');

                continue;
            }
            if (mb_strlen($source) > PairingService::GP_ANKER_SOURCE_MAX) {
                $ab('source zu lang (max. ' . PairingService::GP_ANKER_SOURCE_MAX . ')');

                continue;
            }
            $role = in_array($r['role'] ?? 'kern', ['kern', 'neben'], true) ? ($r['role'] ?? 'kern') : 'kern';
            $conf = isset($r['ai_confidence']) && is_numeric($r['ai_confidence']) ? max(0.0, min(1.0, (float) $r['ai_confidence'])) : null;
            if (! isset($je[$k][$slug]) && count($je[$k] ?? []) >= PairingService::CAP_GP) {
                $ab('Limit erreicht: max ' . PairingService::CAP_GP . ' Anker pro GP');

                continue;
            }
            $je[$k][$slug] = ['slug' => $slug, 'anchor_id' => (int) $ankerIds[$slug], 'role' => $role, 'source' => $source, 'conf' => $conf, 'reasoning' => $r['ai_reasoning'] ?? null];
        }
        $ids = [];
        foreach ($je as $k => $_) {
            if ($P[$k]['id'] !== null) {
                $ids[] = $P[$k]['id'];
            }
        }
        $io = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach (DB::table('foodalchemist_gp_anchor_mappings')->whereIn('gp_id', $chunk)->whereNull('deleted_at')->get() as $r) {
                $io[$r->gp_id][(int) $r->anchor_id] = (array) $r;
            }
        }
        $out = [];
        foreach ($je as $k => $rows) {
            $wg = $P[$k]['wg'];
            $z->inc($wg, 'anker_gps');
            $ist = $P[$k]['id'] !== null ? ($io[$P[$k]['id']] ?? []) : [];
            $gleich = count($ist) === count($rows);
            foreach ($rows as $r) {
                $x = $ist[$r['anchor_id']] ?? null;
                if ($x === null || $x['role'] !== $r['role'] || $x['source'] !== $r['source'] || $this->norm($x['ai_confidence']) !== $this->norm($r['conf']) || ($x['ai_reasoning'] ?? null) !== $r['reasoning']) {
                    $gleich = false;
                }
            }
            $out[$k] = ['rows' => array_values($rows), 'aktion' => $gleich ? 'unveraendert' : 'schreiben'];
            $z->inc($wg, $gleich ? 'anker_unveraendert' : 'anker_neu', count($rows));
        }

        return $out;
    }

    // ── Ausführen (--apply) ────────────────────────────────────────────────────────────────────────

    /**
     * @param  array<string,mixed>  $plan
     * @return array<string,mixed> Protokoll
     */
    private function fuehreAus(array &$plan, array $paket, Team $master, array $o): array
    {
        $ctx = $plan['ctx'];
        $z = $plan['z'];
        $jetzt = now();
        $umhaengen = (bool) ($o['umhaengen'] ?? false);
        $vorlauf = $o['vorlauf_stand'] ?? $this->ladeVorlauf($o['vorlauf'] ?? null);
        $proto = ['lauf' => $jetzt->format('Ymd_His'), 'start' => $jetzt->toIso8601String(), 'master_team' => (int) $master->id, 'paket_manifest_sha256' => $paket['manifest_sha'],
            'nur_wg' => $o['nur_wg'] ?? null, 'gps_neu' => [], 'gps_geaendert' => [], 'struktur_neu' => [], 'struktur_reaktiviert' => [], 'struktur_team_null' => [],
            'verknuepfungen' => [], 'entknuepft_direkt' => [], 'n_las' => [], 'lead' => [], 'status_korrektur' => [], 'kinder_neu' => ['forms' => [], 'textures' => [], 'einheiten' => [], 'aequi' => []],
            'kinder_geaendert' => ['forms' => [], 'textures' => [], 'einheiten' => []], 'anker' => [], 'gp_stand' => $vorlauf, 'guard_real_abgelehnt' => 0, 'lead_ist' => []];
        $leadGeaendert = [];

        DB::transaction(function () use (&$plan, $ctx, $z, $master, $jetzt, $umhaengen, $paket, &$proto, &$leadGeaendert, $o) {
            $P = &$plan['gps'];
            $ts = $jetzt->toDateTimeString();
            $byId = [];
            foreach ($P as $k => $g0) {
                if ($g0['id'] !== null) {
                    $byId[$g0['id']] = $k;
                }
            }

            // 1) GPs anlegen: erst Nicht-Derivate, dann Derivate (Mutter vor Kind)
            foreach ([false, true] as $derivate) {
                foreach ($P as $key => &$g) {
                    if ($g['aktion'] !== 'neu' || $g['derivat'] !== $derivate) {
                        continue;
                    }
                    $row = $this->insertZeile($g, $ctx, $P, $ts);
                    $id = (int) DB::table('foodalchemist_gps')->insertGetId($row);
                    $g['id'] = $id;
                    $byId[$id] = $key;
                    $proto['gps_neu'][] = ['id' => $id, 'gp_key' => $key, 'name' => $g['name'], 'wg' => $g['wg']];
                }
                unset($g);
            }
            // 2) geänderte GPs (nur frei, nie Konflikte)
            foreach ($P as $key => &$g) {
                if ($g['aktion'] !== 'geaendert') {
                    continue;
                }
                $upd = [];
                foreach ($g['diff'] as $c => [$alt, $neu]) {
                    if ($c === 'derivat_von_gp_key') {
                        $upd['derivat_von_gp_id'] = $neu !== null ? ($P[$neu]['id'] ?? ($ctx['bestand'][$neu]['id'] ?? null)) : null;
                        $g['diff']['derivat_von_gp_id'] = [$g['bestand']['derivat_von_gp_id'] ?? null, $upd['derivat_von_gp_id']];
                    } else {
                        $upd[$c] = $neu;
                    }
                }
                $upd = array_intersect_key($upd, $ctx['gpSpalten']);
                $vorher = array_intersect_key($g['bestand'], $upd);
                DB::table('foodalchemist_gps')->where('id', $g['id'])->update($upd + ['updated_at' => $ts]);
                $proto['gps_geaendert'][] = ['id' => $g['id'], 'gp_key' => $key, 'vorher' => $vorher, 'nachher' => $upd];
            }
            unset($g);

            // 3) Kind-Tabellen
            foreach ($P as $key => &$g) {
                $this->schreibeKinder($g, $P, $ctx, $ts, $proto);
            }
            unset($g);

            // 4) Verknüpfen — NORMALER Weg (LeadLaService::verknuepfen, Allergen-Guard), Massen-Events unterdrückt
            $betroffen = [];
            $struktSpalten = array_flip(Schema::getColumnListing('foodalchemist_supplier_item_structures'));
            foreach ($P as $key => &$g) {
                $gpModel = null;
                $leadLaufAlt = $g['lead_alt'];
                foreach ($g['links'] as &$l) {
                    if (! in_array($l['status'], ['neu', 'umhaengen'], true)) {
                        continue;
                    }
                    $gpModel ??= FoodAlchemistGp::visibleToTeam($master)->findOrFail($g['id']);
                    $iid = (int) $l['item_id'];
                    $st = $l['struct'];
                    if ($l['status'] === 'umhaengen') {
                        $alt = (int) $l['gp_alt'];
                        DB::table('foodalchemist_supplier_item_structures')->where('id', $st['id'])->update(['gp_id' => null, 'updated_at' => $ts]);
                        $proto['entknuepft_direkt'][] = ['structure_id' => (int) $st['id'], 'supplier_item_id' => $iid, 'gp_alt' => $alt];
                        $betroffen[$alt] = true;
                        $altGp = DB::table('foodalchemist_gps')->where('id', $alt)->first();
                        if ($altGp !== null && (int) $altGp->lead_la_supplier_item_id === $iid) {
                            $m = FoodAlchemistGp::ohneTeamSicht('gp-paket-import: alter GP beim Umhängen')->find($alt);
                            $neuLead = $m !== null ? $this->lead->pickLeadLa($m, $master) : null;
                            DB::table('foodalchemist_gps')->where('id', $alt)->update(['lead_la_supplier_item_id' => $neuLead, 'updated_at' => $ts]);
                            $proto['lead'][] = ['gp_id' => $alt, 'alt' => $iid, 'neu' => $neuLead];
                        }
                    }
                    $structId = $st['id'] ?? null;
                    $teamVorher = $st['team_id'] ?? null;
                    if ($st === null) {
                        $structId = (int) DB::table('foodalchemist_supplier_item_structures')->insertGetId($this->strukturZeile($iid, $l['struktur'], $struktSpalten, $ts));
                        $proto['struktur_neu'][] = ['id' => $structId, 'supplier_item_id' => $iid];
                    } elseif ($st['deleted_at'] !== null) {
                        $proto['struktur_reaktiviert'][] = ['id' => (int) $st['id'], 'gp_alt' => $st['gp_id'], 'deleted_at' => $st['deleted_at']];
                    }
                    try {
                        Model::withoutEvents(fn () => $this->lead->verknuepfen($master, $gpModel, $iid));
                    } catch (\RuntimeException $e) {
                        $proto['guard_real_abgelehnt']++;
                        if ($st === null) {
                            // die Struktur-Zeile bleibt (ohne GP) — Struktur-Felder aus dem Paket schaden nicht; Rückweg entfernt sie
                        }
                        $l['status'] = str_starts_with($e->getMessage(), 'Allergene oder Zusatzstoffe') ? 'guard_abgelehnt' : 'fehler';
                        $l['grund'] = $e->getMessage();

                        continue;
                    }
                    $gpModel->refresh();
                    if ($teamVorher === null) {                                    // verknuepfen() schreibt team_id ??= Master — globale Zeilen bleiben global
                        DB::table('foodalchemist_supplier_item_structures')->where('id', $structId)->whereNotNull('team_id')->update(['team_id' => null]);
                        $proto['struktur_team_null'][] = (int) $structId;
                    }
                    $proto['verknuepfungen'][] = ['structure_id' => (int) $structId, 'supplier_item_id' => $iid, 'gp_alt' => $l['status'] === 'umhaengen' ? (int) $l['gp_alt'] : null, 'gp_neu' => (int) $g['id']];
                }
                unset($l);
                $betroffen[(int) $g['id']] = true;
                $g['lead_laufstart'] = $leadLaufAlt;
            }
            unset($g);

            // 5) n_las_total, Lead (§4.3), Status — gesammelt am Ende
            $leadIst = [];
            $plan['listen']['lead_park'] = [];                                  // Schätzung des Plans durch den Ist-Stand ersetzen
            foreach (array_keys($betroffen) as $gpId) {
                $gpRow = DB::table('foodalchemist_gps')->where('id', $gpId)->first();
                if ($gpRow === null) {
                    continue;
                }
                $linked = DB::table('foodalchemist_supplier_item_structures')->where('gp_id', $gpId)->whereNull('deleted_at')->pluck('supplier_item_id')->map(fn ($v) => (int) $v)->all();
                $nAlt = (int) $gpRow->n_las_total;
                $proto['n_las'][] = ['gp_id' => $gpId, 'alt' => isset($byId[$gpId]) ? ($P[$byId[$gpId]]['aktion'] === 'neu' ? 0 : (int) ($P[$byId[$gpId]]['bestand']['n_las_total'] ?? 0)) : $nAlt, 'neu' => count($linked)];
                $upd = [];
                if ($nAlt !== count($linked)) {
                    $upd['n_las_total'] = count($linked);
                }
                $entry = isset($byId[$gpId]) ? $P[$byId[$gpId]] : null;
                if ($entry !== null) {
                    [$neuLead, $art] = $this->entscheideLead($entry, $linked, $master, $gpId);
                    $leadAltVorLauf = $entry['lead_alt'];
                    $aktuell = $gpRow->lead_la_supplier_item_id !== null ? (int) $gpRow->lead_la_supplier_item_id : null;
                    if ($art !== null) {
                        $leadIst[$entry['wg']][$art] = ($leadIst[$entry['wg']][$art] ?? 0) + 1;
                        if ($art === 'lead_park') {
                            $plan['listen']['lead_park'][] = ['wg' => $entry['wg'], 'gp_key' => $entry['key'], 'name' => $entry['name'], 'artikel' => count($linked)];
                        }
                    }
                    if ($neuLead !== $aktuell) {
                        $upd['lead_la_supplier_item_id'] = $neuLead;
                    }
                    if ($neuLead !== $leadAltVorLauf) {
                        $proto['lead'][] = ['gp_id' => $gpId, 'alt' => $leadAltVorLauf, 'neu' => $neuLead];
                        if ($entry['aktion'] !== 'neu') {
                            $leadGeaendert[] = $gpId;
                        }
                    }
                    // Status: neue GPs, die per Regel approved sein sollten, aber real keinen Artikel haben → tentative
                    if ($entry['aktion'] === 'neu' && $entry['status_soll'] === 'approved' && $gpRow->status === 'approved' && $linked === []
                        && (bool) ($entry['wert']['requires_la'] ?? true) && ! ($entry['wert']['is_derivat'] ?? false) && ! ($entry['wert']['is_platzhalter'] ?? false)) {
                        $upd['status'] = 'tentative';
                        $proto['status_korrektur'][] = ['gp_id' => $gpId, 'alt' => 'approved', 'neu' => 'tentative'];
                    }
                }
                if ($upd !== []) {
                    DB::table('foodalchemist_gps')->where('id', $gpId)->update($upd + ['updated_at' => $ts]);
                }
            }
            $proto['lead_ist'] = $leadIst;

            // 6) Anker (über PairingService wie gp_anchors.IMPORT)
            foreach ($plan['ankerGp'] as $key => $a) {
                $gpId = $P[$key]['id'] ?? null;
                if ($gpId === null || $a['aktion'] === 'unveraendert') {
                    continue;
                }
                $vorher = DB::table('foodalchemist_gp_anchor_mappings')->where('gp_id', $gpId)->get()->map(fn ($r) => (array) $r)->all();
                $this->pairing->clearGpAnker($master, $gpId);
                $abgelehnt = 0;
                foreach ($a['rows'] as $r) {
                    try {
                        $this->pairing->setGpAnker($master, $gpId, $r['anchor_id'], $r['role'], $r['source'], $r['conf'], $r['reasoning']);
                    } catch (\RuntimeException $e) {
                        $abgelehnt++;
                        $plan['listen']['anker_abgelehnt'][] = ['wg' => $P[$key]['wg'], 'gp_key' => $key, 'anchor_slug' => $r['slug'], 'grund' => $e->getMessage()];
                    }
                }
                $proto['anker'][] = ['gp_id' => $gpId, 'gp_key' => $key, 'vorher' => $vorher];
            }

            // 7) Stand-Hashes (Hand-Änderungen beim nächsten Lauf erkennen)
            $ids = [];
            foreach ($P as $key => $g) {
                if (in_array($g['aktion'], ['neu', 'geaendert', 'unveraendert'], true) && $g['id'] !== null) {
                    $ids[$g['id']] = $key;
                }
            }
            foreach (array_chunk(array_keys($ids), 500, true) as $chunk) {
                foreach (DB::table('foodalchemist_gps')->whereIn('id', $chunk)->get() as $r) {
                    $proto['gp_stand'][$ids[(int) $r->id]] = $this->hashZeile((array) $r, $ctx);
                }
            }
        });

        // Lead-Zähler: ab jetzt zählt der Ist-Stand statt der Schätzung
        foreach (['lead_unveraendert', 'lead_uebernommen', 'lead_neu', 'lead_park'] as $k) {
            foreach (array_keys($z->wg) as $wg) {
                $z->setze($wg, $k, (int) ($proto['lead_ist'][$wg][$k] ?? 0));
            }
        }
        // Guard-Zähler: real abgelehnte Verknüpfungen (sollen mit der Simulation übereinstimmen)
        $simAbgelehnt = $z->summe('v_guard_abgelehnt');
        if ($proto['guard_real_abgelehnt'] !== 0 || $simAbgelehnt !== 0) {
            $proto['guard_sim_abgelehnt'] = $simAbgelehnt;
            if ($proto['guard_real_abgelehnt'] !== $simAbgelehnt) {
                $plan['hinweise'][] = "ACHTUNG: Allergen-Guard real {$proto['guard_real_abgelehnt']} abgelehnt, Simulation sagte {$simAbgelehnt} — Berichte prüfen.";
            }
        }
        foreach ($plan['gps'] as $g) {
            foreach ($g['links'] as $l) {
                if ($l['status'] === 'guard_abgelehnt' && $l['abw'] === [] && $l['item_id'] !== null) {   // real abgelehnt, Simulation nicht
                    $plan['listen']['guard_abgelehnt'][] = ['wg' => $g['wg'], 'gp_key' => $g['key'], 'gp_name' => $g['name'], 'legacy_id' => $l['legacy'], 'artikel_id' => $l['item_id'],
                        'lieferant' => '', 'artikel' => '', 'abweichend_von_artikel_id' => '', 'abweichungen' => $l['grund'], 'vorschlag' => 'neues GP — nur Text'];
                } elseif ($l['status'] === 'fehler') {
                    $plan['listen']['review_ohne_match'][] = ['wg' => $g['wg'], 'gp_key' => $g['key'], 'gp_name' => $g['name'], 'legacy_id' => $l['legacy'], 'grund' => 'Fehler beim Verknüpfen: ' . $l['grund'], 'lead' => $l['lead'] ? 1 : 0];
                }
            }
        }

        // EIN gesammelter Recompute für geänderte Leads bestehender GPs (Kosten-Cache + Rezepte), nie je GP
        $proto['recompute'] = 0;
        $leadGeaendert = array_values(array_unique($leadGeaendert));
        if ($leadGeaendert !== [] && ! ($o['ohne_recompute'] ?? false)) {
            $kosten = app(KostenCacheService::class);
            $kosten->markiereVeraltet($kosten->rezepteMitGps($leadGeaendert), null);
            $proto['recompute'] = $this->lead->recomputeNutzerFuerGps($leadGeaendert);
        }
        $proto['ende'] = now()->toIso8601String();

        return $proto;
    }

    /** @param array<string,array<string,mixed>> $P */
    private function insertZeile(array $g, array $ctx, array $P, string $ts): array
    {
        $col = $ctx['gpSpalten'];
        $p = $g['p'];
        $row = [
            'team_id' => null, 'stamm_class' => StammPaket::PAKET, 'gp_key' => $g['key'],
            'uuid' => ($u = strtolower(trim((string) ($p['uuid'] ?? '')))) !== '' ? $u : (string) UuidV7::generate(),
            'status' => $g['status_soll'] ?? 'tentative', 'n_las_total' => 0, 'first_seen_at' => $ts, 'created_at' => $ts, 'updated_at' => $ts,
        ] + $g['wert'];
        if ($g['derivat']) {
            $m = trim((string) $p['derivat_von_gp_key']);
            $row['derivat_von_gp_id'] = $P[$m]['id'] ?? ($ctx['bestand'][$m]['id'] ?? null);
        }

        return array_intersect_key($row, $col);
    }

    /** @param array<string,mixed> $struktur */
    private function strukturZeile(int $itemId, array $struktur, array $spalten, string $ts): array
    {
        $row = ['uuid' => (string) UuidV7::generate(), 'team_id' => null, 'supplier_item_id' => $itemId, 'gp_id' => null, 'created_at' => $ts, 'updated_at' => $ts];
        foreach (self::STRUKTUR_SPALTEN as $c) {
            if (! array_key_exists($c, $struktur) || ! isset($spalten[$c])) {
                continue;
            }
            $v = $struktur[$c];
            $row[$c] = is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : ($v === '' ? null : $v);
        }
        $row['needs_review'] = (int) ($row['needs_review'] ?? 0);

        return $row;
    }

    /** @param array<string,mixed> $g @param array<string,mixed> $proto */
    private function schreibeKinder(array $g, array $P, array $ctx, string $ts, array &$proto): void
    {
        $id = $g['id'];
        foreach ($g['kind_plan']['forms'] ?? [] as $k) {
            if ($k['aktion'] === 'neu') {
                $alt = DB::table('foodalchemist_gp_forms')->where('gp_id', $id)->where('form_slug', $k['slug'])->first();
                if ($alt !== null) {                                              // weich gelöschte Zeile (Unique umfasst sie) beleben
                    DB::table('foodalchemist_gp_forms')->where('id', $alt->id)->update(['gramm' => $k['gramm'], 'source' => $k['source'] ?? 'manual', 'deleted_at' => null, 'updated_at' => $ts]);
                    $proto['kinder_geaendert']['forms'][] = ['id' => (int) $alt->id, 'vorher' => ['gramm' => $alt->gramm, 'source' => $alt->source, 'deleted_at' => $alt->deleted_at]];
                } else {
                    $nid = DB::table('foodalchemist_gp_forms')->insertGetId(['uuid' => (string) UuidV7::generate(), 'gp_id' => $id, 'form_slug' => $k['slug'], 'gramm' => $k['gramm'],
                        'source' => $k['source'] ?? 'manual', 'created_at' => $ts, 'updated_at' => $ts]);
                    $proto['kinder_neu']['forms'][] = (int) $nid;
                }
            } else {
                DB::table('foodalchemist_gp_forms')->where('id', $k['id'])->update(['gramm' => $k['gramm'], 'source' => $k['source'] ?? 'manual', 'updated_at' => $ts]);
                $proto['kinder_geaendert']['forms'][] = ['id' => $k['id'], 'vorher' => $k['vorher']];
            }
        }
        foreach ($g['kind_plan']['textures'] ?? [] as $k) {
            if ($k['aktion'] === 'neu') {
                $nid = DB::table('foodalchemist_gp_textures')->insertGetId(['gp_id' => $id, 'texture_vocab_id' => $k['texture_id'], 'intensity' => $k['intensity'], 'source' => $k['source'], 'created_at' => $ts, 'updated_at' => $ts]);
                $proto['kinder_neu']['textures'][] = (int) $nid;
            } else {
                DB::table('foodalchemist_gp_textures')->where('id', $k['id'])->update(['intensity' => $k['intensity'], 'source' => $k['source'], 'updated_at' => $ts]);
                $proto['kinder_geaendert']['textures'][] = ['id' => $k['id'], 'vorher' => $k['vorher']];
            }
        }
        foreach ($g['kind_plan']['einheiten'] ?? [] as $k) {
            if ($k['aktion'] === 'neu') {
                $alt = DB::table('foodalchemist_gp_count_unit_defaults')->where('gp_id', $id)->where('unit_vocab_id', $k['unit_id'])->first();
                if ($alt !== null) {
                    DB::table('foodalchemist_gp_count_unit_defaults')->where('id', $alt->id)->update(['default_g' => $k['default_g'], 'is_primary' => $k['is_primary'], 'sort_order' => $k['sort_order'], 'source' => $k['source'], 'deleted_at' => null, 'updated_at' => $ts]);
                    $proto['kinder_geaendert']['einheiten'][] = ['id' => (int) $alt->id, 'vorher' => ['default_g' => $alt->default_g, 'is_primary' => $alt->is_primary, 'sort_order' => $alt->sort_order, 'source' => $alt->source, 'deleted_at' => $alt->deleted_at]];
                } else {
                    $nid = DB::table('foodalchemist_gp_count_unit_defaults')->insertGetId(['uuid' => (string) UuidV7::generate(), 'team_id' => null, 'gp_id' => $id, 'unit_vocab_id' => $k['unit_id'],
                        'default_g' => $k['default_g'], 'is_primary' => $k['is_primary'], 'sort_order' => $k['sort_order'], 'source' => $k['source'], 'created_at' => $ts, 'updated_at' => $ts]);
                    $proto['kinder_neu']['einheiten'][] = (int) $nid;
                }
            } else {
                DB::table('foodalchemist_gp_count_unit_defaults')->where('id', $k['id'])->update(['default_g' => $k['default_g'], 'is_primary' => $k['is_primary'], 'sort_order' => $k['sort_order'], 'source' => $k['source'], 'updated_at' => $ts]);
                $proto['kinder_geaendert']['einheiten'][] = ['id' => $k['id'], 'vorher' => $k['vorher']];
            }
        }
        foreach ($g['kind_plan']['aequi'] ?? [] as $k) {
            $altId = $P[$k['alt_key']]['id'] ?? ($ctx['bestand'][$k['alt_key']]['id'] ?? null);
            if ($altId === null) {
                continue;                                                         // Gegen-GP wurde nicht angelegt
            }
            $nid = DB::table('foodalchemist_component_equivalents')->insertGetId(['uuid' => (string) UuidV7::generate(), 'team_id' => null, 'source_kind' => 'gp', 'source_id' => $id,
                'alt_kind' => 'gp', 'alt_id' => $altId, 'umrechnungsfaktor' => $k['faktor'], 'standard_seite' => $k['seite'], 'match_confidence' => $k['conf'], 'notes' => $k['notes'],
                'created_at' => $ts, 'updated_at' => $ts]);
            $proto['kinder_neu']['aequi'][] = (int) $nid;
        }
    }

    /**
     * Lead nach §4.3 (Ist): bestehender gültiger Lead bleibt; sonst Paket-Lead mit Preis; sonst {@see LeadLaService::pickLeadLa()} —
     * aber nur, wenn der Gewählte einen Preis hat (Regel aus lead-la-repick); sonst NULL und „Park“. Lead aus Artikel ohne Preis wird nie gesetzt.
     *
     * @param  list<int>  $linked
     * @return array{0: ?int, 1: ?string} [Lead, Zähler-Art]
     */
    private function entscheideLead(array $g, array $linked, Team $master, int $gpId): array
    {
        $braucht = (bool) ($g['wert']['requires_la'] ?? true);
        if (! $braucht || ($g['wert']['is_derivat'] ?? false) || $linked === []) {
            return [null, null];
        }
        $alt = $g['lead_alt'];
        if ($alt !== null && in_array($alt, $linked, true) && $this->hatPreis($alt)) {
            return [$alt, 'lead_unveraendert'];
        }
        if ($g['paket_lead'] !== null && in_array($g['paket_lead'], $linked, true) && $this->hatPreis($g['paket_lead'])) {
            return [$g['paket_lead'], 'lead_uebernommen'];
        }
        $m = FoodAlchemistGp::ohneTeamSicht('gp-paket-import: Lead-Wahl')->find($gpId);
        $pick = $m !== null ? $this->lead->pickLeadLa($m, $master) : null;
        if ($pick !== null && $this->hatPreis($pick)) {
            return [$pick, 'lead_neu'];
        }
        // pickLeadLa rechnet ohne Team und sieht für globale Stamm-Artikel keine Preise → Rangliste mit Master-Preissicht, erster bepreister Artikel
        $bepreist = $m !== null ? $this->lead->rangliste($m, $master, null, true)->first(fn ($la) => $la->hat_aktiven_preis && $this->hatPreis((int) $la->id)) : null;
        if ($bepreist !== null) {
            return [(int) $bepreist->id, 'lead_neu'];
        }

        return [null, 'lead_park'];
    }

    // ── Rückweg ────────────────────────────────────────────────────────────────────────────────────

    /**
     * Ein Protokoll umkehren (umgekehrte Reihenfolge, alles in einer Transaktion). Die GPs des Laufs werden per SoftDelete entfernt — NUR wenn
     * danach nichts Neues an ihnen hängt (Rezept, Lager, Bestellung …); sonst Abbruch mit Liste, nichts wird geändert.
     *
     * @param  array<string,mixed>  $p
     * @return array{zurueck: int, uebersprungen: int}
     *
     * @throws \RuntimeException mit der Liste der neuen Referenzen
     */
    public function zuruecknehmen(array $p, ?User $user): array
    {
        $this->master($user, 'GP-Paket-Import zurücknehmen');
        $zurueck = 0;
        $skip = 0;
        DB::transaction(function () use ($p, &$zurueck, &$skip) {
            $ts = now()->toDateTimeString();
            // Anker
            foreach (array_reverse($p['anker'] ?? []) as $a) {
                $vorherIds = array_map(fn ($r) => (int) $r['id'], $a['vorher']);
                DB::table('foodalchemist_gp_anchor_mappings')->where('gp_id', $a['gp_id'])->whereNotIn('id', $vorherIds ?: [0])->delete();
                foreach ($a['vorher'] as $r) {
                    DB::table('foodalchemist_gp_anchor_mappings')->where('id', $r['id'])->update(array_intersect_key($r, array_flip(['role', 'source', 'ai_confidence', 'ai_reasoning', 'deleted_at', 'team_id'])));
                }
                $zurueck++;
            }
            // Leads (nur wenn noch der protokollierte Stand)
            foreach (array_reverse($p['lead'] ?? []) as $l) {
                $ist = DB::table('foodalchemist_gps')->where('id', $l['gp_id'])->first();
                if ($ist !== null && ($ist->lead_la_supplier_item_id === null ? null : (int) $ist->lead_la_supplier_item_id) === $l['neu']) {
                    DB::table('foodalchemist_gps')->where('id', $l['gp_id'])->update(['lead_la_supplier_item_id' => $l['alt'], 'updated_at' => $ts]);
                    $zurueck++;
                } else {
                    $skip++;
                }
            }
            // Verknüpfungen lösen / auf den alten GP zurück
            foreach (array_reverse($p['verknuepfungen'] ?? []) as $v) {
                $ist = DB::table('foodalchemist_supplier_item_structures')->where('id', $v['structure_id'])->first();
                if ($ist !== null && ($ist->gp_id === null ? null : (int) $ist->gp_id) === $v['gp_neu']) {
                    DB::table('foodalchemist_supplier_item_structures')->where('id', $v['structure_id'])->update(['gp_id' => $v['gp_alt'], 'updated_at' => $ts]);
                    $zurueck++;
                } else {
                    $skip++;
                }
            }
            foreach (array_reverse($p['entknuepft_direkt'] ?? []) as $e) {
                $ist = DB::table('foodalchemist_supplier_item_structures')->where('id', $e['structure_id'])->first();
                if ($ist !== null && $ist->gp_id === null) {
                    DB::table('foodalchemist_supplier_item_structures')->where('id', $e['structure_id'])->update(['gp_id' => $e['gp_alt'], 'updated_at' => $ts]);
                }
            }
            foreach ($p['struktur_reaktiviert'] ?? [] as $s) {
                DB::table('foodalchemist_supplier_item_structures')->where('id', $s['id'])->update(['deleted_at' => $s['deleted_at'], 'gp_id' => $s['gp_alt'], 'updated_at' => $ts]);
            }
            foreach ($p['struktur_neu'] ?? [] as $s) {
                $ist = DB::table('foodalchemist_supplier_item_structures')->where('id', $s['id'])->first();
                if ($ist !== null && $ist->gp_id === null) {
                    DB::table('foodalchemist_supplier_item_structures')->where('id', $s['id'])->delete();
                    $zurueck++;
                } else {
                    $skip++;
                }
            }
            foreach (array_reverse($p['n_las'] ?? []) as $n) {
                DB::table('foodalchemist_gps')->where('id', $n['gp_id'])->update(['n_las_total' => $n['alt']]);
            }
            foreach (array_reverse($p['status_korrektur'] ?? []) as $s) {
                DB::table('foodalchemist_gps')->where('id', $s['gp_id'])->update(['status' => $s['alt']]);
            }
            // Kind-Zeilen
            $tab = ['forms' => 'foodalchemist_gp_forms', 'textures' => 'foodalchemist_gp_textures', 'einheiten' => 'foodalchemist_gp_count_unit_defaults', 'aequi' => 'foodalchemist_component_equivalents'];
            foreach ($p['kinder_neu'] ?? [] as $art => $ids) {
                if ($ids !== []) {
                    DB::table($tab[$art])->whereIn('id', $ids)->delete();
                    $zurueck += count($ids);
                }
            }
            foreach ($p['kinder_geaendert'] ?? [] as $art => $zeilen) {
                foreach ($zeilen as $k) {
                    DB::table($tab[$art])->where('id', $k['id'])->update($k['vorher']);
                    $zurueck++;
                }
            }
            // geänderte GPs zurück (nur wenn noch der protokollierte Stand)
            foreach (array_reverse($p['gps_geaendert'] ?? []) as $g) {
                $ist = (array) DB::table('foodalchemist_gps')->where('id', $g['id'])->first();
                $gleich = true;
                foreach ($g['nachher'] as $c => $v) {
                    if ($this->norm($ist[$c] ?? null) !== $this->norm($v)) {
                        $gleich = false;
                    }
                }
                if ($gleich) {
                    DB::table('foodalchemist_gps')->where('id', $g['id'])->update($g['vorher'] + ['updated_at' => $ts]);
                    $zurueck++;
                } else {
                    $skip++;
                }
            }
            // neue GPs: nur ohne neue Referenz (Derivat-Kinder des Laufs zählen nicht — zuerst lösen)
            $neueIds = array_map(fn ($g) => (int) $g['id'], $p['gps_neu'] ?? []);
            if ($neueIds !== []) {
                DB::table('foodalchemist_gps')->whereIn('id', $neueIds)->update(['derivat_von_gp_id' => null]);
            }
            $offen = [];
            foreach ($p['gps_neu'] ?? [] as $g) {
                $z = $this->verweise->verweisZahlen((int) $g['id']);
                if ($z !== []) {
                    $offen[] = "{$g['gp_key']} (#{$g['id']}): " . implode(', ', array_map(fn ($t, $n) => "{$t}={$n}", array_keys($z), $z));
                }
            }
            if ($offen !== []) {
                throw new \RuntimeException("Rückweg abgebrochen, nichts geändert — an neuen GPs hängt inzwischen etwas:\n  " . implode("\n  ", $offen));
            }
            foreach ($p['gps_neu'] ?? [] as $g) {
                DB::table('foodalchemist_gps')->where('id', $g['id'])->whereNull('deleted_at')->update(['deleted_at' => $ts, 'updated_at' => $ts]);
                $zurueck++;
            }
        });

        return ['zurueck' => $zurueck, 'uebersprungen' => $skip];
    }
}
