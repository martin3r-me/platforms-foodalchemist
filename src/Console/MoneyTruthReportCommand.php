<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\MoneyTruthReportService;

/**
 * Spec 22 · H2a — Messbericht Geld-Wahrheiten. **Read-only**, schreibt nichts.
 *
 * Die Etappe 22·H2 verlangt „Messung zuerst, Umbau danach": erst wenn die drei Zahlen
 * auf dem Tisch liegen (V-041 · V-046/V-059 · V-053), ist entscheidbar, ob der jeweilige
 * Befund ein stiller Dauerfehler oder ein Randfall ist — und damit, welcher Umbau die
 * Mühe wert ist. Deshalb steht dieses Kommando VOR den Umbau-Chunks von H2, nicht daneben.
 *
 * Absichtlich kein Signal, keine Zeitreihe, keine `bulk_runs`-Zeile: das ist eine Messung
 * für einen Menschen, kein Detektor. Wird sie später zur Ampel-Metrik, gehört sie in
 * `DataQualityService` — dann aber mit dem dortigen Prädikat, nicht als zweite Wahrheit.
 */
class MoneyTruthReportCommand extends Command
{
    protected $signature = 'foodalchemist:money-truth-report
        {--team= : nur dieses Team (ID), sonst alle}
        {--limit=5 : wie viele Beispiel-Zeilen je Block}
        {--json : Maschinen-lesbare Ausgabe statt Tabellen}';

    protected $description = 'Messbericht der Geld-Wahrheiten (sales_unit_count, Preis-Leiter, Lead-LA-Preis) — read-only.';

    public function handle(MoneyTruthReportService $report): int
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
            $this->zeigeA($mess['a_sales_unit_count']);
            $this->zeigeB($mess['b_preis_wahrheit']);
            $this->zeigeC($mess['c_lead_la_preis']);
        }

        if ($this->option('json')) {
            $this->line(json_encode($alle, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        return self::SUCCESS;
    }

    private function zeigeA(array $a): void
    {
        $this->line('  A · sales_unit_count — zwei Lesarten derselben Spalte (V-041)');
        $this->table(['Kennzahl', 'Wert'], [
            ['VK-Gerichte', $a['vk_gerichte']],
            ['davon sales_unit_count NULL', $a['verteilung']['unit_count_null']],
            ['davon sales_unit_count <= 1', $a['verteilung']['unit_count_le_1']],
            ['davon sales_unit_count > 1', $a['verteilung']['unit_count_gt_1']],
            ['vergleichbar (EK + Darreichungs-EK + VK)', $a['vergleichbar']],
            ['Quoten weichen ab · unit_count > 1', $a['abweichend']['unit_count_gt_1']],
            ['Quoten weichen ab · unit_count <= 1', $a['abweichend']['unit_count_le_1']],
            ['Faktor Darreichung/Division (min/median/max)',
                $this->drei($a['faktor_min'], $a['faktor_median'], $a['faktor_max'])],
        ]);

        if ($a['beispiele'] !== []) {
            $this->table(
                ['ID', 'Name', 'unit_count', 'EK ges.', 'EK Portion', 'VK', 'W% Div.', 'W% Darr.', 'Δ pp', 'Faktor'],
                array_map(fn ($b) => [
                    $b['recipe_id'], $this->kurz($b['name']), $b['sales_unit_count'], $b['ek_total_eur'],
                    $b['ek_portion_eur'], $b['vk_netto_eur'], $b['w_pct_division'],
                    $b['w_pct_darreichung'], $b['delta_pp'], $b['faktor'] ?? '–',
                ], $a['beispiele'])
            );
        }
    }

    private function zeigeB(array $b): void
    {
        $this->line('  B · Preis-Leiter — welche Zahl gilt (V-046 / V-059)');
        $this->table(['Kennzahl', 'Wert'], [
            ['VK-Gerichte', $b['vk_gerichte']],
            ['mit Standard-Darreichung', $b['mit_standard_darreichung']],
            ['ohne jede Darreichung', $b['ohne_darreichung']],
            ['Darreichungen ohne is_standard (V-059)', $b['darreichungen_ohne_standard']],
            ['Darreichungs-Preis ≠ Rezept-Preis', $b['preis_divergenz']],
            ['nur Legacy-Preis (Leiter fällt zurück)', $b['nur_legacy_preis']],
            ['gar kein VK', $b['kein_preis']],
        ]);

        if ($b['beispiele'] !== []) {
            $this->table(['ID', 'Name', 'VK Darreichung', 'VK Legacy', 'Δ €'],
                array_map(fn ($x) => [
                    $x['recipe_id'], $this->kurz($x['name']),
                    $x['vk_darreichung_eur'], $x['vk_legacy_eur'], $x['delta_eur'],
                ], $b['beispiele']));
        }
    }

    private function zeigeC(array $c): void
    {
        $this->line('  C · „aktiver Preis" am Lead-LA — drei Fassungen (V-053)');
        $this->table(['Kennzahl', 'Wert'], [
            ['GPs mit Lead-LA', $c['gps_mit_lead']],
            ['laxe Fassung erfüllt (DQ-Ampel heute)', $c['lax_erfuellt']],
            ['strenge Fassung erfüllt (scopeAktiv / Money-Path)', $c['streng_erfuellt']],
            ['Gültigkeits-Fassung erfüllt (+ valid_to)', $c['gueltig_erfuellt']],
            ['Δ lax → streng = Lücken, die die Ampel verschweigt', $c['delta_lax_streng']],
            ['Δ streng → gültig = die offene valid_to-Frage', $c['delta_streng_gueltig']],
        ]);

        foreach ([
            'nur statusfremd (lax ja, streng nein)' => $c['beispiele_nur_statusfremd'],
            'nur abgelaufen (streng ja, gültig nein)' => $c['beispiele_nur_abgelaufen'],
        ] as $titel => $zeilen) {
            if ($zeilen === []) {
                continue;
            }
            $this->line("    Beispiele — {$titel}:");
            $this->table(['GP-ID', 'Name', 'Lead-LA'],
                array_map(fn ($z) => [$z['gp_id'], $this->kurz($z['name']), $z['lead_la_supplier_item_id']], $zeilen));
        }
    }

    private function drei(?float $min, ?float $median, ?float $max): string
    {
        return $min === null ? '–' : "{$min} / {$median} / {$max}";
    }

    private function kurz(string $name): string
    {
        return mb_strlen($name) > 38 ? mb_substr($name, 0, 37).'…' : $name;
    }
}
