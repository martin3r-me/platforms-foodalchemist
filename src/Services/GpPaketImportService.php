<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\Core\Models\User;
use Platform\FoodAlchemist\Exceptions\GpPaketAbgelehntException;
use Platform\FoodAlchemist\Services\GpPaket\GpPaketAnkerPhase;
use Platform\FoodAlchemist\Services\GpPaket\GpPaketKinder;
use Platform\FoodAlchemist\Services\GpPaket\GpPaketRueckweg;
use Platform\FoodAlchemist\Services\GpPaket\GpPaketValidator;
use Platform\FoodAlchemist\Services\GpPaket\GpPaketVerknuepfer;
use Platform\FoodAlchemist\Support\FaTeam;
use Platform\FoodAlchemist\Support\GpPaketZaehler;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Support\StammPaket;
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
 *
 * Schlanke Orchestrierung (Spec 85 §4): Prüfung/Kontext → {@see GpPaketValidator}, Kind-Tabellen → {@see GpPaketKinder},
 * Artikel/Lead → {@see GpPaketVerknuepfer}, Anker → {@see GpPaketAnkerPhase}, Rückweg → {@see GpPaketRueckweg}.
 */
class GpPaketImportService
{
    use PlattformAdminPruefung;

    /** Pflicht-/Daten-Dateien des Pakets (Skript 300). `gps.jsonl` und `gp_artikel.jsonl` müssen da sein. */
    public const DATEIEN = GpPaketValidator::DATEIEN;

    /** Whitelist der GP-Spalten im Paket (Plan §14, = GP_SPALTEN des Export-Skripts). */
    public const GP_SPALTEN = GpPaketValidator::GP_SPALTEN;

    /** Zusatzfelder je GP-Zeile (Schlüssel statt IDs). */
    public const GP_ZUSATZ = GpPaketValidator::GP_ZUSATZ;

    public const STRUKTUR_SPALTEN = GpPaketValidator::STRUKTUR_SPALTEN;

    public function __construct(
        private readonly GpPaketValidator $v,
        private readonly GpPaketKinder $kinder,
        private readonly GpPaketVerknuepfer $verknuepfer,
        private readonly GpPaketAnkerPhase $anker,
        private readonly GpPaketRueckweg $rueckweg,
        private readonly LeadLaService $lead,
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
        $this->verknuepfer->preisSicht()->setze($master);
        $paket = $this->v->liesPaket((string) $o['paket'], ! ($o['ohne_anker'] ?? false));
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
        $this->verknuepfer->preisSicht()->setze($this->master($o['user'] ?? null, 'GP-Paket prüfen'));
        $paket = $this->v->liesPaket((string) $o['paket'], ! ($o['ohne_anker'] ?? false));
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
                    if ($this->verknuepfer->preisSicht()->hatPreis($i)) {
                        $hatBepreisten = true;

                        break;
                    }
                }
                if ($hatBepreisten && ($lead === null || ! in_array($lead, $set, true) || ! $this->verknuepfer->preisSicht()->hatPreis($lead))) {
                    $abw[] = ['wg' => $wg, 'gp_key' => $key, 'art' => 'Lead fehlt oder löst nicht auf', 'detail' => 'Lead=' . ($lead ?? '∅')];
                }
                if (! $hatBepreisten && $lead !== null && $this->verknuepfer->preisSicht()->hatPreis($lead) === false && in_array($lead, $set, true)) {
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
        $nurWg = ($o['nur_wg'] ?? null) !== null && $o['nur_wg'] !== '' ? $this->v->wgCode($o['nur_wg']) : null;
        $umhaengen = (bool) ($o['umhaengen'] ?? false);
        $vorlauf = $o['vorlauf_stand'] ?? $this->ladeVorlauf($o['vorlauf'] ?? null);
        $z = new GpPaketZaehler;
        $listen = ['gp_abgelehnt' => [], 'konflikte' => [], 'review_ohne_match' => [], 'guard_abgelehnt' => [], 'lead_park' => [],
            'anker_abgelehnt' => [], 'kinder_abgelehnt' => [], 'tentative_mit_artikel' => [], 'ohne_artikel' => []];
        $hinweise = [];
        $ctx = $this->v->kontext();

        // ── 1. GP-Zeilen prüfen ──────────────────────────────────────────────────────────────────
        $alleKeys = [];
        foreach ($paket['gps'] as $p) {
            $alleKeys[trim((string) ($p['gp_key'] ?? ''))] = $p;
        }
        $P = [];
        $abg = [];
        foreach ($paket['gps'] as $p) {
            $key = trim((string) ($p['gp_key'] ?? ''));
            $wg = $this->v->wgCode($p['commodity_group_code'] ?? null);
            if ($nurWg !== null && $wg !== $nurWg) {
                continue;                                                // Teillauf: andere Warengruppen bleiben unberührt
            }
            $name = trim((string) ($p['name'] ?? ''));
            $wgAnzeige = $wg ?? '--';
            $z->inc($wgAnzeige, 'gp_paket');
            $g = ['key' => $key, 'name' => $name, 'wg' => $wgAnzeige, 'p' => $p, 'derivat' => (bool) ($p['is_derivat'] ?? false)];

            $grund = $this->v->pruefeGp($p, $key, $name, $wg, $ctx);
            if ($grund !== null) {
                $abg[$key] = $grund + ['wg' => $wgAnzeige, 'name' => $name];
                $P[$key] = $g;

                continue;
            }
            $g += $this->v->werte($p, $wg, $ctx);
            $P[$key] = $g;
        }

        // Dubletten im Paket (umlaut-normalisiert): alle Beteiligten raus — das ist der Zweck der Aktion
        $namen = [];
        foreach ($P as $key => $g) {
            if (! isset($abg[$key])) {
                $namen[$this->v->normName($g['name'])][] = $key;
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
                if ($this->v->norm($v) !== $this->v->norm($b[$c] ?? null)) {
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
            $g['frei'] = $stand !== null && $stand === $this->v->hashZeile($b, $ctx);
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
        $this->kinder->planeKinder($P, $paket, $ctx, $z, $listen);

        // ── 4./5. Artikel-Verknüpfung (Treffer, Guard-Simulation), Status (§13.4), Lead, n_las_total ──
        $this->verknuepfer->planeLinks($P, $paket, $ctx, $z, $listen, $umhaengen);
        $this->verknuepfer->planeStatusUndLead($P, $z, $listen);

        // ── 6. Anker ─────────────────────────────────────────────────────────────────────────────
        $ankerGp = [];
        if ($paket['anker'] !== null) {
            $ankerGp = $this->anker->planeAnker($P, $paket, $ctx, $z, $listen, $nurWg, $alleKeys);
        }

        return ['gps' => $P, 'z' => $z, 'listen' => $listen, 'hinweise' => array_merge($hinweise, $ctx['hinweise']), 'ctx' => $ctx, 'ankerGp' => $ankerGp, 'abg' => $abg];
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
                $this->kinder->schreibeKinder($g, $P, $ctx, $ts, $proto);
            }
            unset($g);

            // 4) Verknüpfen — NORMALER Weg (LeadLaService::verknuepfen, Allergen-Guard), Massen-Events unterdrückt
            $betroffen = $this->verknuepfer->verknuepfe($P, $master, $ts, $proto);

            // 5) n_las_total, Lead (§4.3), Status — gesammelt am Ende
            $this->verknuepfer->schliesse($P, $betroffen, $byId, $master, $ts, $proto, $plan['listen']['lead_park'], $leadGeaendert);

            // 6) Anker (über PairingService wie gp_anchors.IMPORT)
            $this->anker->schreibe($P, $plan['ankerGp'], $master, $proto, $plan['listen']['anker_abgelehnt']);

            // 6b) 27.10.2026: neue GPs ohne Paket-Anker/-Profil ziehen beides von einigen Geschwistern nach (kostenlos, ohne KI).
            //     Am GP hängend, nicht im Protokoll: der Rückweg entfernt die GPs selbst.
            $proto['geschwister'] = app(GpAromaService::class)->geschwisterNachziehen(array_column($proto['gps_neu'], 'id'));

            // 7) Stand-Hashes (Hand-Änderungen beim nächsten Lauf erkennen)
            $ids = [];
            foreach ($P as $key => $g) {
                if (in_array($g['aktion'], ['neu', 'geaendert', 'unveraendert'], true) && $g['id'] !== null) {
                    $ids[$g['id']] = $key;
                }
            }
            foreach (array_chunk(array_keys($ids), 500, true) as $chunk) {
                foreach (DB::table('foodalchemist_gps')->whereIn('id', $chunk)->get() as $r) {
                    $proto['gp_stand'][$ids[(int) $r->id]] = $this->v->hashZeile((array) $r, $ctx);
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

        return $this->rueckweg->zuruecknehmen($p);
    }
}
