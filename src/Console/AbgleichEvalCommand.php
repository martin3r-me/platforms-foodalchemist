<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\GpAbgleich\GpAbgleichService;
use Platform\FoodAlchemist\Services\GpAbgleich\GpKandidatenSucher;
use Platform\FoodAlchemist\Support\FaTeam;
use Platform\FoodAlchemist\Support\MasterTeam;

/**
 * foodalchemist:abgleich-eval (Entscheid Dominique 25.10.2026): den Abgleich-Dienst an ausgewählten Lieferantenartikeln laufen lassen und je Artikel
 * Entscheidung + Begründung für die Bewertung ausgeben. SCHREIBT NIE: jeder Artikel läuft in einer Transaktion, die zurückgerollt wird; es gibt weder Log noch
 * Verknüpfung noch neues GP. Die bestehende Verknüpfung des Artikels wird bewusst ignoriert (Vergleich „heute“ ↔ „neu“).
 *
 * Eingabe: `--csv` (Spalten artikel_id, optional bezeichnung/heute_gp_id/heute_gp_name/gruppe, Trenner ;) oder `--ids=1,2,3`. Die Artikel-IDs sind die IDs der
 * Datenbank, auf der der Befehl läuft (io).
 */
class AbgleichEvalCommand extends Command
{
    protected $signature = 'foodalchemist:abgleich-eval
        {--csv= : CSV mit Spalte artikel_id (Trenner ;), optional gruppe, heute_gp_id, heute_gp_name}
        {--ids= : Komma-Liste von Artikel-IDs (statt --csv)}
        {--team= : Team-ID (Standard: konfiguriertes Master-Team)}
        {--modus=vorschlag : vorschlag | auto (zeigt nur, was die Automatik täte; schreibt nie)}
        {--ohne-ki : nur die deterministischen Stufen S0–S5, kein KI-Call}
        {--out= : Ausgabeordner (Standard storage/app/abgleich-eval/<Datum>)}';

    protected $description = 'Abgleich-Dienst an ausgewählten Artikeln prüfen (schreibt nie, Transaktion wird zurückgerollt)';

    public function handle(GpAbgleichService $dienst): int
    {
        $team = $this->option('team') !== null ? Team::find((int) $this->option('team')) : (MasterTeam::id() !== null ? Team::find(MasterTeam::id()) : null);
        if ($team === null) {
            $this->error('Kein Team: --team=N angeben oder Master-Team konfigurieren.');

            return self::FAILURE;
        }
        $zeilen = $this->eingabe();
        if ($zeilen === []) {
            $this->error('Keine Artikel: --csv=… oder --ids=… angeben.');

            return self::FAILURE;
        }
        $ki = ! $this->option('ohne-ki');
        $out = (string) ($this->option('out') ?: storage_path('app/abgleich-eval/' . now()->format('Y-m-d_His')));
        File::ensureDirectoryExists($out);

        $kopf = ['gruppe', 'artikel_id', 'bezeichnung', 'zutaten_kurz', 'heute_gp_id', 'heute_gp_name', 'entscheidung', 'gp_id', 'gp_name', 'sicherheit', 'automatisch',
            'begruendung', 'abweichungen', 'top3_kandidaten', 'merkmals_diff_sieger', 'guard', 'felder', 'stufe', 'ki_calls', 'tokens_in', 'tokens_out',
            'dauer_ms', 'vergleich_automatisch', 'heute_gp_diagnose', 'urteil_dominique', 'kommentar_dominique'];
        $rows = [];
        $summe = ['kosten_calls' => 0, 'tokens_in' => 0, 'tokens_out' => 0, 'dauer_ms' => 0, 'n' => 0];
        $zaehler = [];

        foreach ($zeilen as $z) {
            $la = FoodAlchemistSupplierItem::visibleToTeam($team)->find((int) $z['artikel_id']);
            if ($la === null) {
                $rows[] = array_merge($this->leer($kopf), ['gruppe' => $z['gruppe'] ?? '', 'artikel_id' => $z['artikel_id'], 'entscheidung' => 'FEHLER: Artikel nicht gefunden/sichtbar']);

                continue;
            }
            try {
                // Absicherung: alles in einer Transaktion, die zurückgerollt wird — der Dienst schreibt in dieser Betriebsart ohnehin nichts (außer ai_call_log, s. u.).
                // KI-Aufrufe gehören IMMER ins ai_call_log (Kostenlog, Monatsdeckel, Abrechnung): der Rollback würde sie verschlucken. Deshalb werden genau die vom
                // Abgleich erzeugten Zeilen (IDs aus dem Ergebnis) VOR dem Rollback gelesen und NACH dem Rollback neu geschrieben — alles andere bleibt zurückgerollt.
                $logZeilen = [];
                $diag = '';
                $heuteId = ($z['heute_gp_id'] ?? '') !== '' ? (int) $z['heute_gp_id'] : null;
                $e = FaTeam::imTeam($team, function () use ($dienst, $team, $la, $ki, &$logZeilen, &$diag, $heuteId) {
                    DB::beginTransaction();
                    try {
                        $ergebnis = $dienst->entscheide($team, $la, ['ki' => $ki, 'ignoriere_verknuepfung' => true]);
                        if ($heuteId !== null && $ergebnis->felder !== []) {
                            // „Warum nicht das heutige GP?“ — Recall, harter Filter, Diff, Guard, Rang des heutigen GP (nur lesend)
                            $diag = app(GpKandidatenSucher::class)->diagnose($team, $la, $ergebnis->felder, $heuteId);
                        }
                        $logZeilen = $ergebnis->kiLogIds === [] ? [] : DB::table('foodalchemist_ai_call_log')->whereIn('id', $ergebnis->kiLogIds)->get()->map(fn ($r) => (array) $r)->all();

                        return $ergebnis;
                    } finally {
                        DB::rollBack();
                    }
                });
                foreach ($logZeilen as $zeile) {
                    unset($zeile['id']);
                    DB::table('foodalchemist_ai_call_log')->insert($zeile);
                }
            } catch (\Throwable $x) {
                $rows[] = array_merge($this->leer($kopf), ['gruppe' => $z['gruppe'] ?? '', 'artikel_id' => $z['artikel_id'], 'bezeichnung' => $la->designation, 'entscheidung' => 'FEHLER: ' . $x->getMessage()]);

                continue;
            }
            $gp = $e->gpId !== null ? FoodAlchemistGp::visibleToTeam($team)->find($e->gpId) : null;
            $sieger = collect($e->kandidaten)->firstWhere('gp_id', $e->gpId);
            $top3 = collect($e->kandidaten)->take(3)->map(fn ($c) => "#{$c['gp_id']} {$c['name']} (Rang {$c['rang']})")->implode(' | ');
            $heute = ($z['heute_gp_id'] ?? '') !== '' ? (int) $z['heute_gp_id'] : null;
            $vergleich = '';
            if ($heute !== null) {
                $vergleich = $e->aktion === 'verknuepfen' ? ($e->gpId === $heute ? 'gleich' : 'anderes_GP') : ($e->aktion === 'neu' ? 'neu_vorgeschlagen' : $e->aktion);
            }
            $rows[] = [
                'gruppe' => $z['gruppe'] ?? '', 'artikel_id' => $la->id, 'bezeichnung' => $la->designation, 'zutaten_kurz' => mb_substr((string) $la->ingredients_supplier, 0, 90),
                'heute_gp_id' => $heute ?? '', 'heute_gp_name' => $z['heute_gp_name'] ?? '',
                'entscheidung' => $e->aktion, 'gp_id' => $e->gpId ?? '', 'gp_name' => $gp?->name ?? '', 'sicherheit' => round($e->sicherheit, 2),
                'automatisch' => ($this->option('modus') === 'auto' && $e->automatisch) ? 'ja (würde automatisch verknüpft)' : ($e->automatisch ? 'ja' : 'nein'),
                'begruendung' => $e->begruendung, 'abweichungen' => implode('; ', $e->abweichungen), 'top3_kandidaten' => $top3,
                'merkmals_diff_sieger' => $sieger ? implode('; ', $sieger['diff_abweichungen']) : '', 'guard' => $sieger ? implode('; ', $sieger['guard']) : '',
                'felder' => collect($e->felder)->only(['hauptzutat', 'flavor', 'zusatz', 'condition', 'processing', 'form', 'commodity_group_code'])->filter()->map(fn ($v, $k) => "$k=$v")->implode(', '),
                'stufe' => $e->stufe, 'ki_calls' => $e->kiCalls, 'tokens_in' => $e->tokensIn, 'tokens_out' => $e->tokensOut, 'dauer_ms' => $e->dauerMs,
                'vergleich_automatisch' => $vergleich, 'heute_gp_diagnose' => $diag, 'urteil_dominique' => '', 'kommentar_dominique' => '',
            ];
            $summe['kosten_calls'] += $e->kiCalls;
            $summe['tokens_in'] += $e->tokensIn;
            $summe['tokens_out'] += $e->tokensOut;
            $summe['dauer_ms'] += $e->dauerMs;
            $summe['n']++;
            $zaehler[$e->aktion] = ($zaehler[$e->aktion] ?? 0) + 1;
            $this->line(sprintf('#%d %s → %s%s (%.2f)', $la->id, mb_substr((string) $la->designation, 0, 40), $e->aktion, $e->gpId ? " #{$e->gpId}" : '', $e->sicherheit));
        }

        $this->schreibeCsv($out . '/abgleich_eval.csv', $kopf, $rows);
        File::put($out . '/abgleich_eval.md', $this->markdown($rows, $summe, $zaehler));
        $this->info("Eval fertig: {$summe['n']} Artikel, {$summe['kosten_calls']} KI-Calls, {$summe['tokens_in']}/{$summe['tokens_out']} Tokens (ein/aus). Ausgabe: {$out}");

        return self::SUCCESS;
    }

    /** @return list<array<string,string>> */
    private function eingabe(): array
    {
        if ($this->option('ids') !== null && trim((string) $this->option('ids')) !== '') {
            return array_map(fn ($i) => ['artikel_id' => (string) (int) $i], array_filter(explode(',', (string) $this->option('ids'))));
        }
        $csv = (string) $this->option('csv');
        if ($csv === '' || ! is_file($csv)) {
            return [];
        }
        $h = fopen($csv, 'rb');
        $kopf = null;
        $out = [];
        while (($z = fgetcsv($h, 0, ';', '"', '')) !== false) {
            if ($kopf === null) {
                $kopf = array_map(fn ($k) => trim(ltrim($k, "\xEF\xBB\xBF")), $z);

                continue;
            }
            $z = array_pad($z, count($kopf), '');
            $d = array_combine($kopf, array_slice($z, 0, count($kopf)));
            if (($d['artikel_id'] ?? '') !== '') {
                $out[] = $d;
            }
        }
        fclose($h);

        return $out;
    }

    /** @param list<string> $kopf @return array<string,string> */
    private function leer(array $kopf): array
    {
        return array_fill_keys($kopf, '');
    }

    /** @param list<string> $kopf @param list<array<string,mixed>> $rows */
    private function schreibeCsv(string $pfad, array $kopf, array $rows): void
    {
        $h = fopen($pfad, 'wb');
        fwrite($h, "\xEF\xBB\xBF");
        fputcsv($h, $kopf, ';', '"', '');
        foreach ($rows as $r) {
            fputcsv($h, array_map(fn ($k) => (string) ($r[$k] ?? ''), $kopf), ';', '"', '');
        }
        fclose($h);
    }

    /** @param list<array<string,mixed>> $rows @param array<string,int> $s @param array<string,int> $zaehler */
    private function markdown(array $rows, array $s, array $zaehler): string
    {
        $md = "# Abgleich-Eval\n\n";
        $md .= "- Artikel: {$s['n']} · Entscheidungen: " . collect($zaehler)->map(fn ($n, $k) => "{$k} {$n}")->implode(', ') . "\n";
        $md .= "- KI: {$s['kosten_calls']} Calls, {$s['tokens_in']} Tokens ein / {$s['tokens_out']} aus · Dauer gesamt " . round($s['dauer_ms'] / 1000, 1) . " s\n";
        $md .= "- Kosten in Cent: aus den Tokens mit dem Tarif der KI-Klasse „mittel“ berechnen (Settings › KI); der Befehl schätzt nicht.\n\n";
        foreach ($rows as $r) {
            $md .= "## #{$r['artikel_id']} {$r['bezeichnung']}\n";
            $md .= ($r['heute_gp_id'] !== '' ? "- Heute: GP #{$r['heute_gp_id']} {$r['heute_gp_name']}\n" : '');
            $md .= "- Vorschlag: **{$r['entscheidung']}**" . ($r['gp_id'] !== '' ? " → #{$r['gp_id']} {$r['gp_name']}" : '') . " (Sicherheit {$r['sicherheit']}, automatisch: {$r['automatisch']})"
                . ($r['vergleich_automatisch'] !== '' ? " — Vergleich: {$r['vergleich_automatisch']}" : '') . "\n";
            $md .= "- Begründung: {$r['begruendung']}\n";
            $md .= ($r['abweichungen'] !== '' ? "- Abweichungen: {$r['abweichungen']}\n" : '');
            $md .= ($r['top3_kandidaten'] !== '' ? "- Kandidaten: {$r['top3_kandidaten']}\n" : '');
            $md .= ($r['felder'] !== '' ? "- Felder: {$r['felder']}\n" : '');
            $md .= "- Bewertung Dominique: richtig / falsch / unklar — Kommentar:\n\n";
        }

        return $md;
    }
}
