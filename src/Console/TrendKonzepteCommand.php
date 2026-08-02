<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\SignalSeverity;
use Platform\FoodAlchemist\Enums\SignalTyp;
use Platform\FoodAlchemist\Services\ConceptGeneratorService;
use Platform\FoodAlchemist\Services\SignalService;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Services\TrendRadarService;

/**
 * Trendradar-Automatisierung (Transkript-Vision „08:00 → 3 Konzeptvorschläge"):
 * zieht die Top-Trends aus dem geclusterten Bestand, lässt den Konzept-Generator
 * je Trend ein Konzept-Gerüst bauen und legt das Ergebnis als Signal in die
 * bestehende Review-Inbox — auch wer nicht im Modul ist, wird so erreicht.
 *
 * DEFAULT AUS im Scheduler (config foodalchemist.scheduler.trend_konzepte_enabled):
 * der Lauf ruft das Modell pro Trend/Team und gibt sonst ungefragt Provider-Geld aus.
 * Konzepte entstehen pro Team als Draft (created_via=..._trend_auto), nur aus echtem
 * VK-Bestand (deterministischer Assembler — keine Halluzination).
 */
class TrendKonzepteCommand extends Command
{
    protected $signature = 'foodalchemist:trend-konzepte
        {--team= : nur dieses Team (ID), sonst alle}
        {--limit= : Anzahl Top-Trends (überschreibt die Team-Einstellung)}
        {--force : auch Teams bedienen, die die Automatisierung NICHT aktiviert haben}
        {--dry-run : Nur zeigen, welche Trends/Teams — kein KI-Call, kein Signal}';

    protected $description = 'Trendradar → tägliche Konzeptvorschläge aus Top-Trends (Signal in die Inbox)';

    public function handle(TrendRadarService $radar, ConceptGeneratorService $generator, SignalService $signals): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $limitOption = $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null;

        // Pool großzügig ziehen — je Team wird auf sein eigenes Limit geschnitten.
        $pool = $radar->topTrends(10);
        if ($pool === []) {
            $this->warn('Keine Trends im Bestand — erst foodalchemist:trend-cluster laufen lassen.');

            return self::SUCCESS;
        }

        $teams = $this->option('team')
            ? Team::whereKey((int) $this->option('team'))->get()
            : Team::query()->get();

        $settings = app(TeamSettingsService::class);
        $gesamt = 0;

        foreach ($teams as $team) {
            if (! $settings->kiAktiv($team)) {
                $this->line("Team {$team->id} ({$team->name}): KI deaktiviert — übersprungen.");

                continue;
            }
            if (! $force && ! $settings->trendAutoAktiv($team)) {
                $this->line("Team {$team->id} ({$team->name}): Trend-Automatisierung nicht aktiviert — übersprungen.");

                continue;
            }

            $limit = $limitOption ?? $settings->trendAutoLimit($team);
            $topTrends = array_slice($pool, 0, $limit);

            if ($dryRun) {
                $this->line("Team {$team->id} ({$team->name}): würde {$limit} Konzept(e) erzeugen [DRY-RUN] — "
                    . implode(' · ', array_map(fn ($t) => $t->title, $topTrends)));

                continue;
            }

            $conceptRefs = [];
            foreach ($topTrends as $trend) {
                try {
                    $res = $generator->generiereAusBrief(
                        $team,
                        $this->briefFuerTrend($trend),
                        'Trend: ' . $trend->title,
                        'trend_auto'
                    );
                } catch (\Throwable $e) {
                    $this->warn("Team {$team->id}, Trend „{$trend->title}“: {$e->getMessage()}");

                    continue;
                }
                $concept = $res['concept'] ?? null;
                $conceptRefs[] = [
                    'trend_slug' => $trend->slug,
                    'trend_title' => $trend->title,
                    'concept_id' => $concept?->id,
                    'concept_name' => $concept?->name,
                ];
            }

            if ($conceptRefs === []) {
                $this->line("Team {$team->id} ({$team->name}): keine Konzepte erzeugt.");

                continue;
            }

            $mitSignal = $settings->trendSignalAktiv($team);
            if ($mitSignal) {
                $signals->erzeuge(
                    $team,
                    SignalTyp::TrendKonzeptVorschlag,
                    SignalSeverity::Info,
                    count($conceptRefs) . ' Trend-Konzeptvorschläge für heute',
                    [
                        'description' => 'Aus den aktuellen Top-Trends automatisch generiert: '
                            . implode(', ', array_map(fn ($r) => $r['trend_title'], $conceptRefs))
                            . '. Entwürfe im Konzepter prüfen.',
                        'payload' => ['trends' => $conceptRefs],
                        'dedup_key' => 'trend-konzepte-' . date('Y-m-d'),
                        'ref_type' => 'concept',
                        'ref_id' => $conceptRefs[0]['concept_id'] ?? null,
                        'source' => 'trend_auto',
                    ]
                );
            }
            $gesamt++;
            $this->line("Team {$team->id} ({$team->name}): " . count($conceptRefs) . ' Konzept(e)'
                . ($mitSignal ? ' + Signal.' : ' (ohne Signal).'));
        }

        $this->info("Fertig — Signale für {$gesamt} Team(s).");

        return self::SUCCESS;
    }

    /** Baut aus einem Top-Trend einen kurzen Brief für den Konzept-Generator. */
    private function briefFuerTrend(object $trend): string
    {
        $body = preg_replace('/\A\x{FEFF}?\s*---\R.*?\R---\R?/su', '', (string) $trend->content_md) ?? '';
        if (preg_match('/##\s*Zusammenfassung\s*\R+(.+?)(?:\R##\s|\z)/su', $body, $m)) {
            $body = $m[1];
        }
        $body = mb_substr(trim(preg_replace('/\s+/u', ' ', $body) ?? $body), 0, 500);

        return "Aktueller Food-Trend: {$trend->title}.\n\n{$body}\n\n"
            . 'Entwirf ein stimmiges Catering-Konzept, das genau diesen Trend aufgreift '
            . '(Anlass frei wählbar, marktüblich bepreist).';
    }
}
