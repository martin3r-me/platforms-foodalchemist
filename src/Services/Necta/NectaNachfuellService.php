<?php

namespace Platform\FoodAlchemist\Services\Necta;

use Illuminate\Support\Facades\DB;

/**
 * Nachfüllen EINER neuen Deklarations-Spalte für den Bestand (erstmals Jod `iodized`, 15.10.2026): je aktivem Lieferanten
 * `supplier-item-declaration-substances` seitenweise lesen und nur diese Spalte setzen, nur wo sie NULL ist. Der Necta-Stand
 * bekommt das Feld dazu, damit der Vollvergleich danach dieselbe Grundlage sieht.
 *
 * Legt nichts an (keine Lieferanten, Artikel, Zeilen): fehlt die FA-Deklarationszeile, zählt das nur. Idempotent (NULL-Bedingung)
 * und resumefähig über die Laufzeile (`--fortsetzen`). Probelauf schreibt nur die Laufzeile.
 */
class NectaNachfuellService extends NectaLaufBasis
{
    /**
     * @param  array{feld:string, probe?:bool, lieferanten?:list<int>, ohne_lieferanten?:list<int>, seite?:int, pause_ms?:int,
     *               fortsetzen?:?int, user_id?:?int}  $opt
     * @return array{lauf_id:int, status:string, zaehler:array<string,mixed>}
     */
    public function lauf(int $verbindungId, array $opt, ?callable $log = null, ?NectaQuelle $quelle = null): array
    {
        $spalte = (string) $opt['feld'];
        $nectaFeld = array_search($spalte, NectaMapper::DEKLARATIONEN, true);
        if ($nectaFeld === false) {
            throw new \InvalidArgumentException("Unbekannte Deklarations-Spalte {$spalte} (erlaubt: ".implode(', ', NectaMapper::DEKLARATIONEN).').');
        }
        $this->vorbereiten($verbindungId, $opt, $quelle);
        $log ??= fn (string $z) => null;
        $this->zaehler = ['feld' => $spalte, 'lieferanten' => 0, 'lieferanten_erledigt' => 0, 'necta_zeilen' => 0, 'necta_mit_wert' => 0,
            'gesetzt' => 0, 'schon_gesetzt' => 0, 'ohne_fa_zeile' => 0, 'stand_ergaenzt' => 0, 'api_seiten' => 0, 'uebersprungen' => []];

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
                $this->lieferant($l, $nectaFeld, $spalte);
                $this->zaehler['lieferanten_erledigt']++;
                $this->cursor($index);
                $log("{$l->name} (Necta {$l->legacy_id}): gesetzt bisher {$this->zaehler['gesetzt']}");
            }
            $this->laufBeenden('fertig');
        } catch (\Throwable $e) {
            $this->laufBeenden('fehler', $e->getMessage());
            throw $e;
        }

        return ['lauf_id' => $this->laufId, 'status' => 'fertig', 'zaehler' => $this->zaehler];
    }

    /** Bei uns vorhandene, aktive Necta-Lieferanten, sortiert nach Necta-ID (stabiler Cursor). */
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

    private function lieferant(object $l, string $nectaFeld, string $spalte): void
    {
        $seite = 1;
        do {
            $antwort = $this->holen('supplier-item-declaration-substances', $seite, ['supplierId' => (int) $l->legacy_id]);
            $werte = [];   // Necta-Artikel-ID → Rohwert
            foreach ($antwort['items'] as $r) {
                $this->zaehler['necta_zeilen']++;
                if (($r[$nectaFeld] ?? null) !== null && $r[$nectaFeld] !== '') {
                    $werte[(int) $r['supplierItemId']] = (int) $r[$nectaFeld];
                }
            }
            $this->zaehler['necta_mit_wert'] += count($werte);
            if ($werte !== []) {
                $this->seiteSchreiben($werte, $spalte);
            }
            $seite++;
        } while ($seite <= max(1, $antwort['totalPages']) && $antwort['items'] !== []);
    }

    /** Eine Necta-Seite: EINE Abfrage für die FA-Zeilen, nur NULL-Spalten setzen. @param array<int,int> $werte */
    private function seiteSchreiben(array $werte, string $spalte): void
    {
        $fa = DB::table('foodalchemist_item_declarations')->whereIn('legacy_id', array_keys($werte))->whereNull('deleted_at')
            ->get(['id', 'legacy_id', $spalte])->keyBy('legacy_id');
        $gesetzt = [];
        foreach ($werte as $legacy => $wert) {
            $zeile = $fa->get($legacy);
            if ($zeile === null) {
                $this->zaehler['ohne_fa_zeile']++;
            } elseif ($zeile->{$spalte} !== null) {
                $this->zaehler['schon_gesetzt']++;
            } else {
                $this->zaehler['gesetzt']++;
                $this->beispiel('gesetzt', ['legacy_id' => $legacy, $spalte => $wert]);
                $gesetzt[$legacy] = $wert;
                if (! $this->probe) {
                    // NULL-Bedingung auch im UPDATE: ein paralleler Schreiber gewinnt, nichts wird überschrieben
                    DB::table('foodalchemist_item_declarations')->where('id', $zeile->id)->whereNull($spalte)
                        ->update([$spalte => $wert, 'updated_at' => now()]);
                }
            }
        }
        if ($this->probe || $gesetzt === []) {
            return;
        }
        $staende = DB::table('foodalchemist_necta_stand')->where('objekt', 'item_declaration')->whereIn('legacy_id', array_keys($gesetzt))
            ->get(['id', 'legacy_id', 'werte']);
        foreach ($staende as $s) {
            $w = (array) json_decode((string) $s->werte, true);
            $w[$spalte] = $gesetzt[(int) $s->legacy_id];
            DB::table('foodalchemist_necta_stand')->where('id', $s->id)->update(['werte' => json_encode($w), 'updated_at' => now()]);
            $this->zaehler['stand_ergaenzt']++;
        }
    }

    /** @return array{0:int, 1:int} [Lauf-ID, Start-Index] */
    private function laufBeginnen(int $verbindungId, array $opt): array
    {
        if (! empty($opt['fortsetzen'])) {
            $alt = DB::table('foodalchemist_necta_laeufe')->where('id', (int) $opt['fortsetzen'])->where('art', 'nachfuellen')->first();
            if ($alt === null) {
                throw new \RuntimeException("Lauf {$opt['fortsetzen']} (Nachfüllen) nicht gefunden.");
            }
            DB::table('foodalchemist_necta_laeufe')->where('id', $alt->id)->update(['status' => 'laeuft', 'fehler' => null, 'updated_at' => now()]);

            return [(int) $alt->id, (int) (json_decode((string) $alt->cursor, true)['lieferant_index'] ?? 0)];
        }

        return [$this->laufAnlegen('nachfuellen', $verbindungId, null, $opt), 0];
    }
}
