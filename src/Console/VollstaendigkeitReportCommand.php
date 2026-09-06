<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\VollstaendigkeitReportService;

/**
 * Spec 50 · Etappe 0 — Messbericht Vollständigkeit. **Read-only**, schreibt nichts.
 *
 * Die Baseline VOR den Paketen A–C. Ohne sie ist „vollständiger angereichert" hinterher eine
 * Behauptung, und die Kostenkurve der Entscheidung „automatisch vollständig anreichern"
 * bleibt unsichtbar. Zweimal laufen lassen: einmal jetzt, einmal nach Etappe 6 — die
 * `--json`-Ausgabe ist für genau diesen Vergleich da.
 */
class VollstaendigkeitReportCommand extends Command
{
    protected $signature = 'foodalchemist:vollstaendigkeit-report
        {--team= : nur dieses Team (ID), sonst alle}
        {--limit=5 : wie viele Beispiel-Zeilen je Block}
        {--json : Maschinen-lesbare Ausgabe statt Tabellen}';

    protected $description = 'Messbericht Vollständigkeit (VK-Vorbedingungen, Anreicherung, GP, Concept-Header, KI-Kosten) — read-only.';

    public function handle(VollstaendigkeitReportService $report): int
    {
        $teams = $this->option('team')
            ? Team::whereKey((int) $this->option('team'))->get()
            : Team::query()->get();

        if ($teams->isEmpty()) {
            $this->error('Kein Team gefunden (--team=ID prüfen).');

            return self::FAILURE;
        }

        $limit = max(0, (int) $this->option('limit'));
        $alle = [];

        foreach ($teams as $team) {
            $mess = $report->messe($team, $limit);
            $alle[$team->id] = $mess;

            if ($this->option('json')) {
                continue;
            }

            $this->info("── Team {$team->id} ({$team->name}) ──");
            $this->zeigeA($mess['a_vk_vorbedingungen']);
            $this->zeigeB($mess['b_anreicherung']);
            $this->zeigeC($mess['c_grundprodukte']);
            $this->zeigeD($mess['d_concept_struktur']);
            $this->zeigeE($mess['e_ki_kosten']);
        }

        if ($this->option('json')) {
            $this->line(json_encode($alle, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        return self::SUCCESS;
    }

    private function zeigeA(array $a): void
    {
        $this->line('  A · VK-Vorbedingungen — was den Auto-VK blockiert');
        $this->table(['Kennzahl', 'Wert'], [
            ['VK-Gerichte', $a['vk_gerichte']],
            ['ohne Aufschlagsklasse', $this->mitQuote($a['ohne_aufschlagsklasse'], $a['vk_gerichte'])],
            ['ohne Portion am Rezept', $this->mitQuote($a['ohne_portion_am_rezept'], $a['vk_gerichte'])],
            ['ohne jede Darreichung', $this->mitQuote($a['ohne_darreichung'], $a['vk_gerichte'])],
            ['Darreichungen ohne is_standard', $a['darreichung_ohne_standard']],
            ['Standard steht auf „unbestimmt" (Review)', $a['standard_auf_unbestimmt']],
            ['ohne VK', $this->mitQuote($a['ohne_vk'], $a['vk_gerichte'])],
        ]);

        if ($a['beispiele_ohne_klasse'] !== []) {
            $this->table(['ID', 'Name', 'Portion g', 'VK netto'],
                array_map(fn ($b) => [
                    $b['recipe_id'], $this->kurz($b['name']),
                    $b['portion_g'] ?? '–', $b['vk_netto_eur'] ?? '–',
                ], $a['beispiele_ohne_klasse']));
        }
    }

    private function zeigeB(array $b): void
    {
        $this->line('  B · Anreicherung — leere Zielfelder der Schrittfolgen');
        $zeilen = [['Basisrezepte', $b['basisrezept']['gesamt']]];
        foreach ($b['basisrezept'] as $k => $v) {
            if ($k !== 'gesamt') {
                $zeilen[] = ["  {$k}", $this->mitQuote($v, $b['basisrezept']['gesamt'])];
            }
        }
        $zeilen[] = ['Gerichte', $b['gericht']['gesamt']];
        foreach ($b['gericht'] as $k => $v) {
            if ($k !== 'gesamt') {
                $zeilen[] = ["  {$k}", $this->mitQuote($v, $b['gericht']['gesamt'])];
            }
        }
        $this->table(['Kennzahl', 'Wert'], $zeilen);

        $this->line('    Felder OHNE Schrittfolge (tragen trotzdem eine Kette):');
        $this->table(['Feld', 'leer'], array_map(
            fn ($k, $v) => [$k, $v],
            array_keys($b['ohne_schrittfolge']), array_values($b['ohne_schrittfolge'])
        ));

        $this->line('    Coverage-Glieder (Rezepte ohne eine einzige Zeile):');
        $this->table(['Glied', 'Rezepte'], array_map(
            fn ($k, $v) => [$k, $v],
            array_keys($b['coverage']), array_values($b['coverage'])
        ));
    }

    private function zeigeC(array $c): void
    {
        $this->line('  C · Grundprodukte — Felder ohne LA-Quelle + LA-Kaskaden-Schaden');
        $this->table(['Kennzahl', 'Wert'], [
            ['GPs', $c['gps']],
            ['davon tentative', $c['tentative']],
            ['ohne Aroma-Anker (gp.anker ist eine Waise)', $this->mitQuote($c['ohne_aromaanker'], $c['gps'])],
            ['ohne Food-Domain', $this->mitQuote($c['ohne_food_domain'], $c['gps'])],
            ['⚠ KI-abgeschirmt TROTZ LA-Profil (A8)', $c['ki_abgeschirmt_trotz_la_profil']],
        ]);

        if ($c['beispiele_abgeschirmt'] !== []) {
            $this->line('    Diese GPs heilen NICHT mehr mit, wenn der LA korrigiert wird:');
            $this->table(['GP-ID', 'Name'],
                array_map(fn ($g) => [$g['gp_id'], $this->kurz($g['name'])], $c['beispiele_abgeschirmt']));
        }
    }

    private function zeigeD(array $d): void
    {
        $this->line('  D · Concept-Struktur — gerenderte Überschriften');
        $this->table(['Kennzahl', 'Wert'], [
            ['Concepts', $d['concepts']],
            ['ohne gerenderten Header', $this->mitQuote($d['ohne_gerenderten_header'], $d['concepts'])],
            ['davon MIT role statt header (Struktur da, kommt nicht an)', $d['davon_mit_rolle_statt_header']],
        ]);

        if ($d['beispiele'] !== []) {
            $this->table(['Concept-ID', 'Name'],
                array_map(fn ($c) => [$c['concept_id'], $this->kurz($c['name'])], $d['beispiele']));
        }
    }

    private function zeigeE(array $e): void
    {
        $this->line('  E · KI-Kosten — Basis für den Vor/Nach-Vergleich');
        $this->table(['Kennzahl', 'Wert'], [
            ['Calls gesamt', $e['calls_gesamt']],
            ['Tokens gesamt', $e['tokens_gesamt']],
        ]);

        if ($e['je_feature'] !== []) {
            $this->table(['Feature', 'Calls', 'Tokens', 'angenommen', 'verworfen', 'offen'],
                array_map(fn ($f) => [
                    $f['feature'], $f['calls'], $f['tokens'],
                    $f['angenommen'], $f['verworfen'], $f['offen'],
                ], array_slice($e['je_feature'], 0, 15)));
        }
    }

    private function mitQuote(int $wert, int $gesamt): string
    {
        return $gesamt > 0 ? sprintf('%d (%.1f %%)', $wert, $wert / $gesamt * 100) : (string) $wert;
    }

    private function kurz(string $name): string
    {
        return mb_strlen($name) > 38 ? mb_substr($name, 0, 37).'…' : $name;
    }
}
