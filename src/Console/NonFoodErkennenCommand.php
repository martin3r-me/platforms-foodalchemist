<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Platform\FoodAlchemist\Jobs\NonFoodKiBatchJob;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemNonfood;
use Platform\FoodAlchemist\Services\NonFoodService;
use Platform\FoodAlchemist\Support\Warteschlange;

/**
 * Non-Food-Erkennung über den ganzen Artikelbestand: Regelstufe, optional KI für die unklaren Fälle.
 *
 * Dry-Run ist der Default und zählt nur (Non-Food / unklar / Food, je Lieferant). --apply schreibt die Urteile. --ki schickt die
 * unklaren Artikel in Stapeln an die KI (Schlange `anreichern`); was die KI nicht sicher entscheidet, bleibt in der Prüfliste.
 * Von Hand gesetzte Urteile und KI-Urteile werden nicht überschrieben. Die KI-Stufe schickt Stapel von NonFoodKiBatchJob::STAPEL Artikeln (je Stapel ein Call) und nimmt Artikel, die sie schon versucht hat, NICHT noch einmal (--ki-nochmal). Vor dem ganzen Bestand: Messlauf mit --ki-limit.
 */
class NonFoodErkennenCommand extends Command
{
    protected $signature = 'foodalchemist:nonfood-erkennen
        {--team= : nur Artikel dieses Teams (ohne Angabe: alle)}
        {--limit= : nur die ersten n Artikel (Test)}
        {--apply : Urteile schreiben (sonst Dry-Run: nur zählen)}
        {--ki : die unklaren Artikel an die KI geben (nur mit --apply)}
        {--ki-nochmal : auch Artikel noch einmal an die KI geben, die sie schon versucht hat (Standard: nur die noch nie versuchten)}
        {--ki-limit= : höchstens so viele Artikel an die KI (Messlauf an 2–3 Stapeln, bevor der ganze Bestand läuft)}
        {--stapel= : Artikel je KI-Aufruf (Standard: NonFoodKiBatchJob::STAPEL)}';

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
            $alle = $r['unklar_ids'];
            $ids = $alle;
            if (! $this->option('ki-nochmal') && $alle !== []) {
                // nur Artikel, an denen die KI noch nie war: ohne das zahlt jeder Wiederholungslauf für dieselben unsicheren Fälle erneut
                $ids = [];
                foreach (array_chunk($alle, 5000) as $teil) {
                    array_push($ids, ...FoodAlchemistSupplierItemNonfood::query()->whereIn('supplier_item_id', $teil)->whereNull('ki_versucht_at')
                        ->pluck('supplier_item_id')->map(fn ($id) => (int) $id)->all());
                }
            }
            $uebersprungen = count($alle) - count($ids);
            if ($this->option('ki-limit') !== null && (int) $this->option('ki-limit') > 0) {
                $ids = array_slice($ids, 0, (int) $this->option('ki-limit'));
            }
            $groesse = max(1, (int) ($this->option('stapel') ?: NonFoodKiBatchJob::STAPEL));
            $stapel = array_chunk($ids, $groesse);
            foreach ($stapel as $teil) {
                NonFoodKiBatchJob::dispatch($teil)->onQueue(Warteschlange::anreichern());
            }
            $this->line(count($stapel).' KI-Stapel à höchstens '.$groesse.' auf der Schlange „'.(Warteschlange::anreichern() ?? 'default').'“ eingereiht ('.count($ids).' Artikel'
                .($uebersprungen > 0 ? ', '.$uebersprungen.' schon früher von der KI versucht und übersprungen' : '').').');
        }

        return self::SUCCESS;
    }
}
