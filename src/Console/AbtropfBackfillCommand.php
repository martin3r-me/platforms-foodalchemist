<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Support\AbtropfAusText;

/**
 * Abtropf- und Füllgewicht aus dem Artikeltext für den Bestand (Entscheid Dominique 12.11.2026, {@see AbtropfAusText}).
 * Standard ist der Trockenlauf; geschrieben wird nur mit `--apply`. Von Hand gepflegte Werte (`abtropf_quelle = manuell`)
 * bleiben unangetastet. Schreibt nur, wo sich etwas ändert → idempotent. Läuft nie automatisch (keine Migration, kein Zeitplan).
 */
class AbtropfBackfillCommand extends Command
{
    protected $signature = 'foodalchemist:abtropf-backfill
        {--dry-run : nur auflisten, nichts schreiben (Standard)}
        {--apply : erkannte Werte schreiben}
        {--block=1000 : Artikel je Block}';

    protected $description = 'Abtropf- und Füllgewicht aus dem Artikeltext erkennen (Standard: Trockenlauf; --apply schreibt)';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply') && ! (bool) $this->option('dry-run');
        $z = ['geprueft' => 0, 'gesetzt' => 0, 'unveraendert' => 0, 'mehrdeutig' => 0, 'abweichung' => 0, 'manuell_geschuetzt' => 0];
        $gesetzt = $mehrdeutig = $abweichung = [];

        DB::table('foodalchemist_supplier_items')->whereNull('deleted_at')
            ->where(fn ($q) => $q->where('designation', 'like', '%abtropf%')->orWhere('additional_text', 'like', '%abtropf%')
                ->orWhere('marketing_name', 'like', '%abtropf%')
                ->orWhere('designation', 'like', '%ATG%')->orWhere('additional_text', 'like', '%ATG%')
                ->orWhere('designation', 'like', '%abtr.%')->orWhere('additional_text', 'like', '%abtr.%'))
            ->orderBy('id')
            ->chunkById(max(1, (int) $this->option('block')), function ($zeilen) use ($apply, &$z, &$gesetzt, &$mehrdeutig, &$abweichung) {
                foreach ($zeilen as $a) {
                    $menge = FoodAlchemistSupplierItem::mengeVon($a);
                    $r = AbtropfAusText::erkenne([$a->designation, $a->additional_text, $a->marketing_name], $menge, $a->qty_ordering_per_packaging, $a->unit_code);
                    if ($r['status'] === 'kein_treffer') {
                        continue;   // LIKE traf z. B. „ATG“ in einem Wort; der Parser sieht keine Angabe
                    }
                    $z['geprueft']++;
                    if ($r['status'] === 'mehrdeutig') {
                        $z['mehrdeutig']++;
                        $mehrdeutig[] = [$a->id, mb_substr((string) $a->designation, 0, 50), $menge, $a->unit_code, $r['grund']];

                        continue;
                    }
                    if ($a->abtropf_quelle === 'manuell') {
                        $z['manuell_geschuetzt']++;

                        continue;
                    }
                    $alt = [$a->abtropfgewicht === null ? null : round((float) $a->abtropfgewicht, 4), $a->fuellgewicht === null ? null : round((float) $a->fuellgewicht, 4)];
                    if ($r['abweichung']) {
                        $z['abweichung']++;
                        $abweichung[] = [$a->id, mb_substr((string) $a->designation, 0, 50), $menge, $r['abtropfgewicht']];
                    }
                    if ($alt === [$r['abtropfgewicht'], $r['fuellgewicht']] && $a->abtropf_quelle === AbtropfAusText::QUELLE) {
                        $z['unveraendert']++;

                        continue;
                    }
                    $z['gesetzt']++;
                    $gesetzt[] = [$a->id, mb_substr((string) $a->designation, 0, 50), $menge, $r['fuellgewicht'], $r['abtropfgewicht']];
                    if ($apply) {
                        DB::table('foodalchemist_supplier_items')->where('id', $a->id)->update([
                            'abtropfgewicht' => $r['abtropfgewicht'], 'fuellgewicht' => $r['fuellgewicht'],
                            'abtropf_quelle' => AbtropfAusText::QUELLE, 'updated_at' => now(),
                        ]);
                    }
                }
            }, 'id');

        $this->info(($apply ? 'GESCHRIEBEN · ' : 'TROCKENLAUF (schreibt nichts, --apply schreibt) · ').'Abtropfgewicht aus dem Artikeltext');
        $this->table(['Zähler', 'Wert'], collect($z)->map(fn ($v, $k) => [$k, $v])->values()->all());
        if ($gesetzt !== []) {
            $this->line($apply ? 'Gesetzt:' : 'Würde setzen:');
            $this->table(['Id', 'Bezeichnung', 'Menge', 'Füllgewicht', 'Abtropfgewicht'], $gesetzt);
        }
        if ($abweichung !== []) {
            $this->line('Abweichung Menge ≠ Abtropfgewicht (der Preis je kg rechnet mit dem Abtropfgewicht):');
            $this->table(['Id', 'Bezeichnung', 'Menge', 'Abtropfgewicht'], $abweichung);
        }
        if ($mehrdeutig !== []) {
            $this->line('Mehrdeutig (nichts gesetzt, von Hand prüfen):');
            $this->table(['Id', 'Bezeichnung', 'Menge', 'Einheit', 'Grund'], $mehrdeutig);
        }
        if ($apply && $z['gesetzt'] > 0) {
            $this->warn('Rezepte neu rechnen, damit der EK je kg am Gericht stimmt.');
        }

        return self::SUCCESS;
    }
}
