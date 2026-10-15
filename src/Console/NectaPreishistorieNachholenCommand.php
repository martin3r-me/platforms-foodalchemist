<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Platform\FoodAlchemist\Services\Necta\NectaPreishistorieService;

/**
 * Necta PR 2b: Preis-Historie nachholen. Stufe 1 (dieser Stand): nur `--probe` — misst `supplier-item-price-historys` an echten
 * Daten (type, amountFrom, source, Zeilen je Artikel, offene Zeilen, eine vollständige Folge), schreibt nur die Laufzeile.
 * Der echte Import folgt, sobald Bedeutung der Felder und Ziel (Preis-Linie, mit Claudia) belegt sind.
 */
class NectaPreishistorieNachholenCommand extends Command
{
    protected $signature = 'foodalchemist:necta-preishistorie-nachholen
        {--verbindung= : integration_connections.id (Standard: foodalchemist.necta.verbindung)}
        {--probe : messen, nichts schreiben (derzeit Pflicht)}
        {--lieferant=* : nur diese Necta-Lieferanten-IDs (für die Probe empfohlen)}
        {--ohne-lieferant=* : diese Necta-Lieferanten-IDs auslassen (bei uns inaktive entfallen ohnehin)}
        {--max-seiten= : höchstens so viele Seiten je Lieferant (schonende Probe)}
        {--seite=500 : Seitengröße je Necta-Aufruf}
        {--pause-ms=200 : Pause zwischen zwei Necta-Aufrufen}';

    protected $description = 'Necta: Preis-Historie nachholen (Stufe 1: Analyse-Probe der Historien-Felder)';

    public function handle(NectaPreishistorieService $dienst): int
    {
        if (! $this->option('probe')) {
            $this->error('Noch nicht freigegeben: erst --probe auswerten (type/amountFrom/source), dann folgt der Import.');

            return self::FAILURE;
        }
        $verbindung = (int) ($this->option('verbindung') ?? config('foodalchemist.necta.verbindung', 1));
        $this->info("PROBELAUF (schreibt nichts) · Necta-Preis-Historie, Verbindung {$verbindung}");

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
}
