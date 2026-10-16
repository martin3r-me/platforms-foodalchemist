<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Einmalig vor dem Rückbau der Geschirr-DB (PR D): der Servier-Typ eines alten Geschirr-Artikels (`tableware_items.vehicle_vocab_id`), der an einem Slot,
 * einem Paket-Posten oder als Standard-Geschirr an einer Darreichung hing, geht als AUSDRÜCKLICHE Übersteuerung an die Darreichung — aber nur, wo diese
 * noch keinen Typ und kein Gefäß hat. Ein Bestandswert bleibt unberührt, und der Dry-Run (Default) zählt, wie viele Darreichungen schon einen Typ OHNE Gefäß
 * tragen: die bleiben unverändert wirksam (gewollt, bis jemand ein Gefäß setzt).
 */
class GeschirrTypUebernehmenCommand extends Command
{
    protected $signature = 'foodalchemist:geschirr-typ-uebernehmen
        {--apply : Schreiben (sonst Dry-Run: nur zählen)}';

    protected $description = 'Übernimmt den Servier-Typ alter Geschirr-Artikel als Übersteuerung an die Darreichung (vor dem Rückbau der Geschirr-DB)';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        if (! $apply) {
            $this->warn('DRY-RUN — es wird nichts geschrieben. Mit --apply ausführen.');
        }

        // Darreichung → Typ des alten Geschirr-Artikels: Standard-Geschirr der Form, sonst das Slot-/Posten-Geschirr der gewählten Darreichung
        $kandidaten = [];
        foreach (DB::table('foodalchemist_recipe_presentations as d')->join('foodalchemist_tableware_items as t', 't.id', '=', 'd.tableware_item_id')
            ->whereNull('d.deleted_at')->whereNotNull('t.vehicle_vocab_id')->get(['d.id as dar', 't.vehicle_vocab_id as typ']) as $r) {
            $kandidaten[(int) $r->dar] = (int) $r->typ;
        }
        foreach (['foodalchemist_concept_slots', 'foodalchemist_package_dishes'] as $tabelle) {
            foreach (DB::table("{$tabelle} as s")->join('foodalchemist_tableware_items as t', 't.id', '=', 's.tableware_item_id')
                ->whereNotNull('s.presentation_id')->whereNotNull('t.vehicle_vocab_id')->get(['s.presentation_id as dar', 't.vehicle_vocab_id as typ']) as $r) {
                $kandidaten[(int) $r->dar] ??= (int) $r->typ;
            }
        }

        $uebernehmen = 0;
        $uebersprungen = 0;
        foreach ($kandidaten as $darId => $typ) {
            $d = DB::table('foodalchemist_recipe_presentations')->where('id', $darId)->first(['serving_vehicle_vocab_id', 'vessel_gp_id']);
            if ($d === null || $d->serving_vehicle_vocab_id !== null || $d->vessel_gp_id !== null) {
                $uebersprungen++;

                continue;
            }
            $uebernehmen++;
            if ($apply) {
                DB::table('foodalchemist_recipe_presentations')->where('id', $darId)->update(['serving_vehicle_vocab_id' => $typ, 'updated_at' => now()]);
            }
        }

        $ohneGefaess = DB::table('foodalchemist_recipe_presentations')->whereNull('deleted_at')->whereNotNull('serving_vehicle_vocab_id')->whereNull('vessel_gp_id')->count();
        $this->table(['Darreichungen', 'Anzahl'], [
            ['Typ aus altem Geschirr '.($apply ? 'übernommen' : 'würde übernommen'), $uebernehmen],
            ['übersprungen (haben schon Typ oder Gefäß)', $uebersprungen],
            ['mit Typ OHNE Gefäß (bleiben unverändert wirksam)', $ohneGefaess],
        ]);

        return self::SUCCESS;
    }
}
