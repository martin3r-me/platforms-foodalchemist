<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Support\GpAnkerVorauswahl;

/**
 * io-Lauf 01.11.2026: Steht der richtige Kern-Anker in der Vorauswahl? Stichprobe aus GPs mit bekanntem Anker (Katalog- oder Hand-Mapping),
 * alte Vorauswahl (ganzer Name, ohne Geschwister, ohne Warengruppen-Filter) gegen die neue ({@see GpAnkerVorauswahl}). **Read-only.**
 * Ohne Embedding-Provider fehlt in beiden Varianten der semantische Teil; die Spalte `semantik` sagt es.
 */
class GpAnkerVorauswahlMessenCommand extends Command
{
    protected $signature = 'foodalchemist:gp-anker-vorauswahl-messen
        {--stichprobe=30 : Anzahl GPs}
        {--seed=1101 : Zufallssaat (gleiche Saat = gleiche Stichprobe)}
        {--team= : Sicht dieses Teams für die Geschwister (ID); ohne = alle GPs}
        {--quellen=inspire_katalog,manual : Mapping-Quellen, die als bekannter Anker gelten}
        {--json : Maschinen-lesbare Ausgabe}';

    protected $description = 'Misst, wie oft der bekannte Kern-Anker in der alten und der neuen gp.anker-Vorauswahl steht — read-only.';

    public function handle(GpAnkerVorauswahl $vorauswahl): int
    {
        $team = $this->option('team') ? Team::find((int) $this->option('team')) : null;
        if ($this->option('team') && $team === null) {
            $this->error('Team nicht gefunden.');

            return self::FAILURE;
        }
        $bekannt = DB::table('foodalchemist_gp_anchor_mappings AS m')
            ->join('foodalchemist_gps AS g', 'g.id', '=', 'm.gp_id')
            ->join('foodalchemist_vocab_pairing_anchors AS a', 'a.id', '=', 'm.anchor_id')
            ->whereNull('m.deleted_at')->whereNull('g.deleted_at')->whereNull('g.merged_into_id')->whereNull('a.deleted_at')
            ->where('m.role', 'kern')->whereIn('m.source', array_filter(explode(',', (string) $this->option('quellen'))))
            ->orderBy('g.id')->get(['g.id', 'a.slug'])->groupBy('id')->map(fn ($z) => $z->pluck('slug')->all());
        $ids = $bekannt->keys()->all();
        mt_srand((int) $this->option('seed'));
        shuffle($ids);
        $ids = array_slice($ids, 0, max(1, (int) $this->option('stichprobe')));

        $zeilen = [];
        foreach (FoodAlchemistGp::withoutGlobalScopes()->whereIn('id', $ids)->orderBy('id')->get() as $gp) {
            $soll = $bekannt[$gp->id];
            $alt = $vorauswahl->alt($gp);
            $q = $vorauswahl->quellen($gp, $team);
            $neu = $vorauswahl->fuer($gp, $team);
            $zeilen[] = [
                'gp' => (int) $gp->id, 'name' => (string) $gp->name, 'wg' => (string) $gp->commodity_group_code, 'soll' => implode('|', $soll),
                'alt' => array_intersect($soll, $alt) !== [], 'neu' => array_intersect($soll, $neu) !== [],
                'n_alt' => count($alt) - 1, 'n_neu' => count($neu) - 1,
                'durch' => implode('+', array_keys(array_filter($q, fn ($l) => array_intersect($soll, $l) !== []))),
            ];
        }
        $n = count($zeilen);
        $summe = [
            'stichprobe' => $n, 'semantik' => app(\Platform\FoodAlchemist\Services\Ai\KnowledgeEmbeddingService::class)->isProviderAvailable(),
            'treffer_alt' => count(array_filter($zeilen, fn ($z) => $z['alt'])), 'treffer_neu' => count(array_filter($zeilen, fn ($z) => $z['neu'])),
            'listenlaenge_alt' => $n > 0 ? round(array_sum(array_column($zeilen, 'n_alt')) / $n, 1) : 0,
            'listenlaenge_neu' => $n > 0 ? round(array_sum(array_column($zeilen, 'n_neu')) / $n, 1) : 0,
        ];
        if ($this->option('json')) {
            $this->line(json_encode(['summe' => $summe, 'zeilen' => $zeilen], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }
        $this->table(['GP', 'Name', 'WG', 'Soll', 'alt', 'neu', 'Länge alt', 'Länge neu', 'gefunden über'], array_map(fn ($z) => [
            $z['gp'], mb_strimwidth($z['name'], 0, 50, '…'), $z['wg'], $z['soll'], $z['alt'] ? 'ja' : '–', $z['neu'] ? 'ja' : '–', $z['n_alt'], $z['n_neu'], $z['durch'],
        ], $zeilen));
        $this->info("Treffer alt {$summe['treffer_alt']}/{$n}, neu {$summe['treffer_neu']}/{$n} · Ø Länge alt {$summe['listenlaenge_alt']}, neu {$summe['listenlaenge_neu']}"
            .' · Semantik '.($summe['semantik'] ? 'an' : 'AUS (kein Provider — nur Lexik + Geschwister gemessen)'));

        return self::SUCCESS;
    }
}
