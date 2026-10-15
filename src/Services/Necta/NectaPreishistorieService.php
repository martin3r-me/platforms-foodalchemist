<?php

namespace Platform\FoodAlchemist\Services\Necta;

use Illuminate\Support\Facades\DB;

/**
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
