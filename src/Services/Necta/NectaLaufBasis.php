<?php

namespace Platform\FoodAlchemist\Services\Necta;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\SignalSeverity;
use Platform\FoodAlchemist\Enums\SignalTyp;
use Platform\FoodAlchemist\Services\SignalService;
use Platform\FoodAlchemist\Support\JodAusZutaten;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Support\StammPaket;
use Symfony\Component\Uid\UuidV7;

/**
 * Gemeinsamer Unterbau von Nachholer ({@see NectaNachholService}) und Abgleich ({@see NectaAbgleichService}): Necta lesen,
 * Lieferanten-Phase (Anlage + Signal, Ausnahmen), Anlage neuer Artikel samt Details und Preisen, Necta-Stand, Laufzeile.
 *
 * Regeln, die für beide gelten: nur lesen aus Necta; Abgleich über `legacy_id`; neue Lieferanten/Artikel global stamm_paket ohne GP;
 * Lieferanten per `ohne_lieferanten` oder bei uns `is_inactive` werden übersprungen (Protokoll `uebersprungen`); Probelauf schreibt
 * nur die Laufzeile.
 */
abstract class NectaLaufBasis
{
    /** Bis zu so vielen neuen Artikeln je Lieferant: Details gezielt je Artikel holen; darüber seitenweise je Lieferant. */
    public const DETAIL_SCHWELLE = 25;

    protected const DETAIL_RESSOURCEN = [
        'allergene' => 'supplier-item-allergens',
        'deklaration' => 'supplier-item-declaration-substances',
        'naehrwerte' => 'supplier-item-nutritional-values',
        'preise' => 'supplier-item-prices',
    ];

    protected NectaQuelle $quelle;

    protected int $laufId;

    protected bool $probe;

    /** @var array<string,mixed> */
    protected array $opt;

    /** @var array<string,mixed> */
    protected array $zaehler = [];

    /** @var array<string, list<array<string,mixed>>> */
    protected array $beispiele = [];

    /** @var array<string,true> */
    protected array $unbekannteDeklarationsfelder = [];

    public function __construct(protected NectaMapper $mapper) {}

    protected function vorbereiten(int $verbindungId, array $opt, ?NectaQuelle $quelle): void
    {
        $this->quelle = $quelle ?? (app()->bound(NectaQuelle::class) ? app(NectaQuelle::class) : new NectaApiQuelle($verbindungId));
        $this->probe = (bool) ($opt['probe'] ?? false);
        $this->opt = $opt + ['seite' => 500, 'pause_ms' => 200, 'detail_schwelle' => self::DETAIL_SCHWELLE];
        $this->zaehler = ['lieferanten_necta' => 0, 'lieferanten_neu' => 0, 'artikel_geprueft' => 0, 'artikel_neu' => 0,
            'allergene_neu' => 0, 'deklaration_neu' => 0, 'naehrwerte_neu' => 0, 'preise_neu' => 0, 'api_seiten' => 0,
            'neue_lieferanten' => [], 'neue_artikel_je_lieferant' => [], 'lieferanten_erledigt' => 0, 'uebersprungen' => []];
        $this->beispiele = [];
        $this->unbekannteDeklarationsfelder = [];
    }

    // ── Lieferanten-Phase ─────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Alle Necta-Lieferanten lesen, fehlende global anlegen (+ Signal an den Master). Rückgabe sortiert nach Necta-ID, damit ein
     * Cursor (Index) bei einer Wiederaufnahme auf denselben Lieferanten zeigt.
     *
     * @return list<array{legacy_id:int, name:string, fa_id:?int}>
     */
    protected function lieferanten(callable $log): array
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

    // ── Neue Artikel ──────────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Neuen Necta-Artikel anlegen (global stamm_paket, ohne GP). Rückgabe: FA-ID (Probe: 0).
     *
     * @param  array{legacy_id:int, name:string, fa_id:?int}  $l
     */
    protected function artikelAnlegen(array $l, array $r): int
    {
        $zeile = $this->mapper->artikel($r);
        $this->beispiel('artikel', $zeile + ['lieferant' => $l['name']]);
        if ($this->probe) {
            return 0;
        }
        // Jod aus der Zutatenliste: nur am Artikel, nicht im Necta-Stand (Ableitung, kein Necta-Feld)
        $faId = $this->einfuegen('foodalchemist_supplier_items', $zeile + ['supplier_id' => $l['fa_id'], 'team_id' => null,
            'stamm_class' => StammPaket::PAKET, 'iodized_abgeleitet' => JodAusZutaten::wert($zeile['ingredients_supplier'] ?? null)]
            // Mengen-Korrektur aus der Bezeichnung (EDI: Menge / Stückzahl), ebenfalls nicht im Necta-Stand
            + \Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem::mengenKorrektur($zeile['designation'] ?? null, $zeile['qty'] ?? null,
                $zeile['qty_ordering_per_packaging'] ?? null, $zeile['unit_code'] ?? null));
        $this->stand('supplier_item', (int) $zeile['legacy_id'], $zeile);

        return $faId;
    }

    /**
     * Zähler + Details/Preise für die neuen Artikel eines Lieferanten.
     *
     * @param  array{legacy_id:int, name:string, fa_id:?int}  $l
     * @param  array<int,int>  $neu  Necta-ID => FA-ID
     */
    protected function neueArtikelAbschliessen(array $l, array $neu, ?int $teamId, callable $log): void
    {
        if ($neu === []) {
            return;
        }
        $this->zaehler['artikel_neu'] += count($neu);
        $this->zaehler['neue_artikel_je_lieferant'][] = ['legacy_id' => $l['legacy_id'], 'name' => $l['name'], 'neu' => count($neu)];
        $log(sprintf('%s: %d neue Artikel', $l['name'], count($neu)));

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
     * Details zu Artikeln: wenige gezielt je Artikel (Filter supplierItemId), viele seitenweise je Lieferant (supplierId).
     *
     * @param  list<int>  $artikel  Necta-IDs
     * @return \Generator<array<string,mixed>>
     */
    protected function details(string $ressource, int $lieferant, array $artikel): \Generator
    {
        if (count($artikel) <= (int) $this->opt['detail_schwelle']) {
            foreach ($artikel as $id) {
                yield from $this->alleSeitenIter($ressource, ['supplierItemId' => $id]);
            }

            return;
        }
        yield from $this->alleSeitenIter($ressource, ['supplierId' => $lieferant]);
    }

    protected function detailAnlegen(string $art, array $r, int $faArtikelId, ?int $teamId): void
    {
        [$tabelle, $objekt, $zeile, $team] = $this->detailZiel($art, $r, $teamId);
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

    /** @return array{0:string, 1:string, 2:array<string,mixed>, 3:?int} [Tabelle, Objekt, Zeile, Team] */
    protected function detailZiel(string $art, array $r, ?int $teamId): array
    {
        return match ($art) {
            'allergene' => ['foodalchemist_item_allergens', 'item_allergen', $this->mapper->allergene($r), null],
            'deklaration' => ['foodalchemist_item_declarations', 'item_declaration', $this->mapper->deklaration($r, $this->unbekannteDeklarationsfelder), null],
            'naehrwerte' => ['foodalchemist_item_nutritionals', 'item_nutritional', $this->mapper->naehrwerte($r), null],
            'preise' => ['foodalchemist_prices', 'price', $this->mapper->preis($r), $teamId],
        };
    }

    // ── Necta lesen ───────────────────────────────────────────────────────────────────────────────────────────────────────

    /** @return array{items: list<array<string,mixed>>, totalCount: int, pageNumber: int, pageSize: int, totalPages: int} */
    protected function holen(string $ressource, int $seite, array $filter): array
    {
        if ($this->zaehler['api_seiten'] > 0 && (int) $this->opt['pause_ms'] > 0) {
            usleep((int) $this->opt['pause_ms'] * 1000);   // schonend: Pause zwischen den Aufrufen
        }
        $this->zaehler['api_seiten']++;

        return $this->quelle->seite($ressource, $seite, (int) $this->opt['seite'], $filter);
    }

    /** @return \Generator<array<string,mixed>> */
    protected function alleSeitenIter(string $ressource, array $filter): \Generator
    {
        $seite = 1;
        do {
            $antwort = $this->holen($ressource, $seite, $filter);
            yield from $antwort['items'];
            $seite++;
        } while ($seite <= max(1, $antwort['totalPages']) && $antwort['items'] !== []);
    }

    /** @return list<array<string,mixed>> */
    protected function alleSeiten(string $ressource, array $filter): array
    {
        return iterator_to_array($this->alleSeitenIter($ressource, $filter), false);
    }

    // ── Schreiben ─────────────────────────────────────────────────────────────────────────────────────────────────────────

    /** @param array<string,mixed> $zeile */
    protected function einfuegen(string $tabelle, array $zeile): int
    {
        $jetzt = now()->toDateTimeString();

        return (int) DB::table($tabelle)->insertGetId(['uuid' => (string) UuidV7::generate(), ...$zeile, 'created_at' => $jetzt, 'updated_at' => $jetzt]);
    }

    /** Zuletzt gesehener Necta-Stand eines Objekts (Grundlage für „von Hand gepflegt“). @param array<string,mixed> $werte */
    protected function stand(string $objekt, int $legacyId, array $werte): void
    {
        unset($werte['legacy_id']);
        DB::table('foodalchemist_necta_stand')->updateOrInsert(['objekt' => $objekt, 'legacy_id' => $legacyId],
            ['werte' => json_encode($werte), 'lauf_id' => $this->laufId, 'updated_at' => now(), 'created_at' => now()]);
    }

    /** @return array<string,mixed>|null */
    protected function standLesen(string $objekt, int $legacyId): ?array
    {
        $w = DB::table('foodalchemist_necta_stand')->where('objekt', $objekt)->where('legacy_id', $legacyId)->value('werte');

        return $w === null ? null : (array) json_decode((string) $w, true);
    }

    protected function masterTeam(): ?Team
    {
        return MasterTeam::id() !== null ? Team::find(MasterTeam::id()) : null;
    }

    protected function signalNeuerLieferant(int $faId, int $legacyId, string $name): void
    {
        $master = $this->masterTeam();
        if ($master === null) {
            $this->zaehler['warnungen'][] = "Kein Master-Team: Signal für neuen Lieferanten {$name} nicht erzeugt.";

            return;
        }
        app(SignalService::class)->erzeuge($master, SignalTyp::NectaLieferantNeu, SignalSeverity::Info,
            "Neuer Lieferant {$name} aus Necta: für Mandanten freischalten?", [
                'dedup_key' => "necta_lieferant:{$legacyId}", 'source' => 'necta_abgleich', 'ref_type' => 'supplier', 'ref_id' => $faId,
                'description' => 'Der Lieferant wurde aus Necta global angelegt. Kein Mandant sieht ihn automatisch; die Freischaltung '
                    .'je Mandant steht in der Verwaltung (Karte Stamm-Paket).',
                // Ein neuer Lieferant hat noch keinen Mandanten → Lieferanten-Freischaltung ohne Vorauswahl
                'payload' => ['legacy_id' => $legacyId, 'name' => $name, 'lauf_id' => $this->laufId, 'sprung' => '/verwaltung?tab=freigaben'],
            ]);
    }

    // ── Laufzeile ─────────────────────────────────────────────────────────────────────────────────────────────────────────

    protected function laufAnlegen(string $art, int $verbindungId, ?int $teamId, array $opt): int
    {
        $optionen = array_intersect_key($opt, array_flip(['lieferanten', 'ohne_lieferanten', 'seite', 'pause_ms', 'max_lieferanten',
            'detail_schwelle', 'seit', 'voll', 'feld']));

        return (int) DB::table('foodalchemist_necta_laeufe')->insertGetId([
            'uuid' => (string) UuidV7::generate(), 'verbindung_id' => $verbindungId, 'team_id' => $teamId, 'art' => $art,
            'probe' => $this->probe, 'status' => 'laeuft', 'optionen' => json_encode($optionen), 'cursor' => json_encode(['lieferant_index' => 0]),
            'gestartet_at' => now(), 'ausgeloest_von' => $opt['user_id'] ?? null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function cursor(int $index): void
    {
        DB::table('foodalchemist_necta_laeufe')->where('id', $this->laufId)->update([
            'cursor' => json_encode(['lieferant_index' => $index]), 'zaehler' => json_encode($this->zaehler), 'updated_at' => now(),
        ]);
    }

    protected function laufBeenden(string $status, ?string $fehler = null): void
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
    protected function beispiel(string $art, array $zeile): void
    {
        if (count($this->beispiele[$art] ?? []) < 3) {
            $this->beispiele[$art][] = $zeile;
        }
    }
}
