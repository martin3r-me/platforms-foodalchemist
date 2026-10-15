<?php

namespace Platform\FoodAlchemist\Services\Necta;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\SignalSeverity;
use Platform\FoodAlchemist\Enums\SignalTyp;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\SignalService;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Support\StammPaket;
use Symfony\Component\Uid\UuidV7;

/**
 * Necta-Abgleich · Einmal-Nachholer (Bauauftrag 15.10.2026): holt, was im FA-Stamm fehlt — Lieferanten, Lieferantenartikel und
 * zu NEUEN Artikeln Allergene, Deklaration, Nährwerte und Preise. Bestehende Artikel fasst der Nachholer nicht an (das ist der
 * Abgleich, PR 2).
 *
 *  - Nur lesen aus Necta, nie zurückschreiben. Abgleich über `legacy_id` (Necta-ID).
 *  - Neue Lieferanten und Artikel global (`team_id` NULL, `stamm_class` = stamm_paket), Artikel ohne GP: Sie erscheinen in der
 *    Ingest-Lücke „ohne GP“, ein Zuordnungs-Lauf wird bewusst NICHT gestartet (Dominique: „es geht nur um die Lieferantenartikel“).
 *  - Neuer Lieferant → Signal an das Master-Team (Freischalten für Mandanten ist dessen Entscheidung, kein Mandant bekommt ihn).
 *  - Preise im Team des Laufs (Master bzw. später das Team der Verbindung).
 *  - Probelauf: liest alles, schreibt keine Stammdaten — nur die Laufzeile mit Zählern und Beispielen.
 *  - Resumefähig: Cursor (Lieferanten-Index) steht nach jedem Lieferanten in der Laufzeile; ein Neustart überspringt Erledigtes,
 *    und weil jede Anlage gegen `legacy_id` prüft, ist auch ein Neustart ohne Cursor idempotent.
 *  - Ausgenommen: Lieferanten per `ohne_lieferanten` (Necta-IDs) und Lieferanten, die bei uns `is_inactive` sind (z. B. der
 *    auslaufende alte Handelshof-Kanal 16555, Dominique 15.10.). Beides steht im Protokoll unter `uebersprungen`.
 *  - Rohes Schreiben umgeht den Embedding-Observer. Bewusst KEIN Einreihen hier (84k Jobs auf einmal): danach läuft
 *    `foodalchemist:embed --pool=la --missing` (gestapelt, mit Pause und Zeitfenster).
 */
class NectaNachholService
{
    /** Bis zu so vielen neuen Artikeln je Lieferant: Details gezielt je Artikel holen; darüber seitenweise je Lieferant. */
    public const DETAIL_SCHWELLE = 25;

    private const DETAIL_RESSOURCEN = [
        'allergene' => 'supplier-item-allergens',
        'deklaration' => 'supplier-item-declaration-substances',
        'naehrwerte' => 'supplier-item-nutritional-values',
        'preise' => 'supplier-item-prices',
    ];

    private NectaQuelle $quelle;

    private int $laufId;

    private bool $probe;

    /** @var array<string,mixed> */
    private array $opt;

    /** @var array<string,mixed> */
    private array $zaehler = [];

    /** @var array<string, list<array<string,mixed>>> */
    private array $beispiele = [];

    /** @var array<string,true> */
    private array $unbekannteDeklarationsfelder = [];

    public function __construct(private NectaMapper $mapper) {}

    /**
     * @param  array{team_id?: ?int, probe?: bool, lieferanten?: list<int>, seite?: int, pause_ms?: int, fortsetzen?: ?int,
     *               ohne_lieferanten?: list<int>, max_lieferanten?: ?int, detail_schwelle?: int, user_id?: ?int}  $opt
     * @param  callable(string): void|null  $log
     * @return array{lauf_id: int, status: string, zaehler: array<string,mixed>}
     */
    public function lauf(int $verbindungId, array $opt = [], ?callable $log = null, ?NectaQuelle $quelle = null): array
    {
        $this->quelle = $quelle ?? (app()->bound(NectaQuelle::class) ? app(NectaQuelle::class) : new NectaApiQuelle($verbindungId));
        $this->probe = (bool) ($opt['probe'] ?? false);
        $this->opt = $opt + ['seite' => 500, 'pause_ms' => 200, 'detail_schwelle' => self::DETAIL_SCHWELLE];
        $log ??= fn (string $z) => null;
        $teamId = $opt['team_id'] ?? MasterTeam::id();

        [$this->laufId, $startIndex] = $this->laufBeginnen($verbindungId, $teamId, $opt);
        $this->zaehler = ['lieferanten_necta' => 0, 'lieferanten_neu' => 0, 'artikel_geprueft' => 0, 'artikel_neu' => 0,
            'allergene_neu' => 0, 'deklaration_neu' => 0, 'naehrwerte_neu' => 0, 'preise_neu' => 0, 'api_seiten' => 0,
            'neue_lieferanten' => [], 'neue_artikel_je_lieferant' => [], 'lieferanten_erledigt' => 0, 'uebersprungen' => []];

        try {
            $lieferanten = $this->lieferanten($log);
            $index = 0;
            foreach ($lieferanten as $l) {
                $index++;
                if ($index <= $startIndex) {
                    continue;   // Wiederaufnahme: bis hier erledigt
                }
                if (! empty($opt['max_lieferanten']) && $this->zaehler['lieferanten_erledigt'] >= (int) $opt['max_lieferanten']) {
                    break;
                }
                $this->lieferant($l, $teamId, $log);
                $this->zaehler['lieferanten_erledigt']++;
                $this->cursor($index);
            }
            $this->laufBeenden('fertig');
        } catch (\Throwable $e) {
            $this->laufBeenden('fehler', $e->getMessage());
            throw $e;
        }

        return ['lauf_id' => $this->laufId, 'status' => 'fertig', 'zaehler' => $this->zaehler];
    }

    // ── Phasen ────────────────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Alle Necta-Lieferanten lesen, fehlende global anlegen (+ Signal an den Master). Rückgabe sortiert nach Necta-ID, damit der
     * Cursor (Index) bei einer Wiederaufnahme auf denselben Lieferanten zeigt.
     *
     * @return list<array{legacy_id:int, name:string, fa_id:?int}>
     */
    private function lieferanten(callable $log): array
    {
        $alle = $this->alleSeiten('suppliers', []);
        $this->zaehler['lieferanten_necta'] = count($alle);
        $vorhandeneZeilen = DB::table('foodalchemist_suppliers')->whereIn('legacy_id', array_map(fn ($r) => (int) $r['id'], $alle))
            ->get(['id', 'legacy_id', 'is_inactive', 'name'])->keyBy('legacy_id');
        $filter = array_map('intval', $this->opt['lieferanten'] ?? []);
        $ohne = array_map('intval', $this->opt['ohne_lieferanten'] ?? []);

        $raus = [];
        foreach ($alle as $r) {
            $legacy = (int) $r['id'];
            $bei = $vorhandeneZeilen->get($legacy);
            $faId = $bei !== null ? (int) $bei->id : null;
            // Ausnahmen VOR jeder Anlage: ausgenommen per Option oder bei uns stillgelegt (auslaufender Kanal)
            $grund = in_array($legacy, $ohne, true) ? 'ausgenommen (--ohne-lieferant)' : ($bei !== null && (bool) $bei->is_inactive ? 'bei uns inaktiv' : null);
            if ($grund !== null) {
                $this->zaehler['uebersprungen'][] = ['legacy_id' => $legacy, 'name' => (string) ($bei->name ?? $r['name'] ?? ''), 'grund' => $grund];
                $log("Übersprungen: ".($bei->name ?? $r['name'] ?? '?')." (Necta {$legacy}) — {$grund}");

                continue;
            }
            if ($faId === null) {
                $zeile = $this->mapper->lieferant($r);
                $this->beispiel('lieferanten', $zeile);
                $this->zaehler['lieferanten_neu']++;
                $this->zaehler['neue_lieferanten'][] = ['legacy_id' => $legacy, 'name' => $zeile['name']];
                if (! $this->probe) {
                    $faId = $this->einfuegen('foodalchemist_suppliers', $zeile + ['team_id' => null, 'stamm_class' => StammPaket::PAKET]);
                    $this->stand('supplier', $legacy, $zeile);
                    $this->signalNeuerLieferant($faId, $legacy, $zeile['name']);
                }
                $log("Neuer Lieferant: {$zeile['name']} (Necta {$legacy})");
            }
            if ($filter !== [] && ! in_array($legacy, $filter, true)) {
                continue;
            }
            $raus[] = ['legacy_id' => $legacy, 'name' => (string) ($r['name'] ?? ''), 'fa_id' => $faId];
        }
        usort($raus, fn ($a, $b) => $a['legacy_id'] <=> $b['legacy_id']);

        return $raus;
    }

    /** @param array{legacy_id:int, name:string, fa_id:?int} $l */
    private function lieferant(array $l, ?int $teamId, callable $log): void
    {
        // 1. Artikel des Lieferanten seitenweise; nur fehlende legacy_ids anlegen
        $neu = [];   // legacy_id => fa_id (Probe: 0)
        $seite = 1;
        do {
            $antwort = $this->holen('supplier-items', $seite, ['supplierId' => $l['legacy_id']]);
            $items = $antwort['items'];
            $this->zaehler['artikel_geprueft'] += count($items);
            $ids = array_map(fn ($r) => (int) $r['id'], $items);
            $bekannt = $ids === [] ? [] : DB::table('foodalchemist_supplier_items')->whereIn('legacy_id', $ids)->pluck('legacy_id')->flip()->all();
            $zeilen = [];
            foreach ($items as $r) {
                if (isset($bekannt[(int) $r['id']]) || isset($neu[(int) $r['id']])) {
                    continue;
                }
                $zeile = $this->mapper->artikel($r);
                $this->beispiel('artikel', $zeile + ['lieferant' => $l['name']]);
                $zeilen[] = $zeile;
                $neu[(int) $r['id']] = 0;
            }
            if (! $this->probe && $zeilen !== []) {
                foreach ($zeilen as $zeile) {
                    $faId = $this->einfuegen('foodalchemist_supplier_items', $zeile + ['supplier_id' => $l['fa_id'], 'team_id' => null,
                        'stamm_class' => StammPaket::PAKET]);
                    $neu[(int) $zeile['legacy_id']] = $faId;
                    $this->stand('supplier_item', (int) $zeile['legacy_id'], $zeile);
                }
            }
            $seite++;
        } while ($seite <= max(1, $antwort['totalPages']) && $items !== []);

        if ($neu === []) {
            return;
        }
        $this->zaehler['artikel_neu'] += count($neu);
        $this->zaehler['neue_artikel_je_lieferant'][] = ['legacy_id' => $l['legacy_id'], 'name' => $l['name'], 'neu' => count($neu)];
        $log(sprintf('%s: %d neue Artikel', $l['name'], count($neu)));

        // 2. Details + Preise der neuen Artikel
        foreach (self::DETAIL_RESSOURCEN as $art => $ressource) {
            foreach ($this->details($ressource, $l['legacy_id'], array_keys($neu)) as $r) {
                $artikelLegacy = (int) ($r['supplierItemId'] ?? 0);
                if (! array_key_exists($artikelLegacy, $neu)) {
                    continue;
                }
                $this->detailAnlegen($art, $r, $neu[$artikelLegacy], $teamId);
            }
        }
    }

    /**
     * Details zu neuen Artikeln: wenige gezielt je Artikel (Filter supplierItemId), viele seitenweise je Lieferant (supplierId).
     *
     * @param  list<int>  $artikel  Necta-IDs der neuen Artikel
     * @return \Generator<array<string,mixed>>
     */
    private function details(string $ressource, int $lieferant, array $artikel): \Generator
    {
        if (count($artikel) <= (int) $this->opt['detail_schwelle']) {
            foreach ($artikel as $id) {
                yield from $this->alleSeitenIter($ressource, ['supplierItemId' => $id]);
            }

            return;
        }
        yield from $this->alleSeitenIter($ressource, ['supplierId' => $lieferant]);
    }

    private function detailAnlegen(string $art, array $r, int $faArtikelId, ?int $teamId): void
    {
        [$tabelle, $objekt, $zeile, $team] = match ($art) {
            'allergene' => ['foodalchemist_item_allergens', 'item_allergen', $this->mapper->allergene($r), null],
            'deklaration' => ['foodalchemist_item_declarations', 'item_declaration', $this->mapper->deklaration($r, $this->unbekannteDeklarationsfelder), null],
            'naehrwerte' => ['foodalchemist_item_nutritionals', 'item_nutritional', $this->mapper->naehrwerte($r), null],
            'preise' => ['foodalchemist_prices', 'price', $this->mapper->preis($r), $teamId],
        };
        $zaehlKey = $art === 'preise' ? 'preise_neu' : "{$art}_neu";
        if ($art === 'preise' && DB::table('foodalchemist_prices')->where('legacy_id', $zeile['legacy_id'])->exists()) {
            return;   // Preiszeile schon da (legacy_id global unique)
        }
        $this->zaehler[$zaehlKey]++;
        $this->beispiel($art, $zeile);
        if ($this->probe) {
            return;
        }
        $this->einfuegen($tabelle, $zeile + ['supplier_item_id' => $faArtikelId, 'team_id' => $team]);
        $this->stand($objekt, (int) $zeile['legacy_id'], $zeile);
    }

    // ── Necta lesen ───────────────────────────────────────────────────────────────────────────────────────────────────────

    /** @return array{items: list<array<string,mixed>>, totalCount: int, pageNumber: int, pageSize: int, totalPages: int} */
    private function holen(string $ressource, int $seite, array $filter): array
    {
        if ($this->zaehler['api_seiten'] > 0 && (int) $this->opt['pause_ms'] > 0) {
            usleep((int) $this->opt['pause_ms'] * 1000);   // schonend: Pause zwischen den Aufrufen
        }
        $this->zaehler['api_seiten']++;

        return $this->quelle->seite($ressource, $seite, (int) $this->opt['seite'], $filter);
    }

    /** @return \Generator<array<string,mixed>> */
    private function alleSeitenIter(string $ressource, array $filter): \Generator
    {
        $seite = 1;
        do {
            $antwort = $this->holen($ressource, $seite, $filter);
            yield from $antwort['items'];
            $seite++;
        } while ($seite <= max(1, $antwort['totalPages']) && $antwort['items'] !== []);
    }

    /** @return list<array<string,mixed>> */
    private function alleSeiten(string $ressource, array $filter): array
    {
        return iterator_to_array($this->alleSeitenIter($ressource, $filter), false);
    }

    // ── Schreiben ─────────────────────────────────────────────────────────────────────────────────────────────────────────

    /** @param array<string,mixed> $zeile */
    private function einfuegen(string $tabelle, array $zeile): int
    {
        $jetzt = now()->toDateTimeString();

        return (int) DB::table($tabelle)->insertGetId(['uuid' => (string) UuidV7::generate(), ...$zeile, 'created_at' => $jetzt, 'updated_at' => $jetzt]);
    }

    /** Zuletzt übernommener Necta-Stand (Grundlage für „von Hand gepflegt“ im Abgleich, PR 2). @param array<string,mixed> $werte */
    private function stand(string $objekt, int $legacyId, array $werte): void
    {
        unset($werte['legacy_id']);
        DB::table('foodalchemist_necta_stand')->updateOrInsert(['objekt' => $objekt, 'legacy_id' => $legacyId],
            ['werte' => json_encode($werte), 'lauf_id' => $this->laufId, 'updated_at' => now(), 'created_at' => now()]);
    }

    private function signalNeuerLieferant(int $faId, int $legacyId, string $name): void
    {
        $master = MasterTeam::id() !== null ? Team::find(MasterTeam::id()) : null;
        if ($master === null) {
            $this->zaehler['warnungen'][] = "Kein Master-Team: Signal für neuen Lieferanten {$name} nicht erzeugt.";

            return;
        }
        app(SignalService::class)->erzeuge($master, SignalTyp::NectaLieferantNeu, SignalSeverity::Info,
            "Neuer Lieferant {$name} aus Necta: für Mandanten freischalten?", [
                'dedup_key' => "necta_lieferant:{$legacyId}", 'source' => 'necta_abgleich', 'ref_type' => 'supplier', 'ref_id' => $faId,
                'description' => 'Der Lieferant wurde aus Necta global angelegt. Kein Mandant sieht ihn automatisch; die Freischaltung '
                    .'je Mandant steht in der Verwaltung (Karte Stamm-Paket).',
                'payload' => ['legacy_id' => $legacyId, 'name' => $name, 'lauf_id' => $this->laufId,
                    // Peters stabile Adresse (Lieferanten-Freischaltung); die Variante mit &lieferanten={Mandanten-Team} folgt mit seinem PR
                    'sprung' => '/verwaltung?tab=freigaben'],
            ]);
    }

    // ── Laufzeile ─────────────────────────────────────────────────────────────────────────────────────────────────────────

    /** @return array{0:int, 1:int} [lauf_id, Start-Index] */
    private function laufBeginnen(int $verbindungId, ?int $teamId, array $opt): array
    {
        if (! empty($opt['fortsetzen'])) {
            $alt = DB::table('foodalchemist_necta_laeufe')->where('id', (int) $opt['fortsetzen'])->where('art', 'nachholen')->first();
            if ($alt === null) {
                throw new \RuntimeException("Lauf {$opt['fortsetzen']} (Nachholen) nicht gefunden.");
            }
            DB::table('foodalchemist_necta_laeufe')->where('id', $alt->id)->update(['status' => 'laeuft', 'fehler' => null, 'updated_at' => now()]);

            return [(int) $alt->id, (int) (json_decode((string) $alt->cursor, true)['lieferant_index'] ?? 0)];
        }
        $optionen = array_intersect_key($opt, array_flip(['lieferanten', 'ohne_lieferanten', 'seite', 'pause_ms', 'max_lieferanten', 'detail_schwelle']));
        $id = (int) DB::table('foodalchemist_necta_laeufe')->insertGetId([
            'uuid' => (string) UuidV7::generate(), 'verbindung_id' => $verbindungId, 'team_id' => $teamId, 'art' => 'nachholen',
            'probe' => $this->probe, 'status' => 'laeuft', 'optionen' => json_encode($optionen), 'cursor' => json_encode(['lieferant_index' => 0]),
            'gestartet_at' => now(), 'ausgeloest_von' => $opt['user_id'] ?? null, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$id, 0];
    }

    private function cursor(int $index): void
    {
        DB::table('foodalchemist_necta_laeufe')->where('id', $this->laufId)->update([
            'cursor' => json_encode(['lieferant_index' => $index]), 'zaehler' => json_encode($this->zaehler), 'updated_at' => now(),
        ]);
    }

    private function laufBeenden(string $status, ?string $fehler = null): void
    {
        if ($this->unbekannteDeklarationsfelder !== []) {
            $this->zaehler['deklaration_unbekannte_felder'] = array_keys($this->unbekannteDeklarationsfelder);
        }
        DB::table('foodalchemist_necta_laeufe')->where('id', $this->laufId)->update([
            'status' => $status, 'fehler' => $fehler !== null ? mb_substr($fehler, 0, 2000) : null, 'zaehler' => json_encode($this->zaehler),
            'beispiele' => json_encode($this->beispiele), 'beendet_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Probelauf-Beispiele: je Art die ersten drei. @param array<string,mixed> $zeile */
    private function beispiel(string $art, array $zeile): void
    {
        if (count($this->beispiele[$art] ?? []) < 3) {
            $this->beispiele[$art][] = $zeile;
        }
    }
}
