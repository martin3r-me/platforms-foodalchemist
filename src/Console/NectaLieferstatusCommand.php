<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Services\Necta\NectaLieferstatusService;
use Platform\FoodAlchemist\Support\MasterTeam;

/**
 * Lieferstatus für den Bestand ableiten (16.10.2026, {@see NectaLieferstatusService}): Artikel mit offener Necta-Preiszeile
 * status '2' (läuft aus / eingestellt) oder mit gesetztem Necta-Lieferstatus (zum Aufheben). Bei „eingestellt“ werden offene
 * Preiszeilen und Necta-Historie begrenzt; Widersprüche (späterer Normalpreis) werden Prüffall. Idempotent. Erst `--probe`.
 */
class NectaLieferstatusCommand extends Command
{
    protected $signature = 'foodalchemist:necta-lieferstatus
        {--team= : Team der Preise (Standard: Master-Team, wie der Nachholer)}
        {--probe : nur zählen, nichts schreiben}';

    protected $description = 'Necta: Lieferstatus (läuft aus / eingestellt) aus den Preiszeilen ableiten, offene Stände begrenzen';

    public function handle(NectaLieferstatusService $dienst): int
    {
        $probe = (bool) $this->option('probe');
        $team = $this->option('team') !== null ? (int) $this->option('team') : MasterTeam::id();
        $z = ['geprueft' => 0, 'auslaufend' => 0, 'eingestellt' => 0, 'aufgehoben' => 0, 'geaendert' => 0, 'preise_begrenzt' => 0, 'eingestellt_widerspruch' => 0];

        $kandidaten = DB::table('foodalchemist_prices')->whereNotNull('legacy_id')->where('team_id', $team)->where('status', '2')
            ->whereNull('valid_to')->whereNull('deleted_at')->select('supplier_item_id')
            ->union(DB::table('foodalchemist_supplier_items')->where('lieferstatus_quelle', NectaLieferstatusService::QUELLE)->select('id AS supplier_item_id'));
        // IDs vorab festhalten: das Begrenzen ändert genau die Zeilen, aus denen die Kandidaten kommen (Offset-Paging würde springen)
        $ids = DB::query()->fromSub($kandidaten, 'k')->distinct()->orderBy('supplier_item_id')->pluck('supplier_item_id')->map(fn ($v) => (int) $v)->all();
        foreach ($ids as $id) {
            $z['geprueft']++;
            $r = $dienst->aktualisieren($id, $team, $probe);
            if ($r['lieferstatus'] !== null) {
                $z[$r['lieferstatus']]++;
            }
            if ($r['geaendert']) {
                $z['geaendert']++;
                $z['aufgehoben'] += $r['lieferstatus'] === null ? 1 : 0;
            }
            $z['preise_begrenzt'] += $r['begrenzt'];
            $z['eingestellt_widerspruch'] += $r['widerspruch'] ? 1 : 0;
        }

        $this->info(($probe ? 'PROBELAUF (schreibt nichts) · ' : '')."Necta-Lieferstatus, Team {$team}");
        $this->table(['Zähler', 'Wert'], collect($z)->map(fn ($v, $k) => [$k, $v])->values()->all());

        return self::SUCCESS;
    }
}
