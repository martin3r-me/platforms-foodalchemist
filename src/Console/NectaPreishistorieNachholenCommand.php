<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Platform\FoodAlchemist\Services\Necta\NectaPreishistorieService;

/**
 * Necta PR 2b: Preis-Historie nachholen nach `foodalchemist_price_history` (quelle = 'necta_historie'), siehe
 * {@see NectaPreishistorieService}. `--analyse` misst die Historien-Felder (Stufe 1), `--probe` rechnet den Import durch und
 * schreibt nur die Laufzeile, ohne Schalter wird geschrieben. Abgebrochen? `--fortsetzen=<lauf_id>`.
 */
class NectaPreishistorieNachholenCommand extends Command
{
    protected $signature = 'foodalchemist:necta-preishistorie-nachholen
        {--verbindung= : integration_connections.id (Standard: foodalchemist.necta.verbindung)}
        {--team= : Team der Historie = Team der Preise (Standard: Master-Team, wie der Nachholer)}
        {--analyse : nur messen (type, amountFrom, source, Folge), nichts schreiben}
        {--probe : Import durchrechnen, nichts schreiben}
        {--seit= : nur Stände mit validFrom ab (ISO, z. B. 2025-01-01T00:00:00)}
        {--fortsetzen= : Lauf-ID eines abgebrochenen Laufs}
        {--lieferant=* : nur diese Necta-Lieferanten-IDs (für die Probe empfohlen)}
        {--ohne-lieferant=* : diese Necta-Lieferanten-IDs auslassen (bei uns inaktive entfallen ohnehin)}
        {--max-seiten= : höchstens so viele Seiten je Lieferant (schonende Probe)}
        {--seite=500 : Seitengröße je Necta-Aufruf}
        {--pause-ms=200 : Pause zwischen zwei Necta-Aufrufen}';

    protected $description = 'Necta: Preis-Historie nachholen (resumefähig, mit Probe und Analyse)';

    public function handle(NectaPreishistorieService $dienst): int
    {
        $verbindung = (int) ($this->option('verbindung') ?? config('foodalchemist.necta.verbindung', 1));
        if (! $this->option('analyse')) {
            return $this->nachholen($dienst, $verbindung);
        }
        $this->info("ANALYSE (schreibt nichts) · Necta-Preis-Historie, Verbindung {$verbindung}");

        try {
            $r = $dienst->analyse($verbindung, [
                'lieferanten' => array_map('intval', (array) $this->option('lieferant')),
                'ohne_lieferanten' => array_map('intval', (array) $this->option('ohne-lieferant')),
                'max_seiten' => $this->option('max-seiten') !== null ? (int) $this->option('max-seiten') : null,
                'seite' => max(1, (int) $this->option('seite')),
                'pause_ms' => max(0, (int) $this->option('pause-ms')),
            ], fn (string $zeile) => $this->line('  '.$zeile));
        } catch (\Throwable $e) {
            $this->error('Abbruch: '.$e->getMessage());

            return self::FAILURE;
        }

        $z = $r['zaehler'];
        $this->table(['Zähler', 'Wert'], collect($z)->reject(fn ($v) => is_array($v))->map(fn ($v, $k) => [$k, $v])->values()->all());
        $verteilung = fn (array $m) => $m === [] ? 'keine' : collect($m)->map(fn ($n, $k) => "{$k} {$n}")->implode(' · ');
        $this->line('type: '.$verteilung($z['type']));
        $this->line('amountFrom: '.$verteilung($z['amount_from']));
        $this->line('source (Top 10): '.$verteilung($z['source_top']));
        foreach ($z['filter_wirkt_nicht'] as $f) {
            $this->warn("Filter supplierId wirkt nicht bei Necta {$f['legacy_id']} (totalCount {$f['total']}): Lieferant nach Seite 1 abgebrochen.");
        }
        foreach ($z['uebersprungen'] as $l) {
            $this->line("  übersprungen: {$l['name']} (Necta {$l['legacy_id']}) — {$l['grund']}");
        }
        $this->info("Lauf {$r['lauf_id']}: {$r['status']} (Beispiele und Folge in foodalchemist_necta_laeufe.beispiele)");

        return self::SUCCESS;
    }

    private function nachholen(NectaPreishistorieService $dienst, int $verbindung): int
    {
        $probe = (bool) $this->option('probe');
        $this->info(($probe ? 'PROBELAUF (schreibt nichts) · ' : '')."Necta-Preis-Historie nachholen, Verbindung {$verbindung}");
        try {
            $r = $dienst->nachholen($verbindung, [
                'team_id' => $this->option('team') !== null ? (int) $this->option('team') : null,
                'probe' => $probe,
                'lieferanten' => array_map('intval', (array) $this->option('lieferant')),
                'ohne_lieferanten' => array_map('intval', (array) $this->option('ohne-lieferant')),
                'seit' => $this->option('seit'),
                'seite' => max(1, (int) $this->option('seite')),
                'pause_ms' => max(0, (int) $this->option('pause-ms')),
                'fortsetzen' => $this->option('fortsetzen') !== null ? (int) $this->option('fortsetzen') : null,
            ], fn (string $zeile) => $this->line('  '.$zeile));
        } catch (\Throwable $e) {
            $this->error('Abbruch: '.$e->getMessage().' — mit --fortsetzen=<lauf_id> weitermachen (Laufzeile in foodalchemist_necta_laeufe).');

            return self::FAILURE;
        }
        $z = $r['zaehler'];
        $this->table(['Zähler', 'Wert'], collect($z)->reject(fn ($v) => is_array($v))->map(fn ($v, $k) => [$k, $v])->values()->all());
        foreach ($z['filter_wirkt_nicht'] as $f) {
            $this->warn("Filter supplierId wirkt nicht bei Necta {$f['legacy_id']} (totalCount {$f['total']}): Lieferant übersprungen.");
        }
        foreach ($z['uebersprungen'] as $l) {
            $this->line("  übersprungen: {$l['name']} (Necta {$l['legacy_id']}) — {$l['grund']}");
        }
        $this->info("Lauf {$r['lauf_id']}: {$r['status']}".($probe ? ' (Probe)' : ''));

        return self::SUCCESS;
    }
}
