<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Platform\FoodAlchemist\Services\Necta\NectaAbgleichService;

/**
 * Necta-Abgleich nächtlich (Preis-Delta + neue Artikel) bzw. mit `--voll` wöchentlich (zusätzlich Stammdaten, Allergene,
 * Deklaration, Nährwerte bekannter Artikel → Übernahme bzw. Prüffälle). Zeitplan im ServiceProvider, nur wenn
 * `foodalchemist.necta.abgleich_aktiv` eingeschaltet ist (Standard aus).
 */
class NectaAbgleichCommand extends Command
{
    protected $signature = 'foodalchemist:necta-abgleich
        {--verbindung= : integration_connections.id (Standard: foodalchemist.necta.verbindung)}
        {--team= : Team der Preise (Standard: Master-Team)}
        {--probe : nur lesen und zählen, nichts schreiben}
        {--voll : wöchentlicher Vollvergleich bekannter Artikel (Stammdaten, Allergene, Deklaration, Nährwerte)}
        {--seit= : Preis-Delta ab (ISO, z. B. 2026-10-14T00:00:00); Standard: letzter erfolgreicher Lauf − 1 Tag}
        {--lieferant=* : nur diese Necta-Lieferanten-IDs}
        {--ohne-lieferant=* : diese Necta-Lieferanten-IDs auslassen (bei uns inaktive entfallen ohnehin)}
        {--seite=500 : Seitengröße je Necta-Aufruf}
        {--pause-ms=200 : Pause zwischen zwei Necta-Aufrufen}';

    protected $description = 'Necta: Preis-Delta und neue Artikel abgleichen (nächtlich), mit --voll Stammdaten/Allergene vergleichen (wöchentlich)';

    public function handle(NectaAbgleichService $dienst): int
    {
        $probe = (bool) $this->option('probe');
        $voll = (bool) $this->option('voll');
        $verbindung = (int) ($this->option('verbindung') ?? config('foodalchemist.necta.verbindung', 1));
        $this->info(($probe ? 'PROBELAUF (schreibt nichts) · ' : '').'Necta-'.($voll ? 'Vollabgleich' : 'Abgleich').", Verbindung {$verbindung}");

        try {
            $r = $dienst->lauf($verbindung, [
                'team_id' => $this->option('team') !== null ? (int) $this->option('team') : null,
                'probe' => $probe,
                'voll' => $voll,
                'seit' => $this->option('seit'),
                'lieferanten' => array_map('intval', (array) $this->option('lieferant')),
                'ohne_lieferanten' => array_map('intval', (array) $this->option('ohne-lieferant')),
                'seite' => max(1, (int) $this->option('seite')),
                'pause_ms' => max(0, (int) $this->option('pause-ms')),
            ], fn (string $zeile) => $this->line('  '.$zeile));
        } catch (\Throwable $e) {
            $this->error('Abbruch: '.$e->getMessage().' (Laufzeile in foodalchemist_necta_laeufe, Signal an das Master-Team).');

            return self::FAILURE;
        }

        $z = $r['zaehler'];
        $this->table(['Zähler', 'Wert'], collect($z)->reject(fn ($v) => is_array($v))->map(fn ($v, $k) => [$k, $v])->values()->all());
        foreach ($z['neue_lieferanten'] ?? [] as $l) {
            $this->line("  neuer Lieferant: {$l['name']} (Necta {$l['legacy_id']})");
        }
        foreach ($z['neue_artikel_je_lieferant'] ?? [] as $l) {
            $this->line("  neue Artikel: {$l['name']} (Necta {$l['legacy_id']}): {$l['neu']}");
        }
        foreach ($z['uebersprungen'] ?? [] as $l) {
            $this->line("  übersprungen: {$l['name']} (Necta {$l['legacy_id']}) — {$l['grund']}");
        }
        if (! empty($z['deklaration_unbekannte_felder'])) {
            $this->warn('Deklaration: unbekannte Necta-Felder (in details abgelegt): '.implode(', ', $z['deklaration_unbekannte_felder']));
        }
        // Schluss: Prüffälle je Art — damit vor dem Einschalten sichtbar ist, wie viel auf den Master zukommt
        $pf = $z['pruefaelle'] ?? [];
        ksort($pf);
        $this->line('Prüffälle je Art: '.($pf === [] ? 'keine' : collect($pf)->map(fn ($n, $art) => "{$art} {$n}")->implode(' · ')));
        $this->info("Lauf {$r['lauf_id']}: {$r['status']}".($probe ? ' (Probe)' : ''));

        return self::SUCCESS;
    }
}
