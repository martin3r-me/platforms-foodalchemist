<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistTerminologyAntiMarker;
use Platform\FoodAlchemist\Services\TerminologyService;

/**
 * P1 + Move 2 (Wissens-Skalierbarkeit, 2026-08-07): die Brücke Wissens-Doc → deterministische
 * Terminologie. Heute sind die cross_cutting-Docs „Anti-Marker" / „Synonyme" (Wissens-Browser)
 * und die Matcher-Schichten (`TerminologyService`: Anti-Marker-Negativliste S2 + Alias-Gruppen S1)
 * ZWEI getrennte Welten — gepflegtes Wissen wirkt NICHT auf den Matcher. Dieser Command liest die
 * STRUKTURIERTEN Tabellen der Docs deterministisch (KEIN LLM, keine Halluzination) und schlägt
 * Regeln vor.
 *
 * Bewusst ADDITIV: die Docs bleiben im Generator-Grounding (das laut Golden-Eval funktioniert);
 * hier kommt nur die deterministische Wirkung DAZU — kein Eingriff ins Prompt-Grounding, also
 * kein Qualitätsrisiko. `--kind=anti_marker` (Default) liest die Gold-Tabelle (String | Falsch-
 * Match-Risiko | …), `--kind=alias` die Synonym-Tabellen (Begriff | gleichbedeutend mit | …).
 *
 * Sicher: `--dry-run` (Default) zeigt nur die Review-Liste; `--apply` schreibt via TerminologyService
 * (`createAntiMarker`/`createAlias`, via='knowledge_import') — provenienz-getaggt (im Review purgebar)
 * und idempotent (Dedup). Anti-Marker/Aliasse wirken HART → menschliche Sicht vor `--apply` Pflicht;
 * die Extraktion ist ein VORSCHLAG. Prosa-Listen bleiben ausgespart (nicht sicher deterministisch).
 */
class TerminologyImportCommand extends Command
{
    protected $signature = 'foodalchemist:terminology-import
        {--kind=anti_marker : was importieren — anti_marker | alias}
        {--doc= : slug des cross_cutting-Docs (Default je Kind: anti_marker bzw. synonyme)}
        {--apply : schreibt die Vorschläge live in die Terminologie (Default: nur Review-Liste)}';

    protected $description = 'P1/Move2: Anti-Marker- bzw. Synonym-Tabellen aus einem Wissens-Doc → deterministische Terminologie (dry-run default)';

    public function handle(TerminologyService $terminology): int
    {
        $kind = (string) $this->option('kind');
        if (! in_array($kind, ['anti_marker', 'alias'], true)) {
            $this->error("--kind muss anti_marker oder alias sein (nicht [{$kind}]).");

            return self::INVALID;
        }
        $slug = (string) ($this->option('doc') ?: ($kind === 'alias' ? 'synonyme' : 'anti_marker'));
        $doc = DB::table('foodalchemist_knowledge_documents')
            ->where('slug', $slug)->where('category', 'cross_cutting')->whereNull('deleted_at')
            ->first(['content_md']);
        if ($doc === null) {
            $this->error("Wissens-Doc [{$slug}] (cross_cutting) nicht gefunden.");

            return self::INVALID;
        }

        $apply = (bool) $this->option('apply');

        return $kind === 'alias'
            ? $this->importAliases($terminology, (string) $doc->content_md, $apply)
            : $this->importAntiMarkers($terminology, (string) $doc->content_md, $apply);
    }

    private function importAntiMarkers(TerminologyService $terminology, string $md, bool $apply): int
    {
        $vorschlaege = $this->parseAntiMarkers($md);
        if ($vorschlaege === []) {
            $this->warn('Keine Anti-Marker-Tabellenzeilen erkannt — nichts zu tun.');

            return self::SUCCESS;
        }

        $bestand = [];
        foreach (FoodAlchemistTerminologyAntiMarker::query()->whereNull('deleted_at')->get(['trigger_token', 'forbid_token']) as $r) {
            $bestand[mb_strtolower(trim((string) $r->trigger_token)) . '|' . mb_strtolower(trim((string) $r->forbid_token))] = true;
        }

        $neu = $skip = 0;
        $rows = [];
        foreach ($vorschlaege as $v) {
            $existiert = isset($bestand[$v['trigger'] . '|' . $v['forbid']]);
            $rows[] = [$v['trigger'], '↛ ' . $v['forbid'], mb_strimwidth($v['note'], 0, 46, '…'), $existiert ? 'existiert' : ($apply ? 'angelegt' : 'neu')];
            if ($existiert) {
                $skip++;

                continue;
            }
            if ($apply) {
                $terminology->createAntiMarker($v['trigger'], $v['forbid'], null, $v['note'] ?: null, 'knowledge_import');
                $bestand[$v['trigger'] . '|' . $v['forbid']] = true;
            }
            $neu++;
        }
        $this->table(['Trigger', 'verbietet', 'Notiz', 'Status'], $rows);

        return $this->quittung('Anti-Marker', count($vorschlaege), $neu, $skip, $apply);
    }

    private function importAliases(TerminologyService $terminology, string $md, bool $apply): int
    {
        $gruppen = $this->parseAliases($md);
        if ($gruppen === []) {
            $this->warn('Keine Synonym-Tabellenzeilen erkannt — nichts zu tun.');

            return self::SUCCESS;
        }

        // Bestand: bestehende Gruppen als sortierter Member-Key (reihenfolge-unabhängig).
        $bestand = [];
        foreach ($terminology->aliasGroups() as $g) {
            $bestand[$this->groupKey($g)] = true;
        }

        $neu = $skip = 0;
        $rows = [];
        foreach ($gruppen as $g) {
            $existiert = isset($bestand[$this->groupKey($g)]);
            $rows[] = [implode(' = ', array_slice($g, 0, 4)) . (count($g) > 4 ? ' …' : ''), $existiert ? 'existiert' : ($apply ? 'angelegt' : 'neu')];
            if ($existiert) {
                $skip++;

                continue;
            }
            if ($apply) {
                $terminology->createAlias($g, null, 'knowledge_import');
                $bestand[$this->groupKey($g)] = true;
            }
            $neu++;
        }
        $this->table(['Alias-Gruppe', 'Status'], $rows);

        return $this->quittung('Alias-Gruppen', count($gruppen), $neu, $skip, $apply);
    }

    private function quittung(string $was, int $gesamt, int $neu, int $skip, bool $apply): int
    {
        $this->info(sprintf('%d %s · %d %s · %d bereits vorhanden.',
            $gesamt, $was, $neu, $apply ? 'angelegt' : 'neu (dry-run)', $skip));
        if (! $apply && $neu > 0) {
            $this->warn('Dry-run — nichts geschrieben. Nach Sichtung mit --apply live setzen (via=knowledge_import, im Review purgebar).');
        }

        return self::SUCCESS;
    }

    /**
     * Reiner Parser (testbar) der Anti-Marker-Gold-Tabelle(n): Tabellen mit „String"- + „Falsch-
     * Match"/„Risiko"-Spalte → je Datenzeile trigger (String, ohne **) ↛ forbid (je „/"-Term auf
     * sein letztes Token reduziert, Klammern weg). note = „Wie unterscheiden".
     *
     * @return list<array{trigger:string, forbid:string, note:string}>
     */
    public function parseAntiMarkers(string $markdown): array
    {
        $out = [];
        $seen = [];
        $header = null;

        foreach ($this->tableRows($markdown) as $row) {
            if ($row['cells'] === []) {                          // Tabellen-Ende (Leerzeile/Überschrift) → Header-Kontext schließen
                $header = null;

                continue;
            }
            if ($row['header'] !== null) {
                $lower = $row['header'];
                $t = $this->firstIndex($lower, ['string']);
                $f = $this->firstIndex($lower, ['falsch', 'risiko']);
                $header = ($t !== null && $f !== null) ? [$t, $f, $this->firstIndex($lower, ['unterscheiden', 'wie'])] : null;

                continue;
            }
            if ($header === null) {
                continue;
            }
            [$tI, $fI, $nI] = $header;
            $trigger = $this->clean($row['cells'][$tI] ?? '');
            $riskCell = $row['cells'][$fI] ?? '';
            $note = $nI !== null ? trim($row['cells'][$nI] ?? '') : '';
            if ($trigger === '' || $riskCell === '') {
                continue;
            }
            foreach (explode('/', $riskCell) as $term) {
                $forbid = $this->lastToken($term);
                if ($forbid === '' || $forbid === $trigger || mb_strlen($forbid) < 3) {
                    continue;
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

    /**
     * Reiner Parser (testbar) der Synonym-Tabelle(n): Tabellen mit „Begriff"- + „gleichbedeutend"/
     * „Synonym"-Spalte → Alias-Gruppe [Begriff, …Synonyme] (Synonyme an „/" und „," gesplittet,
     * Klammern/** weg, lowercase). Nur Gruppen mit ≥2 verschiedenen Gliedern; reihenfolge-unabh. dedupliziert.
     *
     * @return list<list<string>>
     */
    public function parseAliases(string $markdown): array
    {
        $out = [];
        $seen = [];
        $header = null;

        foreach ($this->tableRows($markdown) as $row) {
            if ($row['cells'] === []) {                          // Tabellen-Ende (Leerzeile/Überschrift) → Header-Kontext schließen
                $header = null;

                continue;
            }
            if ($row['header'] !== null) {
                $lower = $row['header'];
                $b = $this->firstIndex($lower, ['begriff']);
                $s = $this->firstIndex($lower, ['gleichbedeutend', 'synonym', 'variante']);
                $header = ($b !== null && $s !== null) ? [$b, $s] : null;

                continue;
            }
            if ($header === null) {
                continue;
            }
            [$bI, $sI] = $header;
            $begriff = $this->clean($row['cells'][$bI] ?? '');
            $synCell = $row['cells'][$sI] ?? '';
            if ($begriff === '' || $synCell === '') {
                continue;
            }
            $members = [$begriff];
            foreach (preg_split('#[/,]#', $synCell) ?: [] as $syn) {
                $m = $this->clean($syn);
                if ($m !== '' && mb_strlen($m) >= 2) {
                    $members[] = $m;
                }
            }
            $members = array_values(array_unique($members));
            if (count($members) < 2) {
                continue;
            }
            $key = $this->groupKey($members);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $members;
        }

        return $out;
    }

    /**
     * Markdown-Tabellenzeilen als Strom: jede Zeile ist entweder ein Header (lowercased Zellen,
     * für die Spalten-Zuordnung) oder eine Datenzeile (Zellen). Trenn-/Nicht-Tabellenzeilen
     * setzen den Kontext zurück (header=null), sodass eine Tabelle an der ersten Nicht-|-Zeile endet.
     *
     * @return list<array{header: ?list<string>, cells: list<string>}>
     */
    private function tableRows(string $markdown): array
    {
        $rows = [];
        foreach (preg_split('/\R/', $markdown) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] !== '|') {
                $rows[] = ['header' => null, 'cells' => []];    // Reset-Marker (Tabellen-Ende)

                continue;
            }
            $cells = $this->cells($line);
            if ($cells === [] || count(array_filter($cells, fn ($c) => preg_replace('/[-:\s]/', '', $c) !== '')) === 0) {
                continue;                                       // Trenn-Zeile |---|
            }
            $lower = array_map('mb_strtolower', $cells);
            // Header, wenn eine der bekannten Spalten-Überschriften vorkommt.
            $istHeader = $this->firstIndex($lower, ['string', 'begriff']) !== null;
            $rows[] = $istHeader ? ['header' => $lower, 'cells' => $cells] : ['header' => null, 'cells' => $cells];
        }

        return $rows;
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

    /** @return list<string> */
    private function cells(string $line): array
    {
        $line = (string) preg_replace('/\|$/', '', (string) preg_replace('/^\|/', '', trim($line)));

        return array_map('trim', explode('|', $line));
    }

    private function clean(string $s): string
    {
        $s = str_replace('*', '', $s);
        $s = (string) preg_replace('/\(.*?\)/u', '', $s);

        return mb_strtolower(trim($s));
    }

    private function lastToken(string $s): string
    {
        $tokens = preg_split('/[^[:alnum:]]+/u', $this->clean($s), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return $tokens === [] ? '' : (string) end($tokens);
    }

    /** Reihenfolge-unabhängiger Schlüssel einer Alias-Gruppe (für Dedup). @param list<string> $g */
    private function groupKey(array $g): string
    {
        $n = array_values(array_unique(array_map(fn ($m) => mb_strtolower(trim((string) $m)), $g)));
        sort($n);

        return implode('|', $n);
    }
}
