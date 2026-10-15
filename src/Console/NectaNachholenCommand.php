<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Platform\FoodAlchemist\Services\Necta\NectaNachholService;

/**
 * Necta-Abgleich · Einmal-Nachholer (Bauauftrag 15.10.2026). Holt fehlende Lieferanten, Lieferantenartikel und zu neuen Artikeln
 * Allergene, Deklaration, Nährwerte und Preise aus necta.one (Raw-API über das Integrations-Modul, nur lesend).
 *
 * Erst `--probe` (schreibt nichts, nur Zahlen + Beispiele in der Laufzeile), Zahlen an den Kurator, dann echter Lauf nach
 * Dominiques Freigabe. Abgebrochen? `--fortsetzen=<lauf_id>` macht beim nächsten Lieferanten weiter.
 */
class NectaNachholenCommand extends Command
{
    protected $signature = 'foodalchemist:necta-nachholen
        {--verbindung=1 : integration_connections.id der Necta-Verbindung}
        {--team= : Team der Preise (Standard: Master-Team)}
        {--probe : nur lesen und zählen, nichts anlegen}
        {--lieferant=* : nur diese Necta-Lieferanten-IDs}
        {--ohne-lieferant=* : diese Necta-Lieferanten-IDs auslassen (bei uns inaktive Lieferanten entfallen ohnehin)}
        {--max-lieferanten= : höchstens so viele Lieferanten in diesem Lauf}
        {--seite=500 : Seitengröße je Necta-Aufruf}
        {--pause-ms=200 : Pause zwischen zwei Necta-Aufrufen}
        {--fortsetzen= : Lauf-ID eines abgebrochenen Nachhol-Laufs}';

    protected $description = 'Necta: fehlende Lieferanten, Artikel, Details und Preise einmalig nachholen (resumefähig, mit Probelauf)';

    public function handle(NectaNachholService $dienst): int
    {
        $probe = (bool) $this->option('probe');
        $this->info(($probe ? 'PROBELAUF (schreibt nichts) · ' : '').'Necta-Nachholer, Verbindung '.$this->option('verbindung'));

        try {
            $r = $dienst->lauf((int) $this->option('verbindung'), [
                'team_id' => $this->option('team') !== null ? (int) $this->option('team') : null,
                'probe' => $probe,
                'lieferanten' => array_map('intval', (array) $this->option('lieferant')),
                'ohne_lieferanten' => array_map('intval', (array) $this->option('ohne-lieferant')),
                'max_lieferanten' => $this->option('max-lieferanten') !== null ? (int) $this->option('max-lieferanten') : null,
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
        foreach ($z['neue_lieferanten'] ?? [] as $l) {
            $this->line("  neuer Lieferant: {$l['name']} (Necta {$l['legacy_id']})");
        }
        foreach ($z['uebersprungen'] ?? [] as $l) {
            $this->line("  übersprungen: {$l['name']} (Necta {$l['legacy_id']}) — {$l['grund']}");
        }
        if (! empty($z['deklaration_unbekannte_felder'])) {
            $this->warn('Deklaration: unbekannte Necta-Felder (in details abgelegt): '.implode(', ', $z['deklaration_unbekannte_felder']));
        }
        $this->info("Lauf {$r['lauf_id']}: {$r['status']}".($probe ? ' (Probe)' : ''));
        if (! $probe && ($z['artikel_neu'] ?? 0) > 0) {
            $this->line('Als Nächstes: php artisan foodalchemist:embed --pool=la --missing (Embeddings der neuen Artikel, gestapelt).');
        }

        return self::SUCCESS;
    }
}
