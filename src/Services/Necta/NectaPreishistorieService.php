<?php

namespace Platform\FoodAlchemist\Services\Necta;

use Illuminate\Support\Facades\DB;

/**
 * Necta PR 2b, Stufe 2 (16.10.2026): {@see nachholen()} schreibt die Preis-Vergangenheit nach `foodalchemist_price_history`
 * (quelle = 'necta_historie'), abgestimmt mit Claudia (PreisVerlaufService):
 *  - Belegt an io (Lauf 10, 87.628 Zeilen): type N = Normalpreis → status '0', A = paralleler Aktionspreis → status '2'
 *    (Artikel 29075911: ab 05.10. gleichzeitig N 12,29 und A 9,99). Anderer type → nicht geschrieben, gezählt.
 *  - Je Artikel + validFrom + Status genau EINE Zeile: die mit der kleinsten amountFrom (Grundpreis; Staffeln verworfen, gezählt).
 *  - status_valid_from = validFrom, valid_to = NULL bei 3000-12-31 (offen), price = Gebindepreis (Claudia rechnet um),
 *    price_partial = priceOrderingUnit, team_id = team_id des FA-Artikels, details = {source, amount_from, type}.
 *  - Nur Artikel, die es bei uns gibt (legacy_id); inaktive Lieferanten aus; idempotent über `necta_historie_id`.
 *  - Zeiten Europe/Berlin → UTC wie der übrige Stamm ({@see NectaMapper::zeit()}).
 *  - Speicher: Kandidaten nur je Lieferant (kompakt), geschrieben in Blöcken zu 1.000; resumefähig je Lieferant.
 *
 * Necta PR 2b, Stufe 1: Analyse der Preis-Historie (`supplier-item-price-historys`, ~1,5 Mio. Zeilen) vor dem Nachholen.
 *
 * Bedeutung von `type`, `amountFrom` und `source` ist nicht dokumentiert; bekannt ist nur eine Stichprobe (15.10.2026:
 * type N, amountFrom 0, validTo 3000-12-31 = offen). Deshalb zuerst messen statt raten: je aktivem Lieferanten seitenweise lesen
 * und zählen (Verteilungen, Zeilen je Artikel, offene Zeilen, Beispiele, eine vollständige Folge eines Artikels). Schreibt nur
 * die Laufzeile. Das eigentliche Nachholen folgt, wenn Zuordnung und Ziel (mit Claudia, Preis-Linie) belegt sind.
 *
 * Schutz: Liefert der Filter `supplierId` Zeilen fremder Artikel (Filter wirkt nicht), bricht der Lieferant nach der ersten
 * Seite ab, statt 1,5 Mio. Zeilen je Lieferant zu lesen.
 */
class NectaPreishistorieService extends NectaLaufBasis
{
    /** Höchstens so viele Beispiele je Merkmal. */
    private const BEISPIELE = 3;

    /** Zeilen werden nur für so viele Artikel je Lieferant gehalten (Beispiel-Folge); sonst nur Zähler (memory_limit 512M). */
    private const FOLGE_KANDIDATEN = 50;

    /**
     * @param  array{probe?:bool, lieferanten?:list<int>, ohne_lieferanten?:list<int>, seite?:int, pause_ms?:int, max_seiten?:?int}  $opt
     * @return array{lauf_id:int, status:string, zaehler:array<string,mixed>, beispiele:array<string,mixed>}
     */
    public function analyse(int $verbindungId, array $opt, ?callable $log = null, ?NectaQuelle $quelle = null): array
    {
        $this->vorbereiten($verbindungId, ['probe' => true] + $opt, $quelle);
        $log ??= fn (string $z) => null;
        $this->zaehler = ['lieferanten' => 0, 'lieferanten_erledigt' => 0, 'api_seiten' => 0, 'zeilen' => 0, 'zeilen_bekannte_artikel' => 0,
            'zeilen_fremde_artikel' => 0, 'artikel_mit_historie' => 0, 'max_zeilen_je_artikel' => 0, 'offen_bis_3000' => 0,
            'type' => [], 'amount_from' => [], 'source_top' => [], 'filter_wirkt_nicht' => [], 'uebersprungen' => []];
        $this->laufId = $this->laufAnlegen('preishistorie', $verbindungId, null, $opt);

        try {
            $lieferanten = $this->aktiveLieferanten();
            $this->zaehler['lieferanten'] = count($lieferanten);
            foreach ($lieferanten as $l) {
                $this->lieferant($l);
                $this->zaehler['lieferanten_erledigt']++;
                $log("{$l->name} (Necta {$l->legacy_id}): {$this->zaehler['zeilen']} Zeilen bisher");
            }
            arsort($this->zaehler['source_top']);
            $this->zaehler['source_top'] = array_slice($this->zaehler['source_top'], 0, 10, true);
            ksort($this->zaehler['type']);
            ksort($this->zaehler['amount_from']);
            $this->laufBeenden('fertig');
        } catch (\Throwable $e) {
            $this->laufBeenden('fehler', $e->getMessage());
            throw $e;
        }

        return ['lauf_id' => $this->laufId, 'status' => 'fertig', 'zaehler' => $this->zaehler, 'beispiele' => $this->beispiele];
    }

    /** type → status der Preis-Linie (belegt an io, siehe Klassenkommentar). */
    public const TYPE_STATUS = ['N' => '0', 'A' => '2'];

    private const BLOCK = 1000;

    /**
     * @param  array{probe?:bool, lieferanten?:list<int>, ohne_lieferanten?:list<int>, seite?:int, pause_ms?:int, seit?:?string,
     *               fortsetzen?:?int, user_id?:?int}  $opt
     * @return array{lauf_id:int, status:string, zaehler:array<string,mixed>}
     */
    public function nachholen(int $verbindungId, array $opt, ?callable $log = null, ?NectaQuelle $quelle = null): array
    {
        $this->vorbereiten($verbindungId, $opt, $quelle);
        $log ??= fn (string $z) => null;
        $this->zaehler = ['lieferanten' => 0, 'lieferanten_erledigt' => 0, 'api_seiten' => 0, 'zeilen' => 0, 'zeilen_fremde_artikel' => 0,
            'typ_unbekannt' => 0, 'staffel_verworfen' => 0, 'vor_seit' => 0, 'zu_schreiben' => 0, 'geschrieben' => 0, 'schon_vorhanden' => 0,
            'filter_wirkt_nicht' => [], 'uebersprungen' => []];

        [$this->laufId, $startIndex] = $this->laufBeginnen($verbindungId, $opt);
        try {
            $lieferanten = $this->aktiveLieferanten();
            $this->zaehler['lieferanten'] = count($lieferanten);
            $index = 0;
            foreach ($lieferanten as $l) {
                $index++;
                if ($index <= $startIndex) {
                    continue;   // Wiederaufnahme: bis hier erledigt
                }
                $this->lieferantNachholen($l);
                $this->zaehler['lieferanten_erledigt']++;
                $this->cursor($index);
                $log("{$l->name} (Necta {$l->legacy_id}): {$this->zaehler['zu_schreiben']} Stände bisher");
            }
            $this->laufBeenden('fertig');
        } catch (\Throwable $e) {
            $this->laufBeenden('fehler', $e->getMessage());
            throw $e;
        }

        return ['lauf_id' => $this->laufId, 'status' => 'fertig', 'zaehler' => $this->zaehler];
    }

    private function lieferantNachholen(object $l): void
    {
        $artikel = DB::table('foodalchemist_supplier_items')->where('supplier_id', $l->id)->whereNotNull('legacy_id')
            ->get(['id', 'legacy_id', 'team_id'])->keyBy(fn ($a) => (int) $a->legacy_id);
        $seit = $this->opt['seit'] ?? null;
        $kandidaten = [];   // "faId|validFrom|status" → kompakte Zeile mit kleinster amountFrom
        $seite = 1;
        do {
            $antwort = $this->holen('supplier-item-price-historys', $seite, ['supplierId' => (int) $l->legacy_id]);
            $fremde = 0;
            foreach ($antwort['items'] as $r) {
                $this->zaehler['zeilen']++;
                $a = $artikel->get((int) ($r['supplierItemId'] ?? 0));
                if ($a === null) {
                    $this->zaehler['zeilen_fremde_artikel']++;
                    $fremde++;

                    continue;
                }
                $status = self::TYPE_STATUS[(string) ($r['type'] ?? '')] ?? null;
                if ($status === null) {
                    $this->zaehler['typ_unbekannt']++;

                    continue;
                }
                if ($seit !== null && strcmp((string) ($r['validFrom'] ?? ''), (string) $seit) < 0) {
                    $this->zaehler['vor_seit']++;

                    continue;
                }
                $schluessel = "{$a->id}|{$r['validFrom']}|{$status}";
                $menge = (float) ($r['amountFrom'] ?? 0);
                if (isset($kandidaten[$schluessel])) {
                    $this->zaehler['staffel_verworfen']++;
                    if ($menge >= $kandidaten[$schluessel][0]) {
                        continue;
                    }
                }
                $kandidaten[$schluessel] = [$menge, (int) $r['id'], (int) $a->id, $a->team_id === null ? null : (int) $a->team_id, $status,
                    $r['price'] ?? null, $r['priceOrderingUnit'] ?? null, (string) ($r['validFrom'] ?? ''), (string) ($r['validTo'] ?? ''),
                    $r['source'] ?? null, (string) ($r['type'] ?? '')];
            }
            if ($seite === 1 && $antwort['items'] !== [] && $fremde === count($antwort['items'])) {
                $this->zaehler['filter_wirkt_nicht'][] = ['legacy_id' => (int) $l->legacy_id, 'total' => $antwort['totalCount']];

                return;
            }
            $seite++;
        } while ($seite <= max(1, $antwort['totalPages']) && $antwort['items'] !== []);

        $this->zaehler['zu_schreiben'] += count($kandidaten);
        foreach (array_chunk(array_values($kandidaten), self::BLOCK) as $block) {
            $this->blockSchreiben($block);
        }
    }

    /** @param list<array> $block */
    private function blockSchreiben(array $block): void
    {
        $ids = array_column($block, 1);
        $vorhanden = DB::table('foodalchemist_price_history')->whereIn('necta_historie_id', $ids)->pluck('necta_historie_id')
            ->map(fn ($v) => (int) $v)->flip();
        $jetzt = now()->toDateTimeString();
        $zeilen = [];
        foreach ($block as [$menge, $id, $faId, $teamId, $status, $preis, $preisBe, $von, $bis, $source, $type]) {
            if ($vorhanden->has($id)) {
                $this->zaehler['schon_vorhanden']++;

                continue;
            }
            $zeile = ['uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(), 'price_id' => null, 'legacy_id' => null, 'necta_historie_id' => $id,
                'supplier_item_id' => $faId, 'team_id' => $teamId, 'status' => $status, 'price' => $preis, 'price_partial' => $preisBe,
                'status_valid_from' => NectaMapper::zeit($von),
                'valid_to' => str_starts_with($bis, '3000-') ? null : NectaMapper::zeit($bis),   // 3000-12-31 = offen
                'is_blocked' => false, 'change_date' => null, 'quelle' => 'necta_historie',
                'details' => json_encode(['source' => $source, 'amount_from' => $menge, 'type' => $type]),
                'lauf_id' => $this->laufId, 'abgeloest_at' => null, 'created_at' => $jetzt, 'updated_at' => $jetzt];
            $this->beispiel('stand', $zeile);
            $zeilen[] = $zeile;
        }
        if ($this->probe || $zeilen === []) {
            return;
        }
        $this->zaehler['geschrieben'] += DB::table('foodalchemist_price_history')->insertOrIgnore($zeilen);
    }

    /** @return array{0:int, 1:int} [Lauf-ID, Start-Index] */
    private function laufBeginnen(int $verbindungId, array $opt): array
    {
        if (! empty($opt['fortsetzen'])) {
            $alt = DB::table('foodalchemist_necta_laeufe')->where('id', (int) $opt['fortsetzen'])->where('art', 'preishistorie')->first();
            if ($alt === null) {
                throw new \RuntimeException("Lauf {$opt['fortsetzen']} (Preis-Historie) nicht gefunden.");
            }
            DB::table('foodalchemist_necta_laeufe')->where('id', $alt->id)->update(['status' => 'laeuft', 'fehler' => null, 'updated_at' => now()]);

            return [(int) $alt->id, (int) (json_decode((string) $alt->cursor, true)['lieferant_index'] ?? 0)];
        }

        return [$this->laufAnlegen('preishistorie', $verbindungId, null, $opt), 0];
    }

    /** Aktive, bei uns vorhandene Necta-Lieferanten (wie das Nachfüllen), sortiert nach Necta-ID. */
    private function aktiveLieferanten(): array
    {
        $filter = array_map('intval', $this->opt['lieferanten'] ?? []);
        $ohne = array_map('intval', $this->opt['ohne_lieferanten'] ?? []);
        $raus = [];
        foreach (DB::table('foodalchemist_suppliers')->whereNotNull('legacy_id')->whereNull('deleted_at')->orderBy('legacy_id')
            ->get(['id', 'legacy_id', 'name', 'is_inactive']) as $l) {
            $grund = in_array((int) $l->legacy_id, $ohne, true) ? 'ausgenommen (--ohne-lieferant)' : ((bool) $l->is_inactive ? 'bei uns inaktiv' : null);
            if ($grund !== null) {
                $this->zaehler['uebersprungen'][] = ['legacy_id' => (int) $l->legacy_id, 'name' => (string) $l->name, 'grund' => $grund];

                continue;
            }
            if ($filter === [] || in_array((int) $l->legacy_id, $filter, true)) {
                $raus[] = $l;
            }
        }

        return $raus;
    }

    private function lieferant(object $l): void
    {
        $artikel = DB::table('foodalchemist_supplier_items')->where('supplier_id', $l->id)->whereNotNull('legacy_id')
            ->pluck('legacy_id')->map(fn ($v) => (int) $v)->flip()->all();
        $jeArtikel = [];   // Necta-Artikel → Anzahl Zeilen
        $folgen = [];      // nur für FOLGE_KANDIDATEN Artikel: die Zeilen
        $seite = 1;
        $maxSeiten = isset($this->opt['max_seiten']) ? max(1, (int) $this->opt['max_seiten']) : null;
        do {
            $antwort = $this->holen('supplier-item-price-historys', $seite, ['supplierId' => (int) $l->legacy_id]);
            $fremde = 0;
            foreach ($antwort['items'] as $r) {
                $this->zaehlen($r, $artikel, $jeArtikel, $folgen, $fremde);
            }
            if ($seite === 1 && $antwort['items'] !== [] && $fremde === count($antwort['items'])) {
                // Keine einzige Zeile gehört zu Artikeln dieses Lieferanten: Filter wirkt nicht → nicht weiterlesen
                $this->zaehler['filter_wirkt_nicht'][] = ['legacy_id' => (int) $l->legacy_id, 'total' => $antwort['totalCount']];

                return;
            }
            $seite++;
        } while ($seite <= max(1, $antwort['totalPages']) && $antwort['items'] !== [] && ($maxSeiten === null || $seite <= $maxSeiten));

        $this->zaehler['artikel_mit_historie'] += count($jeArtikel);
        $this->zaehler['max_zeilen_je_artikel'] = max($this->zaehler['max_zeilen_je_artikel'], $jeArtikel === [] ? 0 : max($jeArtikel));
        foreach ($folgen as $legacy => $zeilen) {
            // Eine vollständige Folge (≥ 3 Zeilen), chronologisch, um Überlappung/Lücken von validFrom/validTo zu sehen
            if (count($zeilen) >= 3 && ! isset($this->beispiele['folge'])) {
                usort($zeilen, fn ($a, $b) => strcmp((string) $a['validFrom'], (string) $b['validFrom']) ?: ((int) $a['amountFrom'] <=> (int) $b['amountFrom']));
                $this->beispiele['folge'] = ['supplierItemId' => $legacy, 'zeilen' => array_slice($zeilen, 0, 25)];
            }
        }
    }

    /** @param array<int,int> $artikel  @param array<int,int> $jeArtikel  @param array<int,list<array<string,mixed>>> $folgen */
    private function zaehlen(array $r, array $artikel, array &$jeArtikel, array &$folgen, int &$fremde): void
    {
        $this->zaehler['zeilen']++;
        $legacy = (int) ($r['supplierItemId'] ?? 0);
        if (! isset($artikel[$legacy])) {
            $this->zaehler['zeilen_fremde_artikel']++;
            $fremde++;

            return;
        }
        $this->zaehler['zeilen_bekannte_artikel']++;
        $type = (string) ($r['type'] ?? '∅');
        $this->zaehler['type'][$type] = ($this->zaehler['type'][$type] ?? 0) + 1;
        $menge = $r['amountFrom'] ?? null;
        $mengeKey = $menge === null ? '∅' : (string) (0 + $menge);
        $this->zaehler['amount_from'][$mengeKey] = ($this->zaehler['amount_from'][$mengeKey] ?? 0) + 1;
        $source = (string) ($r['source'] ?? '∅');
        $this->zaehler['source_top'][$source] = ($this->zaehler['source_top'][$source] ?? 0) + 1;
        if (str_starts_with((string) ($r['validTo'] ?? ''), '3000-')) {
            $this->zaehler['offen_bis_3000']++;
        }
        $jeArtikel[$legacy] = ($jeArtikel[$legacy] ?? 0) + 1;
        if (isset($folgen[$legacy]) || count($folgen) < self::FOLGE_KANDIDATEN) {
            $folgen[$legacy][] = array_intersect_key($r, array_flip(['id', 'type', 'price', 'priceOrderingUnit', 'amountFrom', 'validFrom', 'validTo', 'source']));
        }
        $this->beispielJe("type_{$type}", $r);
        if ($menge !== null && (float) $menge > 1) {
            $this->beispielJe('amount_from_gross', $r);
        }
    }

    private function beispielJe(string $art, array $r): void
    {
        if (count($this->beispiele[$art] ?? []) < self::BEISPIELE) {
            $this->beispiele[$art][] = $r;
        }
    }
}
