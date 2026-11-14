<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Support\GpLaufFelder;

/**
 * GP-Lauf: Strukturfelder vieler GPs nach Plan setzen und den Namen daraus neu bilden (Kurator 14.11.2026, Regelwerk v3.10.0).
 *
 * Plan = JSONL, je Zeile `{"gp_id":N,"name_alt":"…","felder":{…},"notiz":"…"}` (erlaubte Felder: {@see GpLaufFelder::FELDER}). Je Zeile wird gegen den
 * LIVE-Stand geprüft (GP existiert und ist aktiv, gehört zum Bereich, Name seit dem Plan unverändert, Werte zulässig), die Felder werden in die
 * vorhandenen gemischt (nicht genannte bleiben) und der Name wird GENAU wie {@see GpNamingService::updateGp} gerendert und geprüft
 * ({@see GpNamingService::updateVorschau}, eine Stelle). Regelwerk-Fehler, Namens-Kollision (Merge-Kandidat) und Key-Kollision sind Konflikte und
 * werden nicht geschrieben. Kein KI-Aufruf.
 *
 * Schreiben läuft über updateGp selbst (ohne Modell-Ereignisse: keine Embedding-/Activity-Stürme bei Tausenden GPs); gp_key/main_ingredient_slug
 * ziehen mit, wenn sie vorher aus Name bzw. Feldern ableitbar waren. Jede Änderung wird mit Alt-/Neu-Werten protokolliert und ist per
 * {@see zuruecknehmen} umkehrbar.
 */
class GpFelderPlanService
{
    private const AKTIV = ['approved', 'tentative'];

    /** Spalten, die ein Lauf ändern kann (Protokoll + Rückweg). */
    private const SPALTEN = [
        'name', 'gp_key', 'main_ingredient_slug', 'main_ingredient_display', 'condition', 'condition_source', 'processing', 'form', 'flavor', 'zusatz',
        'pflichtangabe', 'portion', 'bio', 'namens_vegan', 'namens_glutenfrei', 'namens_laktosefrei', 'namens_msc', 'namens_asc', 'herkunft_land',
        'angabe_status', 'angabe_grund', 'commodity_group_code', 'sub_category', 'rezepturware', 'marke_offen', 'piece_default_g', 'is_derivat',
        'derivat_von_gp_id', 'requires_la', 'alkohol', 'alkohol_source',
    ];

    public function __construct(
        private readonly GpNamingService $naming,
        private readonly GpBereinigenService $bereich,
        private readonly GpLaufNachlauf $nachlauf,
    ) {}

    // ── Plan lesen ─────────────────────────────────────────────────────────────────────────────────

    /** @return array{zeilen: list<array<string,mixed>>, konflikte: list<array<string,mixed>>} */
    public function lesePlan(string $pfad): array
    {
        if (! is_readable($pfad)) {
            throw new \RuntimeException("Plan nicht lesbar: {$pfad}");
        }
        $zeilen = [];
        $konflikte = [];
        foreach (file($pfad, FILE_IGNORE_NEW_LINES) ?: [] as $i => $roh) {
            $roh = trim((string) preg_replace('/^\xEF\xBB\xBF/', '', $roh));
            if ($roh === '' || str_starts_with($roh, '#')) {
                continue;
            }
            $z = json_decode($roh, true);
            if (! is_array($z) || ! isset($z['gp_id']) || ! array_key_exists('name_alt', $z) || ! is_array($z['felder'] ?? null)) {
                $konflikte[] = ['zeile' => $i + 1, 'gp_id' => is_array($z) ? ($z['gp_id'] ?? null) : null, 'name_alt' => is_array($z) ? ($z['name_alt'] ?? null) : null,
                    'grund' => 'Zeile kein gültiges JSON {gp_id, name_alt, felder{…}}'];

                continue;
            }
            $zeilen[] = ['zeile' => $i + 1] + $z;
        }

        return ['zeilen' => $zeilen, 'konflikte' => $konflikte];
    }

    // ── Plan prüfen ────────────────────────────────────────────────────────────────────────────────

    /**
     * @param  ?int  $bereichTeam  team-eigene GPs dieses Teams; null = globale Stamm-GPs (nur Master, prüft der Befehl)
     * @param  list<array<string,mixed>>  $zeilen
     * @return array{aenderungen: list<array<string,mixed>>, konflikte: list<array<string,mixed>>, bericht: list<list<mixed>>, ohne_aenderung: int}
     */
    public function plane(?int $bereichTeam, int $kuratorTeam, array $zeilen, ?string $wg = null): array
    {
        $gps = DB::table('foodalchemist_gps')->whereIn('id', array_map(fn ($z) => (int) $z['gp_id'], $zeilen))->get()->keyBy('id');
        $aenderungen = [];
        $konflikte = [];
        $bericht = [];
        $ohne = 0;
        $gesehen = [];
        $neueNamen = [];
        $neueKeys = [];

        foreach ($zeilen as $z) {
            $gpId = (int) $z['gp_id'];
            $gp = $gps->get($gpId);
            $nameAlt = trim((string) $z['name_alt']);
            $felder = $z['felder'];
            $konflikt = function (string $grund, ?string $nameNeu = null) use (&$konflikte, &$bericht, $z, $gpId, $nameAlt) {
                $konflikte[] = ['zeile' => $z['zeile'], 'gp_id' => $gpId, 'name_alt' => $nameAlt, 'grund' => $grund];
                $bericht[] = [$gpId, $nameAlt, $nameNeu ?? '', '', 'konflikt', $grund];
            };
            if ($gp === null || $gp->deleted_at !== null) {
                $konflikt('GP existiert nicht (mehr)');

                continue;
            }
            if ($wg !== null && (string) $gp->commodity_group_code !== $wg) {
                continue;
            }
            if (isset($gesehen[$gpId])) {
                $konflikt("GP steht mehrfach im Plan (Zeile {$gesehen[$gpId]})");

                continue;
            }
            $gesehen[$gpId] = $z['zeile'];
            if (! $this->bereich->imBereich($gp, $bereichTeam) || ! in_array($gp->status, self::AKTIV, true)) {
                $konflikt("Bereich/Status passt nicht (Team {$gp->team_id}, {$gp->status})" . ($bereichTeam !== null && $gp->team_id === null ? ' — globales GP: --global' : ''));

                continue;
            }
            if (trim((string) $gp->name) !== $nameAlt) {
                $konflikt('Name seit Plan geändert (live: »' . $gp->name . '«)');

                continue;
            }
            if ($felder === []) {
                $konflikt('felder leer');

                continue;
            }
            $wgNeu = (string) ($felder['commodity_group_code'] ?? $gp->commodity_group_code);
            $fehler = GpLaufFelder::pruefe($felder, $wgNeu, $bereichTeam ?? $kuratorTeam);
            if ($wgNeu !== (string) $gp->commodity_group_code && ! array_key_exists('sub_category', $felder) && trim((string) $gp->sub_category) !== ''
                && ! GpLaufFelder::untergruppeVerwaltet($wgNeu, (string) $gp->sub_category, $bereichTeam ?? $kuratorTeam)) {
                $fehler[] = "Untergruppe »{$gp->sub_category}« passt nicht zur neuen WG {$wgNeu} — sub_category mitgeben (oder null)";
            }
            if ($fehler !== []) {
                $konflikt(implode('; ', $fehler));

                continue;
            }

            $modell = FoodAlchemistGp::query()->ohneTeamSicht('master-kuration')->find($gpId);
            $altIn = $this->eingabeAusGp($modell);
            $in = array_merge($altIn, GpLaufFelder::alsEingabe($felder));
            if (trim((string) ($in['hauptzutat'] ?? '')) === '') {
                $konflikt('Hauptzutat leer — main_ingredient_display im Plan mitgeben');

                continue;
            }
            ['in' => $inFertig, 'name' => $nameNeu, 'pruefung' => $pruefung] = $this->naming->updateVorschau($modell, $in);
            if ($pruefung['errors'] !== []) {
                $konflikt('Neuer Name verletzt Regelwerk: ' . implode('; ', $pruefung['errors']), $nameNeu);

                continue;
            }
            $nameGleich = $nameNeu === $nameAlt;
            if (! $nameGleich) {
                $besetzt = $this->bereich->bereich(DB::table('foodalchemist_gps'), $bereichTeam)->whereNull('deleted_at')->whereIn('status', self::AKTIV)
                    ->where('name', $nameNeu)->where('id', '!=', $gpId)->first();
                if ($besetzt !== null) {
                    $konflikt("Merge-Kandidat: »{$nameNeu}« trägt schon #{$besetzt->id} ({$besetzt->status})", $nameNeu);

                    continue;
                }
                if (isset($neueNamen[mb_strtolower($nameNeu)])) {
                    $konflikt('Merge-Kandidat: gleicher neuer Name wie #' . $neueNamen[mb_strtolower($nameNeu)] . ' im selben Lauf', $nameNeu);

                    continue;
                }
            }
            [$keyNeu, $slugNeu, $keyAbleitbar] = $this->neueSchluessel($gp, $this->naming->updateVorschau($modell, $altIn)['in'], $inFertig, $nameNeu);
            if ($keyNeu !== $gp->gp_key) {
                // gp_key ist je Bereich eindeutig — alle Zeilen (auch merged/soft-gelöscht) zählen mit
                $kollision = $this->bereich->bereich(DB::table('foodalchemist_gps'), $bereichTeam)->where('gp_key', $keyNeu)->where('id', '!=', $gpId)->first();
                if ($kollision !== null || isset($neueKeys[$keyNeu])) {
                    $konflikt('Key-Kollision: »' . $keyNeu . '« gehört schon #' . ($kollision->id ?? $neueKeys[$keyNeu]) . ' — Merge-Kandidat?', $nameNeu);

                    continue;
                }
                $neueKeys[$keyNeu] = $gpId;
            }

            $geaendert = $this->geaenderteFelder($gp, $felder);
            if (! $nameGleich) {
                array_unshift($geaendert, 'name');
            }
            if ($keyNeu !== $gp->gp_key) {
                $geaendert[] = 'gp_key';
            }
            if ($slugNeu !== $gp->main_ingredient_slug) {
                $geaendert[] = 'main_ingredient_slug';
            }
            $hinweise = array_values(array_filter([...$pruefung['warnings'], $keyAbleitbar ? null : 'gp_key nicht aus Name/Feldern ableitbar — bleibt']));
            if ($geaendert === []) {
                $ohne++;
                $bericht[] = [$gpId, $nameAlt, $nameNeu, '', 'ohne_aenderung', implode(' | ', $hinweise)];

                continue;
            }
            if (! $nameGleich) {
                $neueNamen[mb_strtolower($nameNeu)] = $gpId;
            }
            $aenderungen[] = [
                'gp_id' => $gpId, 'wg' => $gp->commodity_group_code, 'name_alt' => $nameAlt, 'name_neu' => $nameNeu, 'in' => $in, 'felder' => $felder,
                'gp_key_neu' => $keyNeu, 'slug_neu' => $slugNeu, 'geaendert' => $geaendert, 'hinweise' => $hinweise, 'notiz' => (string) ($z['notiz'] ?? ''),
            ];
            $bericht[] = [$gpId, $nameAlt, $nameNeu, implode(',', $geaendert), 'ok', implode(' | ', $hinweise)];
        }

        return ['aenderungen' => $aenderungen, 'konflikte' => $konflikte, 'bericht' => $bericht, 'ohne_aenderung' => $ohne];
    }

    /** Naming-Eingabe aus dem Ist-Stand eines GP (Schlüssel wie updateGp/gps.PUT). @return array<string,mixed> */
    public function eingabeAusGp(FoodAlchemistGp $gp): array
    {
        return [
            'hauptzutat' => (string) $gp->main_ingredient_display, 'flavor' => (string) $gp->flavor, 'zusatz' => (string) $gp->zusatz,
            'condition' => (string) $gp->condition, 'processing' => (string) $gp->processing, 'form' => (string) $gp->form,
            'portion' => (string) $gp->portion, 'pflichtangabe' => (string) $gp->pflichtangabe,
            'commodity_group_code' => (string) $gp->commodity_group_code, 'sub_category' => $gp->sub_category,
            'bio' => trim((string) $gp->bio) !== '', 'vegan' => (bool) $gp->namens_vegan, 'glutenfrei' => (bool) $gp->namens_glutenfrei,
            'laktosefrei' => (bool) $gp->namens_laktosefrei, 'msc' => (bool) $gp->namens_msc, 'asc' => (bool) $gp->namens_asc,
            'herkunft_land' => (string) $gp->herkunft_land,
        ];
    }

    /**
     * Neue Schlüssel: gp_key folgt, wenn er vorher aus dem Namen (Slug) oder aus den Feldern (gpKeyAusFeldern / buildGpKey) ableitbar war — in derselben
     * Bildungsform; sonst bleibt er. main_ingredient_slug folgt der Hauptzutat, wenn er vorher deren Slug war (oder leer).
     *
     * @return array{0: string, 1: ?string, 2: bool} [gp_key, main_ingredient_slug, ableitbar]
     */
    private function neueSchluessel(object $gp, array $altIn, array $neuIn, string $nameNeu): array
    {
        $n = $this->naming;
        $altSlug = $n->slugify((string) ($altIn['hauptzutat'] ?? ''));
        $neuSlug = $n->slugify((string) ($neuIn['hauptzutat'] ?? ''));
        $key = (string) $gp->gp_key;
        $neuerKey = match (true) {
            $key === $n->slugify((string) $gp->name) => $n->slugify($nameNeu),
            $key === $n->gpKeyAusFeldern($altSlug, $altIn) => $n->gpKeyAusFeldern($neuSlug, $neuIn),
            $key === $n->buildGpKey($altSlug, $altIn['processing'] ?? null, $altIn['form'] ?? null, $altIn['flavor'] ?? null, $altIn['zusatz'] ?? null)
                => $n->buildGpKey($neuSlug, $neuIn['processing'] ?? null, $neuIn['form'] ?? null, $neuIn['flavor'] ?? null, $neuIn['zusatz'] ?? null),
            default => null,
        };
        $slug = $gp->main_ingredient_slug;
        if (trim((string) $slug) === '' || $slug === $altSlug) {
            $slug = $neuSlug;
        }

        return [$neuerKey ?? $key, $slug, $neuerKey !== null];
    }

    /** Plan-Felder, deren Wert sich vom Ist-Stand unterscheidet. @return list<string> */
    private function geaenderteFelder(object $gp, array $felder): array
    {
        $out = [];
        foreach ($felder as $feld => $wert) {
            $ist = $gp->{$feld} ?? null;
            $gleich = match ($feld) {
                'bio' => (trim((string) $ist) !== '') === (bool) $wert,
                'namens_vegan', 'namens_glutenfrei', 'namens_laktosefrei', 'namens_msc', 'namens_asc' => (bool) $ist === (bool) $wert,
                'rezepturware' => ($ist === null ? null : (bool) $ist) === ($wert === null ? null : (bool) $wert),
                'piece_default_g' => ($ist === null ? null : round((float) $ist, 2)) === ($wert === null ? null : round((float) $wert, 2)),
                'condition' => (string) $this->naming->normalisiereZustand((string) $ist) === (string) $this->naming->normalisiereZustand((string) $wert),
                default => trim((string) $ist) === trim((string) $wert),
            };
            if (! $gleich) {
                $out[] = $feld;
            }
        }

        return $out;
    }

    // ── Ausführen ──────────────────────────────────────────────────────────────────────────────────

    /**
     * Geprüfte Änderungen schreiben — je GP eine Transaktion über updateGp (ohne Modell-Ereignisse). Ein GP, dessen Name seit der Planung geändert
     * wurde oder dessen geschriebener Name vom geplanten abweicht, wird zurückgerollt und als Fehler gemeldet.
     *
     * @param  list<array<string,mixed>>  $aenderungen
     * @param  array<string,mixed>  $protokoll  erhält `felder`
     * @return array{betroffen: list<int>, fehler: list<array{gp_id:int, grund:string}>}
     */
    public function fuehreAus(Team $team, array $aenderungen, array &$protokoll): array
    {
        $spalten = array_values(array_intersect(self::SPALTEN, Schema::getColumnListing('foodalchemist_gps')));
        $protokoll['felder'] ??= [];
        $betroffen = [];
        $fehler = [];
        foreach ($aenderungen as $a) {
            try {
                DB::transaction(function () use ($team, $a, $spalten, &$protokoll) {
                    $alt = (array) DB::table('foodalchemist_gps')->where('id', $a['gp_id'])->lockForUpdate()->first($spalten);
                    if (trim((string) $alt['name']) !== $a['name_alt']) {
                        throw new \RuntimeException('Name seit Planung geändert');
                    }
                    $modell = FoodAlchemistGp::query()->ohneTeamSicht('master-kuration')->findOrFail($a['gp_id']);
                    FoodAlchemistGp::withoutEvents(fn () => $this->naming->updateGp($team, $modell, $a['in']));
                    $extra = ['gp_key' => $a['gp_key_neu'], 'main_ingredient_slug' => $a['slug_neu']];
                    if (array_key_exists('piece_default_g', $a['felder'])) {
                        $extra['piece_default_g'] = $a['felder']['piece_default_g'] === null ? null : (float) $a['felder']['piece_default_g'];
                    }
                    if (array_key_exists('condition', $a['felder']) && trim((string) $a['felder']['condition']) === '') {
                        $extra['condition'] = null;   // updateGp leert den Zustand nie; der Plan darf es (Name wurde ohne Zustand gerendert)
                    }
                    $zustandNeu = array_key_exists('condition', $extra) ? null : DB::table('foodalchemist_gps')->where('id', $a['gp_id'])->value('condition');
                    if ($zustandNeu !== $alt['condition'] && in_array('condition_source', $spalten, true)) {
                        $extra['condition_source'] = $zustandNeu === null ? null : 'manual';
                    }
                    DB::table('foodalchemist_gps')->where('id', $a['gp_id'])->update($extra + ['updated_at' => now()]);
                    $neu = (array) DB::table('foodalchemist_gps')->where('id', $a['gp_id'])->first($spalten);
                    if ($neu['name'] !== $a['name_neu']) {
                        throw new \RuntimeException("geschriebener Name »{$neu['name']}« ≠ geplant »{$a['name_neu']}«");
                    }
                    $diff = array_values(array_filter($spalten, fn ($s) => ($alt[$s] === null) !== ($neu[$s] === null) || (string) $alt[$s] !== (string) $neu[$s]));
                    $protokoll['felder'][] = [
                        'id' => $a['gp_id'],
                        'alt' => array_intersect_key($alt, array_flip([...$diff, 'name', 'gp_key'])),
                        'neu' => array_intersect_key($neu, array_flip([...$diff, 'name', 'gp_key'])),
                    ];
                });
                $betroffen[] = (int) $a['gp_id'];
            } catch (\RuntimeException $e) {
                $fehler[] = ['gp_id' => (int) $a['gp_id'], 'grund' => $e->getMessage()];
            }
        }

        return ['betroffen' => $betroffen, 'fehler' => $fehler];
    }

    /** Nachlauf je Lauf: Zähler + ungültige Leads (keine Rang-Neuwahl — die Artikelmenge hat sich nicht geändert), optional Rezepte. */
    public function nachlauf(Team $team, array $gpIds, array &$protokoll, bool $recompute): int
    {
        $this->nachlauf->zaehlerUndLead($team, $gpIds, $protokoll, false);

        return $recompute ? $this->nachlauf->rechneRezepte($gpIds) : 0;
    }

    // ── Zurücknehmen ───────────────────────────────────────────────────────────────────────────────

    /**
     * Protokoll umkehren (umgekehrte Reihenfolge). Ein GP wird nur zurückgesetzt, wenn Name UND gp_key noch dem protokollierten neuen Stand entsprechen.
     *
     * @param  array<string,mixed>  $p
     * @return array{zurueck: int, uebersprungen: int}
     */
    public function zuruecknehmen(array $p): array
    {
        $zurueck = 0;
        $skip = 0;
        DB::transaction(function () use ($p, &$zurueck, &$skip) {
            $r = $this->nachlauf->zuruecknehmen($p);
            $zurueck += $r['zurueck'];
            $skip += $r['uebersprungen'];
            foreach (array_reverse($p['felder'] ?? []) as $f) {
                $ist = DB::table('foodalchemist_gps')->where('id', $f['id'])->first();
                if ($ist !== null && $ist->name === $f['neu']['name'] && $ist->gp_key === $f['neu']['gp_key']) {
                    DB::table('foodalchemist_gps')->where('id', $f['id'])->update([...$f['alt'], 'updated_at' => now()]);
                    $zurueck++;
                } else {
                    $skip++;
                }
            }
        });

        return ['zurueck' => $zurueck, 'uebersprungen' => $skip];
    }
}
