<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Platform\FoodAlchemist\Services\Necta\NectaNachfuellService;

/**
 * Neue Deklarations-Spalte für den Bestand aus Necta nachfüllen (erstmals Jod, 15.10.2026). Setzt nur diese Spalte und nur wo sie
 * NULL ist; legt nichts an. Erst `--probe`, abgebrochen? `--fortsetzen=<lauf_id>`.
 */
class NectaDeklarationNachfuellenCommand extends Command
{
    protected $signature = 'foodalchemist:necta-deklaration-nachfuellen
        {--feld= : FA-Spalte, z. B. iodized}
        {--verbindung= : integration_connections.id (Standard: foodalchemist.necta.verbindung)}
        {--probe : nur lesen und zählen, nichts schreiben}
        {--lieferant=* : nur diese Necta-Lieferanten-IDs}
        {--ohne-lieferant=* : diese Necta-Lieferanten-IDs auslassen (bei uns inaktive entfallen ohnehin)}
        {--seite=500 : Seitengröße je Necta-Aufruf}
        {--pause-ms=200 : Pause zwischen zwei Necta-Aufrufen}
        {--fortsetzen= : Lauf-ID eines abgebrochenen Nachfüll-Laufs}';

    protected $description = 'Necta: eine neue Deklarations-Spalte für den Bestand nachfüllen (nur NULL-Werte, resumefähig, mit Probelauf)';

    public function handle(NectaNachfuellService $dienst): int
    {
        if (! $this->option('feld')) {
            $this->error('--feld fehlt (z. B. --feld=iodized).');

            return self::FAILURE;
        }
        $probe = (bool) $this->option('probe');
        $verbindung = (int) ($this->option('verbindung') ?? config('foodalchemist.necta.verbindung', 1));
        $this->info(($probe ? 'PROBELAUF (schreibt nichts) · ' : '')."Necta-Deklaration nachfüllen: {$this->option('feld')}, Verbindung {$verbindung}");

        try {
            $r = $dienst->lauf($verbindung, [
                'feld' => (string) $this->option('feld'),
                'probe' => $probe,
                'lieferanten' => array_map('intval', (array) $this->option('lieferant')),
                'ohne_lieferanten' => array_map('intval', (array) $this->option('ohne-lieferant')),
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
        foreach ($z['uebersprungen'] ?? [] as $l) {
            $this->line("  übersprungen: {$l['name']} (Necta {$l['legacy_id']}) — {$l['grund']}");
        }
        $this->info("Lauf {$r['lauf_id']}: {$r['status']}".($probe ? ' (Probe)' : ''));

        return self::SUCCESS;
    }
}
