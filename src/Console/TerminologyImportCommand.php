<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistTerminologyAntiMarker;
use Platform\FoodAlchemist\Services\TerminologyService;

/**
 * P1 (Wissens-Skalierbarkeit, 2026-08-07): die Brücke Wissens-Doc → deterministische
 * Negativliste. Heute sind das cross_cutting-Doc „Anti-Marker" (Wissens-Browser) und die
 * Matcher-Negativliste (`TerminologyService`/`foodalchemist_terminology_anti_markers`) ZWEI
 * getrennte Welten — gepflegtes Anti-Marker-Wissen wirkt NICHT auf den Matcher. Dieser
 * Command liest die STRUKTURIERTE Gold-Tabelle des Docs (Spalten: String | Falsch-Match-Risiko
 * | …) deterministisch (KEIN LLM, keine Halluzination) und schlägt Anti-Marker-Regeln vor.
 *
 * Sicher by design: `--dry-run` (Default) zeigt nur die Review-Liste; `--apply` schreibt via
 * `TerminologyService::createAntiMarker(via='knowledge_import')` — Provenienz-getaggt (im Review
 * purgebar) und idempotent (bestehende trigger↛forbid werden übersprungen). Anti-Marker sind
 * HARTE Filter, darum ist die menschliche Sicht vor `--apply` Pflicht — die Extraktion ist ein
 * VORSCHLAG, kein Automatismus. Die Prosa-Listen („Per Domain konsolidiert") bleiben bewusst
 * ausgespart (nicht sicher deterministisch parsebar).
 */
class TerminologyImportCommand extends Command
{
    protected $signature = 'foodalchemist:terminology-import
        {--doc=anti_marker : slug des cross_cutting-Wissens-Docs mit der Gold-Tabelle}
        {--apply : schreibt die Vorschläge live in die Negativliste (Default: nur Review-Liste)}';

    protected $description = 'P1: Anti-Marker-Gold-Tabelle aus einem Wissens-Doc → deterministische Matcher-Negativliste (dry-run default)';

    public function handle(TerminologyService $terminology): int
    {
        $slug = (string) $this->option('doc');
        $doc = DB::table('foodalchemist_knowledge_documents')
            ->where('slug', $slug)->where('category', 'cross_cutting')->whereNull('deleted_at')
            ->first(['content_md']);
        if ($doc === null) {
            $this->error("Wissens-Doc [{$slug}] (cross_cutting) nicht gefunden.");

            return self::INVALID;
        }

        $vorschlaege = $this->parseAntiMarkers((string) $doc->content_md);
        if ($vorschlaege === []) {
            $this->warn('Keine Anti-Marker-Tabellenzeilen erkannt — nichts zu tun.');

            return self::SUCCESS;
        }

        // Bestand für Dedup (trigger+forbid, normalisiert).
        $bestand = [];
        foreach (FoodAlchemistTerminologyAntiMarker::query()->whereNull('deleted_at')->get(['trigger_token', 'forbid_token']) as $r) {
            $bestand[mb_strtolower(trim((string) $r->trigger_token)) . '|' . mb_strtolower(trim((string) $r->forbid_token))] = true;
        }

        $apply = (bool) $this->option('apply');
        $neu = 0;
        $skip = 0;
        $rows = [];
        foreach ($vorschlaege as $v) {
            $key = $v['trigger'] . '|' . $v['forbid'];
            $existiert = isset($bestand[$key]);
            $status = $existiert ? 'existiert' : ($apply ? 'angelegt' : 'neu');
            $rows[] = [$v['trigger'], '↛ ' . $v['forbid'], mb_strimwidth($v['note'], 0, 48, '…'), $status];
            if ($existiert) {
                $skip++;

                continue;
            }
            if ($apply) {
                $terminology->createAntiMarker($v['trigger'], $v['forbid'], null, $v['note'] ?: null, 'knowledge_import');
                $bestand[$key] = true;
            }
            $neu++;
        }

        $this->table(['Trigger', 'verbietet', 'Notiz', 'Status'], $rows);
        $this->info(sprintf('%d Vorschläge · %d %s · %d bereits vorhanden.',
            count($vorschlaege), $neu, $apply ? 'angelegt' : 'neu (dry-run)', $skip));
        if (! $apply && $neu > 0) {
            $this->warn('Dry-run — nichts geschrieben. Nach Sichtung mit --apply live setzen (via=knowledge_import, im Review purgebar).');
        }

        return self::SUCCESS;
    }

    /**
     * Reiner, deterministischer Parser (testbar) der Gold-Tabelle(n): findet Tabellen mit einer
     * „String"-Spalte + einer „Falsch-Match"/„Risiko"-Spalte und leitet je Datenzeile Anti-Marker
     * ab. trigger = String-Zelle (ohne **); forbid = je „/"-getrennter Risiko-Term auf sein
     * distinktivstes (letztes) Token reduziert, Klammer-Zusätze entfernt. note = „Wie unterscheiden".
     * Konservativ: leere/mehrdeutige Zellen werden übersprungen, nicht geraten.
     *
     * @return list<array{trigger:string, forbid:string, note:string}>
     */
    public function parseAntiMarkers(string $markdown): array
    {
        $out = [];
        $seen = [];
        $header = null;   // aktuelle Spalten-Zuordnung [triggerIdx, forbidIdx, noteIdx]

        foreach (preg_split('/\R/', $markdown) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] !== '|') {
                $header = null;                                   // Tabelle endet an der ersten Nicht-Tabellenzeile

                continue;
            }
            $cells = $this->cells($line);
            if ($cells === []) {
                continue;
            }
            // Trenn-Zeile |---|---| überspringen
            if (count(array_filter($cells, fn ($c) => $c !== '' && preg_replace('/[-:\s]/', '', $c) !== '')) === 0) {
                continue;
            }
            // Header erkennen: enthält „String" + „Falsch"/„Risiko"
            $lower = array_map('mb_strtolower', $cells);
            $tIdx = $this->firstIndex($lower, ['string']);
            $fIdx = $this->firstIndex($lower, ['falsch', 'risiko']);
            if ($tIdx !== null && $fIdx !== null) {
                $header = [$tIdx, $fIdx, $this->firstIndex($lower, ['unterscheiden', 'wie'])];

                continue;
            }
            if ($header === null) {
                continue;                                          // Datenzeile ohne erkannten Header → ignorieren
            }

            [$tI, $fI, $nI] = $header;
            $trigger = $this->clean($cells[$tI] ?? '');
            $riskCell = $cells[$fI] ?? '';
            $note = $nI !== null ? trim($cells[$nI] ?? '') : '';
            if ($trigger === '' || $riskCell === '') {
                continue;
            }

            foreach (explode('/', $riskCell) as $term) {
                $forbid = $this->lastToken($term);
                if ($forbid === '' || $forbid === $trigger || mb_strlen($forbid) < 3) {
                    continue;                                      // leer / identisch / zu kurz → nicht raten
                }
                $key = $trigger . '|' . $forbid;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $out[] = ['trigger' => $trigger, 'forbid' => $forbid, 'note' => $note];
            }
        }

        return $out;
    }

    /** @param list<string> $haystack @param list<string> $needles */
    private function firstIndex(array $haystack, array $needles): ?int
    {
        foreach ($haystack as $i => $cell) {
            foreach ($needles as $n) {
                if (str_contains($cell, $n)) {
                    return $i;
                }
            }
        }

        return null;
    }

    /** Zeile |a|b|c| → ['a','b','c'] (führendes/abschließendes | verworfen). */
    private function cells(string $line): array
    {
        $line = trim($line);
        $line = preg_replace('/^\|/', '', $line);
        $line = preg_replace('/\|$/', '', (string) $line);

        return array_map('trim', explode('|', (string) $line));
    }

    /** Zell-Inhalt → trigger-Token: **fett** weg, Klammer-Zusatz weg, lowercase, getrimmt. */
    private function clean(string $s): string
    {
        $s = str_replace('*', '', $s);
        $s = (string) preg_replace('/\(.*?\)/u', '', $s);

        return mb_strtolower(trim($s));
    }

    /** Risiko-Term → distinktivstes Token (letztes Alnum-Wort, Klammern weg). */
    private function lastToken(string $s): string
    {
        $s = $this->clean($s);
        $tokens = preg_split('/[^[:alnum:]]+/u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return $tokens === [] ? '' : (string) end($tokens);
    }
}
