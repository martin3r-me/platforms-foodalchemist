<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;

/**
 * Artikel↔GP-Verknüpfungen umhängen, neue GPs anlegen, Verknüpfungen lösen, Warengruppe setzen (Kurator/Dominique 19.10.2026).
 *
 * Führt einen GENEHMIGTEN Plan aus (strikte Plan-CSV; der Review-Entwurf {@see entwurfAusReview} ist nur ein Vorschlag zum Prüfen) und prüft
 * jede Zeile gegen den Live-Stand. Nur klare Ziele werden ausgeführt: Urteil + vorhandenes Ziel-GP ODER ein neues GP mit vollem §6-Namen.
 * Neue GPs entstehen über {@see GpNamingService::createGp} (Namensprüfung, Regeln, Dubletten-Guard, Key-Bildung) — nie per Roh-SQL.
 * Verknüpfungen (`supplier_item_structures.gp_id`) werden per Query-Builder umgehängt (keine Modell-Ereignisse); jeder Schritt
 * wird so protokolliert, dass {@see zuruecknehmen} ihn umkehren kann.
 *
 * Danach: n_las_total neu zählen und für jedes betroffene GP, dessen Lead-LA nicht mehr am GP hängt (oder fehlt), den Lead über die
 * bestehende Wahl ({@see LeadLaService::pickLeadLa}, §8: Vollständigkeit > Aktualität > Preis/Einheit) neu wählen.
 */
class GpUmhaengenService
{
    public const AKTIONEN = ['umhaengen', 'neues_gp', 'loesen', 'wg_setzen', 'gp_aufloesen'];

    public const PLAN_KOPF = ['aktion', 'supplier_item_id', 'gp_id_quelle', 'ziel_gp_id', 'neu_name', 'neu_wg', 'neu_sub', 'neu_status', 'notiz'];

    /** Status, auf denen ein Ziel-GP stehen darf. */
    private const ZIEL_STATUS = ['approved', 'tentative'];

    public function __construct(
        private readonly GpNamingService $naming,
        private readonly LeadLaService $lead,
        private readonly GpBereinigenService $csv,
    ) {}

    // ── Entwurf aus Review-CSV ─────────────────────────────────────────────────────────────────────

    /**
     * Aus Review-CSVs (`WG…_artikel.csv`) einen ENTWURF der Plan-Zeilen bilden. Nur Urteile falsch/andere_variante. Was sich nicht
     * eindeutig lesen lässt, kommt als `unaufgeloest` zurück und wird nie ausgeführt.
     *
     * @param  list<string>  $reviewCsvs
     * @return array{plan: list<array<string,string>>, unaufgeloest: list<array<string,string>>}
     */
    public function entwurfAusReview(array $reviewCsvs): array
    {
        $plan = [];
        $offen = [];
        foreach ($reviewCsvs as $datei) {
            foreach ($this->csv->leseCsv($datei) as $z) {
                if (! in_array((string) ($z['urteil'] ?? ''), ['falsch', 'andere_variante'], true)) {
                    continue;
                }
                $ziel = trim((string) ($z['ziel_gp'] ?? ''));
                $notiz = trim(((string) ($z['urteil'])) . ': ' . mb_substr((string) ($z['begruendung'] ?? ''), 0, 120) . ' [' . ($z['sicherheit'] ?? '') . ']');
                $basis = ['supplier_item_id' => (string) $z['supplier_item_id'], 'gp_id_quelle' => (string) $z['gp_id'], 'ziel_gp_id' => '', 'neu_name' => '',
                    'neu_wg' => '', 'neu_sub' => '', 'neu_status' => '', 'notiz' => $notiz];

                if (preg_match('/kein\s+food|verkn(?:u|ü)pfung\s+l(?:oe|ö)sen|leergut|pfand/iu', $ziel) === 1) {
                    $plan[] = ['aktion' => 'loesen'] + $basis;

                    continue;
                }
                if (stripos($ziel, 'NEUES GP') === 0) {
                    $name = trim(preg_replace('/^NEUES GP NOETIG:\s*/iu', '', $ziel));
                    $wgHinweis = preg_match('/\bWG\s*(\d{1,2})\b/iu', $name, $mw) === 1 ? str_pad($mw[1], 2, '0', STR_PAD_LEFT) : '';
                    $name = trim(preg_replace('/\s*\([^)]*(?:\bWG\s*\d|alt\.|Produkt )[^)]*\)/iu', '', $name));
                    if ($name === '') {
                        $offen[] = ['supplier_item_id' => $basis['supplier_item_id'], 'ziel_gp' => $ziel, 'grund' => 'Name des neuen GP leer'];

                        continue;
                    }
                    $plan[] = ['aktion' => 'neues_gp', 'neu_name' => $name, 'neu_wg' => $wgHinweis] + array_diff_key($basis, ['neu_wg' => 1]);

                    continue;
                }
                $id = null;
                if (preg_match('/\(\s*id\s*(\d+)\b/iu', $ziel, $m) === 1 || preg_match('/^(\d+)\b/u', $ziel, $m) === 1 || preg_match('/\((\d+)\)\s*$/u', $ziel, $m) === 1) {
                    $id = $m[1];
                }
                if ($id !== null) {
                    $plan[] = ['aktion' => 'umhaengen', 'ziel_gp_id' => $id] + $basis;

                    continue;
                }
                $offen[] = ['supplier_item_id' => $basis['supplier_item_id'], 'ziel_gp' => $ziel, 'grund' => $ziel === '' ? 'kein Ziel angegeben' : 'Ziel nicht eindeutig lesbar'];
            }
        }

        return ['plan' => $plan, 'unaufgeloest' => $offen];
    }

    // ── Plan prüfen ────────────────────────────────────────────────────────────────────────────────

    /**
     * Plan-Zeilen prüfen. Rückgabe: ausführbare Aktionen (mit aufgelösten Details) und Konflikte.
     *
     * @param  list<array<string,string>>  $zeilen  Plan-CSV (Spalten {@see PLAN_KOPF})
     * @return array{aktionen: list<array<string,mixed>>, neue_gps: array<string,array<string,mixed>>, konflikte: list<array<string,mixed>>}
     */
    public function plane(int $teamId, array $zeilen, ?string $wg = null): array
    {
        $team = Team::findOrFail($teamId);
        $aktionen = [];
        $neueGps = [];
        $konflikte = [];
        $gesehen = [];
        $aufgeloest = [];

        foreach ($zeilen as $nr => $z) {
            $aktion = trim((string) ($z['aktion'] ?? ''));
            $si = (int) ($z['supplier_item_id'] ?? 0);
            $quelleGp = (int) ($z['gp_id_quelle'] ?? 0);
            $fehler = function (string $grund) use (&$konflikte, $nr, $aktion, $si, $quelleGp) {
                $konflikte[] = ['zeile' => $nr + 2, 'aktion' => $aktion, 'supplier_item_id' => $si ?: null, 'gp_id' => $quelleGp ?: null, 'grund' => $grund];
            };
            if (! in_array($aktion, self::AKTIONEN, true)) {
                $fehler('aktion unbekannt (' . implode('|', self::AKTIONEN) . ')');

                continue;
            }

            if ($aktion === 'wg_setzen') {
                $gp = DB::table('foodalchemist_gps')->where('id', $quelleGp)->first();
                $neuWg = trim((string) ($z['neu_wg'] ?? ''));
                if ($gp === null || $gp->deleted_at !== null || (int) $gp->team_id !== $teamId) {
                    $fehler('GP existiert nicht oder gehört nicht dem Team');

                    continue;
                }
                if ($wg !== null && (string) $gp->commodity_group_code !== $wg) {
                    continue;
                }
                if (! DB::table('foodalchemist_lookup_commodity_groups')->where('code', $neuWg)->exists()) {
                    $fehler("Warengruppe »{$neuWg}« existiert nicht");

                    continue;
                }
                $sub = trim((string) ($z['neu_sub'] ?? ''));
                if ($sub !== '' && ! DB::table('foodalchemist_commodity_group_subcategories')->where('commodity_group_code', $neuWg)->where('name', $sub)->exists()) {
                    $fehler("Sub-Kategorie »{$sub}« gibt es in WG {$neuWg} nicht");

                    continue;
                }
                $aktionen[] = ['aktion' => 'wg_setzen', 'gp_id' => (int) $gp->id, 'wg_alt' => $gp->commodity_group_code, 'sub_alt' => $gp->sub_category, 'wg_neu' => $neuWg, 'sub_neu' => $sub !== '' ? $sub : null];

                continue;
            }

            if ($aktion === 'gp_aufloesen') {
                // GP auflösen: alles, was nach den übrigen Plan-Zeilen noch am GP hängt (Artikel, Rezept-Zutaten, Äquivalente …), geht auf das
                // Ziel-GP; das Quell-GP wird `merged` (wie ein Merge, voll protokolliert, umkehrbar).
                $quellGp = DB::table('foodalchemist_gps')->where('id', $quelleGp)->first();
                $zielId = (int) ($z['ziel_gp_id'] ?? 0);
                $ziel = DB::table('foodalchemist_gps')->where('id', $zielId)->first();
                if ($quellGp === null || $quellGp->deleted_at !== null || (int) $quellGp->team_id !== $teamId || ! in_array($quellGp->status, self::ZIEL_STATUS, true)) {
                    $fehler('Quell-GP existiert nicht, gehört nicht dem Team oder ist nicht aktiv');

                    continue;
                }
                if ($ziel === null || $ziel->deleted_at !== null || (int) $ziel->team_id !== $teamId || ! in_array($ziel->status, self::ZIEL_STATUS, true) || $zielId === $quelleGp) {
                    $fehler("Ziel-GP {$zielId} fehlt, ist nicht aktiv oder gleich dem Quell-GP");

                    continue;
                }
                if ($wg !== null && (string) $quellGp->commodity_group_code !== $wg) {
                    continue;
                }
                if (isset($aufgeloest[$quelleGp])) {
                    $fehler('GP steht mehrfach als Quelle im Plan');

                    continue;
                }
                $aufgeloest[$quelleGp] = $zielId;
                $aktionen[] = ['aktion' => 'gp_aufloesen', 'gp_id' => $quelleGp, 'ziel_gp_id' => $zielId];

                continue;
            }

            $st = DB::table('foodalchemist_supplier_item_structures')->where('supplier_item_id', $si)->whereNull('deleted_at')->first();
            if ($st === null) {
                $fehler('Artikel hat keine Verknüpfungszeile');

                continue;
            }
            if (isset($gesehen[$si])) {
                $fehler('Artikel steht mehrfach im Plan');

                continue;
            }
            $gesehen[$si] = true;
            $aktuell = $st->gp_id !== null ? (int) $st->gp_id : null;
            if ($quelleGp > 0 && $aktuell !== $quelleGp) {
                $fehler("Artikel hängt inzwischen an GP " . ($aktuell ?? 'keinem') . ", Plan erwartete {$quelleGp}");

                continue;
            }
            $quelle = $aktuell !== null ? DB::table('foodalchemist_gps')->where('id', $aktuell)->first() : null;
            if ($wg !== null && ($quelle === null || (string) $quelle->commodity_group_code !== $wg)) {
                continue;
            }

            if ($aktion === 'loesen') {
                if ($aktuell === null) {
                    $fehler('Artikel hängt an keinem GP');

                    continue;
                }
                $aktionen[] = ['aktion' => 'loesen', 'structure_id' => (int) $st->id, 'supplier_item_id' => $si, 'gp_alt' => $aktuell, 'gp_neu' => null];

                continue;
            }

            if ($aktion === 'umhaengen') {
                $zielId = (int) ($z['ziel_gp_id'] ?? 0);
                $ziel = DB::table('foodalchemist_gps')->where('id', $zielId)->first();
                if ($ziel === null || $ziel->deleted_at !== null) {
                    $fehler("Ziel-GP {$zielId} existiert nicht");

                    continue;
                }
                if ((int) $ziel->team_id !== $teamId || ! in_array($ziel->status, self::ZIEL_STATUS, true)) {
                    $fehler("Ziel-GP {$zielId} gehört nicht dem Team oder hat Status {$ziel->status}");

                    continue;
                }
                if ($aktuell === $zielId) {
                    $fehler('Artikel hängt schon am Ziel-GP');

                    continue;
                }
                $aktionen[] = ['aktion' => 'umhaengen', 'structure_id' => (int) $st->id, 'supplier_item_id' => $si, 'gp_alt' => $aktuell, 'gp_neu' => $zielId];

                continue;
            }

            // neues_gp
            $name = trim((string) ($z['neu_name'] ?? ''));
            if ($name === '') {
                $fehler('neu_name fehlt');

                continue;
            }
            $felder = $this->naming->felderAusName($name);
            $neuWg = trim((string) ($z['neu_wg'] ?? '')) ?: (string) ($quelle->commodity_group_code ?? '');
            if ($felder['hauptzutat'] === '' || $neuWg === '') {
                $fehler('Hauptzutat oder Warengruppe nicht bestimmbar (neu_wg angeben)');

                continue;
            }
            $schluessel = mb_strtolower($name) . '|' . $neuWg;
            if (! isset($neueGps[$schluessel])) {
                $in = ['name' => $name, 'hauptzutat' => $felder['hauptzutat'], 'condition' => $felder['condition'], 'processing' => $felder['processing'],
                    'form' => $felder['form'], 'portion' => $felder['portion'], 'pflichtangabe' => $felder['pflichtangabe'], 'commodity_group_code' => $neuWg,
                    'sub_category' => trim((string) ($z['neu_sub'] ?? ''))];
                $pruefung = $this->naming->validateGpName($name, $in + ['warengruppe' => $neuWg]);
                if ($pruefung['errors'] !== []) {
                    $fehler('Name verletzt Regelwerk: ' . implode('; ', $pruefung['errors']));

                    continue;
                }
                $key = $this->naming->buildGpKey($this->naming->slugify($felder['hauptzutat']), $felder['processing'], $felder['form']);
                $guard = $this->naming->anlageGuard($team, $key, $name);
                if ($guard['blockiert']) {
                    $fehler('GP gibt es schon: #' . ($guard['vorhandenes_gp']->id ?? '?') . ' ' . ($guard['vorhandenes_gp']->name ?? '') . " ({$guard['grund']}) — Plan auf umhaengen ändern");

                    continue;
                }
                $status = trim((string) ($z['neu_status'] ?? '')) ?: 'approved';
                if (! in_array($status, ['approved', 'tentative'], true)) {
                    $fehler("neu_status »{$status}« ungültig (approved|tentative)");

                    continue;
                }
                $neueGps[$schluessel] = ['name' => $name, 'in' => $in, 'bio' => (bool) $felder['bio'], 'status' => $status, 'gp_key' => $key, 'artikel' => []];
            }
            $neueGps[$schluessel]['artikel'][] = $si;
            $aktionen[] = ['aktion' => 'neues_gp', 'structure_id' => (int) $st->id, 'supplier_item_id' => $si, 'gp_alt' => $aktuell, 'gp_neu' => null, 'neu_schluessel' => $schluessel];
        }

        return ['aktionen' => $aktionen, 'neue_gps' => $neueGps, 'konflikte' => $konflikte];
    }

    // ── Ausführen ──────────────────────────────────────────────────────────────────────────────────

    /**
     * Geprüften Plan ausführen (eine Transaktion). Rückgabe: betroffene GP-IDs (für Recompute) und Zahlen.
     *
     * @param  array{aktionen: list<array<string,mixed>>, neue_gps: array<string,array<string,mixed>>}  $plan
     * @param  array<string,mixed>  $protokoll
     * @return array{betroffen: list<int>, lead_gewechselt: list<int>}
     */
    public function fuehreAus(int $teamId, array $plan, array &$protokoll): array
    {
        $team = Team::findOrFail($teamId);
        $betroffen = [];
        $leadGeaendert = [];

        DB::transaction(function () use ($team, $teamId, $plan, &$protokoll, &$betroffen, &$leadGeaendert) {
            // 1) neue GPs
            $neueIds = [];
            foreach ($plan['neue_gps'] as $schluessel => $spec) {
                $gp = $this->naming->createGp($team, $spec['in'] + ['name' => $spec['name']], false);
                $upd = ['status' => $spec['status'], 'updated_at' => now()];
                if ($spec['bio']) {
                    $upd['bio'] = 'bio';
                }
                DB::table('foodalchemist_gps')->where('id', $gp->id)->update($upd);
                $neueIds[$schluessel] = (int) $gp->id;
                $protokoll['neue_gps'][] = ['id' => (int) $gp->id, 'name' => $spec['name'], 'gp_key' => $gp->gp_key];
                $betroffen[(int) $gp->id] = true;
            }

            // 2) Verknüpfungen
            foreach ($plan['aktionen'] as $a) {
                if ($a['aktion'] === 'wg_setzen') {
                    DB::table('foodalchemist_gps')->where('id', $a['gp_id'])->update(['commodity_group_code' => $a['wg_neu'], 'sub_category' => $a['sub_neu'], 'updated_at' => now()]);
                    $protokoll['wg'][] = ['gp_id' => $a['gp_id'], 'wg_alt' => $a['wg_alt'], 'sub_alt' => $a['sub_alt'], 'wg_neu' => $a['wg_neu'], 'sub_neu' => $a['sub_neu']];

                    continue;
                }
                $ziel = match ($a['aktion']) {
                    'umhaengen' => $a['gp_neu'],
                    'neues_gp' => $neueIds[$a['neu_schluessel']] ?? null,
                    default => null,
                };
                DB::table('foodalchemist_supplier_item_structures')->where('id', $a['structure_id'])->update(['gp_id' => $ziel, 'updated_at' => now()]);
                $protokoll['verknuepfungen'][] = ['structure_id' => $a['structure_id'], 'supplier_item_id' => $a['supplier_item_id'], 'gp_alt' => $a['gp_alt'], 'gp_neu' => $ziel];
                foreach ([$a['gp_alt'], $ziel] as $g) {
                    if ($g !== null) {
                        $betroffen[(int) $g] = true;
                    }
                }
            }

            // 2b) GPs auflösen (nach allen Einzel-Umhängungen): Rest → Ziel-GP, Quelle `merged`
            foreach ($plan['aktionen'] as $a) {
                if ($a['aktion'] !== 'gp_aufloesen') {
                    continue;
                }
                $verlierer = DB::table('foodalchemist_gps')->where('id', $a['gp_id'])->first();
                $sieger = DB::table('foodalchemist_gps')->where('id', $a['ziel_gp_id'])->first();
                $protokoll['aufloesen'] ??= [];
                $this->csv->merge($sieger, $verlierer, $protokoll['aufloesen']);
                $betroffen[(int) $a['gp_id']] = true;
                $betroffen[(int) $a['ziel_gp_id']] = true;
            }

            // 3) Zähler + Lead
            foreach (array_keys($betroffen) as $gpId) {
                $gp = \Platform\FoodAlchemist\Models\FoodAlchemistGp::find($gpId);
                if ($gp === null) {
                    continue;
                }
                $n = DB::table('foodalchemist_supplier_item_structures')->where('gp_id', $gpId)->whereNull('deleted_at')->count();
                $protokoll['n_las'][] = ['gp_id' => $gpId, 'alt' => (int) $gp->n_las_total, 'neu' => $n];
                DB::table('foodalchemist_gps')->where('id', $gpId)->update(['n_las_total' => $n, 'updated_at' => now()]);

                $leadAlt = $gp->lead_la_supplier_item_id !== null ? (int) $gp->lead_la_supplier_item_id : null;
                $leadGueltig = $leadAlt !== null && DB::table('foodalchemist_supplier_item_structures')->where('supplier_item_id', $leadAlt)->where('gp_id', $gpId)->whereNull('deleted_at')->exists();
                if ($leadGueltig) {
                    continue;
                }
                if ($n === 0 || ! $gp->requires_la) {
                    $neuLead = null;
                } else {
                    $neuLead = $this->lead->pickLeadLa($gp->refresh(), $team);
                }
                if ($neuLead !== $leadAlt) {
                    DB::table('foodalchemist_gps')->where('id', $gpId)->update(['lead_la_supplier_item_id' => $neuLead, 'updated_at' => now()]);
                    $protokoll['lead'][] = ['gp_id' => $gpId, 'alt' => $leadAlt, 'neu' => $neuLead];
                    $leadGeaendert[] = $gpId;
                }
            }
        });

        return ['betroffen' => array_map('intval', array_keys($betroffen)), 'lead_gewechselt' => $leadGeaendert];
    }

    /** Rezepte neu rechnen, die die betroffenen GPs nutzen (einmal, V-049). */
    public function rechneRezepte(array $gpIds): int
    {
        return $this->lead->recomputeNutzerFuerGps($gpIds);
    }

    // ── Zurücknehmen ───────────────────────────────────────────────────────────────────────────────

    /**
     * Ein Protokoll umkehren (umgekehrte Reihenfolge). Geändert wird nur, was noch genau dem protokollierten Stand entspricht.
     * Neue GPs werden nur entfernt, wenn nichts mehr an ihnen hängt.
     *
     * @param  array<string,mixed>  $p
     * @return array{zurueck: int, uebersprungen: int, gps_behalten: list<int>}
     */
    public function zuruecknehmen(array $p): array
    {
        $zurueck = 0;
        $skip = 0;
        $behalten = [];
        DB::transaction(function () use ($p, &$zurueck, &$skip, &$behalten) {
            if (! empty($p['aufloesen'])) {
                $r = $this->csv->zuruecknehmen($p['aufloesen']);
                $zurueck += $r['zurueck'];
                $skip += $r['uebersprungen'];
            }
            foreach (array_reverse($p['lead'] ?? []) as $l) {
                $ist = DB::table('foodalchemist_gps')->where('id', $l['gp_id'])->first();
                if ($ist !== null && ($ist->lead_la_supplier_item_id === null ? null : (int) $ist->lead_la_supplier_item_id) === $l['neu']) {
                    DB::table('foodalchemist_gps')->where('id', $l['gp_id'])->update(['lead_la_supplier_item_id' => $l['alt'], 'updated_at' => now()]);
                    $zurueck++;
                } else {
                    $skip++;
                }
            }
            foreach (array_reverse($p['verknuepfungen'] ?? []) as $v) {
                $ist = DB::table('foodalchemist_supplier_item_structures')->where('id', $v['structure_id'])->first();
                if ($ist !== null && ($ist->gp_id === null ? null : (int) $ist->gp_id) === $v['gp_neu']) {
                    DB::table('foodalchemist_supplier_item_structures')->where('id', $v['structure_id'])->update(['gp_id' => $v['gp_alt'], 'updated_at' => now()]);
                    $zurueck++;
                } else {
                    $skip++;
                }
            }
            foreach (array_reverse($p['wg'] ?? []) as $w) {
                $ist = DB::table('foodalchemist_gps')->where('id', $w['gp_id'])->first();
                if ($ist !== null && (string) $ist->commodity_group_code === (string) $w['wg_neu']) {
                    DB::table('foodalchemist_gps')->where('id', $w['gp_id'])->update(['commodity_group_code' => $w['wg_alt'], 'sub_category' => $w['sub_alt'], 'updated_at' => now()]);
                    $zurueck++;
                } else {
                    $skip++;
                }
            }
            foreach (array_reverse($p['n_las'] ?? []) as $n) {
                DB::table('foodalchemist_gps')->where('id', $n['gp_id'])->update(['n_las_total' => $n['alt']]);
            }
            foreach (array_reverse($p['neue_gps'] ?? []) as $g) {
                if ($this->csv->verweisZahlen((int) $g['id']) === []) {
                    DB::table('foodalchemist_gps')->where('id', $g['id'])->delete();
                    $zurueck++;
                } else {
                    $behalten[] = (int) $g['id'];
                    $skip++;
                }
            }
        });

        return ['zurueck' => $zurueck, 'uebersprungen' => $skip, 'gps_behalten' => $behalten];
    }
}
