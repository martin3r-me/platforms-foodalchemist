<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Support\JodAusZutaten;

/**
 * Jod aus der Zutatenliste für den Bestand ableiten (15.10.2026): setzt `supplier_items.iodized_abgeleitet` aus
 * `ingredients_supplier` ({@see JodAusZutaten}). Schreibt nur, wo sich der Wert ändert → idempotent; nach einer Verbesserung der
 * Muster einfach erneut laufen lassen. Der Necta-Rohwert in der Deklaration bleibt unberührt. Erst `--probe`.
 * Danach die Rezepte neu rechnen (Recompute), damit `additive_iodized` am Gericht stimmt.
 */
class JodAbleitenCommand extends Command
{
    protected $signature = 'foodalchemist:jod-ableiten
        {--probe : nur zählen und Beispiele zeigen, nichts schreiben}
        {--block=1000 : Artikel je Block}';

    protected $description = 'Jod (jodiert ja/nein) aus der Zutatenliste der Lieferantenartikel ableiten';

    public function handle(): int
    {
        $probe = (bool) $this->option('probe');
        $z = ['geprueft' => 0, 'ja' => 0, 'nein' => 0, 'keine_aussage' => 0, 'geaendert' => 0];
        $beispiele = ['ja' => [], 'nein' => []];

        DB::table('foodalchemist_supplier_items')
            ->where(fn ($q) => $q->whereNotNull('ingredients_supplier')->orWhereNotNull('iodized_abgeleitet'))
            ->orderBy('id')
            ->chunkById(max(1, (int) $this->option('block')), function ($zeilen) use ($probe, &$z, &$beispiele) {
                foreach ($zeilen as $a) {
                    $z['geprueft']++;
                    $wert = JodAusZutaten::wert($a->ingredients_supplier);
                    $art = match ($wert) { JodAusZutaten::JA => 'ja', JodAusZutaten::NEIN => 'nein', default => 'keine_aussage' };
                    $z[$art]++;
                    if ($art !== 'keine_aussage' && count($beispiele[$art]) < 5) {
                        $beispiele[$art][] = "#{$a->id} ".mb_substr((string) $a->designation, 0, 50);
                    }
                    if ($wert === ($a->iodized_abgeleitet === null ? null : (int) $a->iodized_abgeleitet)) {
                        continue;
                    }
                    $z['geaendert']++;
                    if (! $probe) {
                        DB::table('foodalchemist_supplier_items')->where('id', $a->id)->update(['iodized_abgeleitet' => $wert]);
                    }
                }
            }, 'id');

        $this->info(($probe ? 'PROBELAUF (schreibt nichts) · ' : '').'Jod aus der Zutatenliste abgeleitet');
        $this->table(['Zähler', 'Wert'], collect($z)->map(fn ($v, $k) => [$k, $v])->values()->all());
        foreach ($beispiele as $art => $liste) {
            foreach ($liste as $b) {
                $this->line("  {$art}: {$b}");
            }
        }
        if (! $probe && $z['geaendert'] > 0) {
            $this->warn('Rezepte neu rechnen, damit additive_iodized am Gericht stimmt.');
        }

        return self::SUCCESS;
    }
}
