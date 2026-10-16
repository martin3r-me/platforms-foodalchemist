<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Platform\FoodAlchemist\Jobs\NonFoodKiBatchJob;
use Platform\FoodAlchemist\Services\NonFoodService;
use Platform\FoodAlchemist\Support\Warteschlange;

/**
 * Non-Food-Erkennung über den ganzen Artikelbestand: Regelstufe, optional KI für die unklaren Fälle.
 *
 * Dry-Run ist der Default und zählt nur (Non-Food / unklar / Food, je Lieferant). --apply schreibt die Urteile. --ki schickt die
 * unklaren Artikel in Stapeln an die KI (Schlange `anreichern`); was die KI nicht sicher entscheidet, bleibt in der Prüfliste.
 * Von Hand gesetzte Urteile und KI-Urteile werden nicht überschrieben. Die Kosten der KI-Stufe: je 20 Artikel ein Call.
 */
class NonFoodErkennenCommand extends Command
{
    protected $signature = 'foodalchemist:nonfood-erkennen
        {--team= : nur Artikel dieses Teams (ohne Angabe: alle)}
        {--limit= : nur die ersten n Artikel (Test)}
        {--apply : Urteile schreiben (sonst Dry-Run: nur zählen)}
        {--ki : die unklaren Artikel an die KI geben (nur mit --apply)}';

    protected $description = 'Non-Food am Lieferantenartikel erkennen: Regel, dann KI für unklare, Rest in die Prüfliste';

    public function handle(NonFoodService $svc): int
    {
        $apply = (bool) $this->option('apply');
        if ($this->option('ki') && ! $apply) {
            $this->error('--ki braucht --apply.');

            return self::FAILURE;
        }
        if (! $apply) {
            $this->warn('DRY-RUN — es wird nichts geschrieben. Mit --apply ausführen.');
        }

        $r = $svc->regelLauf(
            $this->option('team') !== null ? (int) $this->option('team') : null,
            $apply,
            $this->option('limit') !== null ? (int) $this->option('limit') : null,
        );
        $this->table(['Artikel', 'Non-Food', 'unklar', 'Food'], [[$r['gesamt'], $r['nonfood'], $r['unklar'], $r['food']]]);
        if ($apply) {
            $this->line('Geschrieben: '.$r['geschrieben'].' · gelöscht (Regel jetzt „Food“): '.$r['geloescht']);
        }

        uasort($r['je_lieferant'], fn ($a, $b) => ($b['nonfood'] + $b['unklar']) <=> ($a['nonfood'] + $a['unklar']));
        $zeilen = [];
        foreach (array_slice($r['je_lieferant'], 0, 20, true) as $name => $z) {
            $zeilen[] = [$name, $z['nonfood'], $z['unklar']];
        }
        $this->table(['Lieferant (Top 20)', 'Non-Food', 'unklar'], $zeilen);

        if ($this->option('ki')) {
            $stapel = array_chunk($r['unklar_ids'], NonFoodKiBatchJob::STAPEL);
            foreach ($stapel as $ids) {
                NonFoodKiBatchJob::dispatch($ids)->onQueue(Warteschlange::anreichern());
            }
            $this->line(count($stapel).' KI-Stapel auf der Schlange „'.(Warteschlange::anreichern() ?? 'default').'“ eingereiht ('.count($r['unklar_ids']).' Artikel).');
        }

        return self::SUCCESS;
    }
}
