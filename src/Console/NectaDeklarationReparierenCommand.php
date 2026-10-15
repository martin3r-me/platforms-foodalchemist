<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Platform\FoodAlchemist\Models\FoodAlchemistItemDeclaration;
use Platform\FoodAlchemist\Services\Necta\NectaMapper;

/**
 * Einmal-Reparatur Necta-Deklarationen (Nachholer-Lauf 3 auf foodalchemist.io, 15.10.2026): Der Mapper kannte die Raw-API-Namen
 * `declarationWithDye` usw. nicht, alle Werte landeten als `declaration_with_dye` … in `details`, die Stoff-Spalten blieben leer.
 * Der Befehl liest diese Rohwerte zurück und mappt sie mit dem korrigierten {@see NectaMapper::deklaration()} neu, samt Necta-Stand.
 *
 * Betroffen sind nur Zeilen mit legacy_id, ALLEN Stoff-Spalten leer und mindestens einem `declaration_…`-Schlüssel in `details`.
 * Danach trifft die Auswahl die Zeile nicht mehr → idempotent.
 */
class NectaDeklarationReparierenCommand extends Command
{
    protected $signature = 'foodalchemist:necta-deklaration-reparieren
        {--probe : nur zählen und Beispiele zeigen, nichts schreiben}
        {--block=500 : Zeilen je Block}';

    protected $description = 'Necta: Deklarationszeilen mit leeren Stoff-Spalten aus den Rohwerten in details neu mappen';

    public function handle(NectaMapper $mapper): int
    {
        $probe = (bool) $this->option('probe');
        $stoffe = array_keys(FoodAlchemistItemDeclaration::STOFFE);
        $z = ['kandidaten' => 0, 'repariert' => 0, 'ohne_rohwert' => 0, 'stand_aktualisiert' => 0];
        $unbekannt = [];
        $beispiele = [];

        $abfrage = DB::table('foodalchemist_item_declarations')->whereNotNull('legacy_id')->whereNotNull('details')
            ->where('details', 'like', '%"declaration_%')   // grob vorfiltern, genau prüft die Schleife
            ->where(function ($q) use ($stoffe) {
                foreach ($stoffe as $s) {
                    $q->whereNull($s);
                }
            });

        $abfrage->orderBy('id')->chunkById(max(1, (int) $this->option('block')), function ($zeilen) use ($mapper, $probe, $stoffe, &$z, &$unbekannt, &$beispiele) {
            foreach ($zeilen as $zeile) {
                $z['kandidaten']++;
                $roh = ['supplierItemId' => (int) $zeile->legacy_id];
                $rest = [];
                foreach ((array) json_decode((string) $zeile->details, true) as $schluessel => $wert) {
                    // Der alte Mapper legte Unbekanntes als snake_case ab; camel() kehrt das um (declaration_b_excessive… → declarationBExcessive…)
                    if (str_starts_with($schluessel, 'declaration_')) {
                        $roh[Str::camel($schluessel)] = $wert;
                    } else {
                        $rest[$schluessel] = $wert;
                    }
                }
                if (count($roh) === 1) {
                    $z['ohne_rohwert']++;

                    continue;
                }
                $neu = $mapper->deklaration($roh + $rest, $unbekannt);
                unset($neu['legacy_id']);
                $z['repariert']++;
                if (count($beispiele) < 3) {
                    $beispiele[] = ['legacy_id' => $zeile->legacy_id] + array_filter($neu, fn ($v) => $v !== null);
                }
                if ($probe) {
                    continue;
                }
                DB::table('foodalchemist_item_declarations')->where('id', $zeile->id)->update($neu + ['updated_at' => now()]);
                $z['stand_aktualisiert'] += DB::table('foodalchemist_necta_stand')->where('objekt', 'item_declaration')
                    ->where('legacy_id', (int) $zeile->legacy_id)->update(['werte' => json_encode($neu), 'updated_at' => now()]);
            }
        }, 'id');

        $this->info(($probe ? 'PROBELAUF (schreibt nichts) · ' : '').'Necta-Deklarationen neu gemappt');
        $this->table(['Zähler', 'Wert'], collect($z)->map(fn ($v, $k) => [$k, $v])->values()->all());
        foreach ($beispiele as $b) {
            $this->line('  '.json_encode($b, JSON_UNESCAPED_UNICODE));
        }
        if ($unbekannt !== []) {
            $this->warn('Weiterhin unbekannte Necta-Felder (bleiben in details): '.implode(', ', array_keys($unbekannt)));
        }

        return self::SUCCESS;
    }
}
