<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;

/**
 * Mengen-Korrektur aus der Bezeichnung für den Bestand (Entscheid Dominique 16.10.2026, {@see \Platform\FoodAlchemist\Support\MengeAusBezeichnung}):
 * setzt `qty_korrigiert` + `qty_korrektur_quelle`, der Necta-Rohwert `qty` bleibt. Schreibt nur, wo sich etwas ändert → idempotent;
 * entfernt eine frühere Korrektur, wenn die Regel nicht mehr greift. Erst `--probe`.
 */
class MengeKorrigierenCommand extends Command
{
    protected $signature = 'foodalchemist:menge-korrigieren
        {--probe : nur zählen und Beispiele zeigen, nichts schreiben}
        {--block=1000 : Artikel je Block}';

    protected $description = 'Menge je Bestelleinheit aus der Bezeichnung korrigieren (EDI: Menge / Stückzahl), Necta-qty bleibt';

    public function handle(): int
    {
        $probe = (bool) $this->option('probe');
        $z = ['geprueft' => 0, 'korrigiert' => 0, 'geaendert' => 0, 'korrektur_entfernt' => 0];
        $beispiele = [];

        DB::table('foodalchemist_supplier_items')->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereNotNull('qty')->orWhereNotNull('qty_korrigiert'))
            ->orderBy('id')
            ->chunkById(max(1, (int) $this->option('block')), function ($zeilen) use ($probe, &$z, &$beispiele) {
                foreach ($zeilen as $a) {
                    $z['geprueft']++;
                    $neu = FoodAlchemistSupplierItem::mengenKorrektur($a->designation, $a->qty, $a->qty_ordering_per_packaging, $a->unit_code);
                    if ($neu['qty_korrigiert'] !== null) {
                        $z['korrigiert']++;
                        if (count($beispiele) < 8) {
                            $beispiele[] = "#{$a->id} ".mb_substr((string) $a->designation, 0, 45)." · qty {$a->qty} × {$a->qty_ordering_per_packaging} → {$neu['qty_korrigiert']}";
                        }
                    }
                    $alt = $a->qty_korrigiert === null ? null : round((float) $a->qty_korrigiert, 4);
                    if ($alt === $neu['qty_korrigiert'] && $a->qty_korrektur_quelle === $neu['qty_korrektur_quelle']) {
                        continue;
                    }
                    $z['geaendert']++;
                    if ($neu['qty_korrigiert'] === null) {
                        $z['korrektur_entfernt']++;
                    }
                    if (! $probe) {
                        DB::table('foodalchemist_supplier_items')->where('id', $a->id)->update($neu);
                    }
                }
            }, 'id');

        $this->info(($probe ? 'PROBELAUF (schreibt nichts) · ' : '').'Mengen-Korrektur aus der Bezeichnung');
        $this->table(['Zähler', 'Wert'], collect($z)->map(fn ($v, $k) => [$k, $v])->values()->all());
        foreach ($beispiele as $b) {
            $this->line("  {$b}");
        }
        if (! $probe && $z['geaendert'] > 0) {
            $this->warn('Rezepte neu rechnen, damit EK je kg/l am Gericht stimmt.');
        }

        return self::SUCCESS;
    }
}
