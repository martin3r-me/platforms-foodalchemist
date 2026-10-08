<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistTrend;
use Platform\FoodAlchemist\Services\TrendService;

/**
 * Spec 79 · Startbestand: die 28 Trends aus Sarah Sporks Projektarbeit (BHG Trend Radar 2026,
 * Click Dummy 3) in ein Team übernehmen — mit ihrer Einordnung und je Quelle einem Beleg.
 * Idempotent über den Slug: vorhandene Trends bleiben unberührt.
 *
 * Status: „auf dem Radar", wenn die Radar-Regel erfüllt ist, sonst „geprüft" (z. B. Bubble Tea:
 * nur Google Trends und Instagram — nach Kap. 3.3 noch unbestätigt).
 */
class TrendsStartbestandCommand extends Command
{
    protected $signature = 'foodalchemist:trends-startbestand
        {--team= : Ziel-Team (ID), Pflicht}
        {--dry-run : nur zeigen, was angelegt würde}';

    protected $description = 'Trendradar → Startbestand aus Sarah Sporks Projektarbeit (28 Trends) übernehmen';

    /** Quellen-Schlüssel aus dem Click Dummy → Trendradar-Vokabular. */
    private const QUELLEN = [
        'literatur' => 'literatur', 'marktforschung' => 'marktforschung', 'google' => 'google_trends',
        'instagram' => 'instagram', 'umfrage' => 'befragung', 'branchenquelle' => 'branchenquelle',
    ];

    private const GARTNER = ['Slope of Enlightenment' => 'slope', 'Plateau of Productivity' => 'plateau',
        'Trough of Disillusionment' => 'trough', 'Peak of Inflated Expectations' => 'peak', 'Innovation Trigger' => 'innovation_trigger'];

    public function handle(TrendService $svc): int
    {
        $team = Team::find((int) $this->option('team'));
        if ($team === null) {
            $this->error('Bitte --team=<ID> angeben.');

            return self::FAILURE;
        }
        $daten = json_decode((string) file_get_contents(__DIR__.'/../../database/data/trends_startbestand_sarah_spork.json'), true);
        $dry = (bool) $this->option('dry-run');
        $neu = 0;
        $radar = 0;

        foreach ((array) $daten as $d) {
            $slug = $svc->slugFuer($d['name']);
            if (FoodAlchemistTrend::where('team_id', $team->id)->where('slug', $slug)->exists()) {
                $this->line("= {$d['name']} (vorhanden)");
                continue;
            }
            if ($dry) {
                $this->line("+ {$d['name']}");
                $neu++;
                continue;
            }
            $quellen = array_map(fn ($q) => self::QUELLEN[$q] ?? 'literatur', (array) $d['quellen']);
            $trend = $svc->anlegen($team, [
                'name' => $d['name'],
                'definition' => $d['definition'],
                'typ' => $d['typ'],
                'ebene' => $d['ebene'],
                'kategorie' => $d['kategorie'],
                'historische_einordnung' => $d['historisch'] ?? null,
                'gartner_phase' => isset($d['gartner']) ? (self::GARTNER[$d['gartner']] ?? null) : null,
                'einordnung_quelle' => 'manuell',
                'einordnung_begruendung' => 'Einordnung aus Sarah Spork, BHG Trend Radar (2026), '.$d['ref'].'.',
            ]);
            foreach (array_unique($quellen) as $quelle) {
                $svc->belegAnhaengen($team, $trend->id, [
                    'quelle' => $quelle,
                    'titel' => $quelle === 'befragung'
                        ? 'Mitarbeiterbefragung Trend-Radar BHG (Hatch, Juli 2026)'
                        : 'Sarah Spork, BHG Trend Radar (2026), '.$d['ref'],
                    'notiz' => 'Übernommen aus der Projektarbeit; Quelle dort: '.$quelle.'.',
                    'beobachtet_am' => '2026-07-31',
                ]);
            }
            $trend = $trend->refresh();
            $status = $svc->radarHindernis($trend) === null ? 'auf_radar' : 'geprueft';
            $svc->statusSetzen($team, $trend->id, $status);
            $radar += $status === 'auf_radar' ? 1 : 0;
            $this->line(($status === 'auf_radar' ? '◉ ' : '○ ').$d['name']);
            $neu++;
        }

        $this->info($dry ? "{$neu} Trends würden angelegt." : "{$neu} Trends angelegt, {$radar} davon auf dem Radar.");

        return self::SUCCESS;
    }
}
