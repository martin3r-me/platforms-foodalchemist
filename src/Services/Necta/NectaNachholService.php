<?php

namespace Platform\FoodAlchemist\Services\Necta;

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Support\MasterTeam;

/**
 * Necta-Abgleich · Einmal-Nachholer (Bauauftrag 15.10.2026): holt, was im FA-Stamm fehlt — Lieferanten, Lieferantenartikel und
 * zu NEUEN Artikeln Allergene, Deklaration, Nährwerte und Preise. Bestehende Artikel fasst der Nachholer nicht an (das ist der
 * Abgleich, {@see NectaAbgleichService}). Gemeinsame Regeln und Anlage: {@see NectaLaufBasis}.
 *
 *  - Neue Lieferanten und Artikel global (`team_id` NULL, `stamm_class` = stamm_paket), Artikel ohne GP: Sie erscheinen in der
 *    Ingest-Lücke „ohne GP“, ein Zuordnungs-Lauf wird bewusst NICHT gestartet (Dominique: „es geht nur um die Lieferantenartikel“).
 *  - Resumefähig: Cursor (Lieferanten-Index) steht nach jedem Lieferanten in der Laufzeile; ein Neustart überspringt Erledigtes,
 *    und weil jede Anlage gegen `legacy_id` prüft, ist auch ein Neustart ohne Cursor idempotent.
 *  - Rohes Schreiben umgeht den Embedding-Observer. Bewusst KEIN Einreihen hier (84k Jobs auf einmal): danach läuft
 *    `foodalchemist:embed --pool=la --missing` (gestapelt, mit Pause und Zeitfenster).
 */
class NectaNachholService extends NectaLaufBasis
{
    /**
     * @param  array{team_id?: ?int, probe?: bool, lieferanten?: list<int>, seite?: int, pause_ms?: int, fortsetzen?: ?int,
     *               ohne_lieferanten?: list<int>, max_lieferanten?: ?int, detail_schwelle?: int, user_id?: ?int}  $opt
     * @param  callable(string): void|null  $log
     * @return array{lauf_id: int, status: string, zaehler: array<string,mixed>}
     */
    public function lauf(int $verbindungId, array $opt = [], ?callable $log = null, ?NectaQuelle $quelle = null): array
    {
        $this->vorbereiten($verbindungId, $opt, $quelle);
        $log ??= fn (string $z) => null;
        $teamId = $opt['team_id'] ?? MasterTeam::id();

        [$this->laufId, $startIndex] = $this->laufBeginnen($verbindungId, $teamId, $opt);

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

    /** @param array{legacy_id:int, name:string, fa_id:?int} $l */
    private function lieferant(array $l, ?int $teamId, callable $log): void
    {
        // Artikel des Lieferanten seitenweise; nur fehlende legacy_ids anlegen
        $neu = [];   // legacy_id => fa_id (Probe: 0)
        $seite = 1;
        do {
            $antwort = $this->holen('supplier-items', $seite, ['supplierId' => $l['legacy_id']]);
            $items = $antwort['items'];
            $this->zaehler['artikel_geprueft'] += count($items);
            $ids = array_map(fn ($r) => (int) $r['id'], $items);
            $bekannt = $ids === [] ? [] : DB::table('foodalchemist_supplier_items')->whereIn('legacy_id', $ids)->pluck('legacy_id')->flip()->all();
            foreach ($items as $r) {
                if (isset($bekannt[(int) $r['id']]) || isset($neu[(int) $r['id']])) {
                    continue;
                }
                $neu[(int) $r['id']] = $this->artikelAnlegen($l, $r);
            }
            $seite++;
        } while ($seite <= max(1, $antwort['totalPages']) && $items !== []);

        $this->neueArtikelAbschliessen($l, $neu, $teamId, $log);
    }

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

        return [$this->laufAnlegen('nachholen', $verbindungId, $teamId, $opt), 0];
    }
}
