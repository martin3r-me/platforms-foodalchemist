<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Platform\Core\Models\Team;
use Platform\Core\Models\User;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\BulkEnrichService;
use Platform\FoodAlchemist\Services\GpAromaService;
use Platform\FoodAlchemist\Services\SensorikService;
use Platform\FoodAlchemist\Support\GpAromaNachholen;

/**
 * Bestand nachziehen: Kern-Anker + Geschmacksprofil für GPs mit Lücke (Kurator/Dominique 27.10.2026; io nach dem Stamm-Ersatz ohne
 * jedes Profil). Erst der kostenlose Geschwister-Teil, dann die KI. Nur Lücken: ein vorhandenes Profil/ein Anker wird nie neu bezahlt.
 *
 *  Standard = Trockenlauf: zählt Lücken, was die Geschwister sofort füllen könnten, was zur KI geht, und schätzt die Kosten aus dem
 *  Mittel der letzten erfolgreichen Calls (`foodalchemist_ai_call_log`, Feature gp.sensorik bzw. gp.anker; ohne Historie: Token-Annahme).
 *  `--apply` schreibt in Chunks mit Pause (off-peak), Lauf-Protokoll als JSON-Lines; abgebrochen = einfach neu starten (Lücken-Logik
 *  ist die Wiederaufnahme). `--nur-geschwister` = ohne KI. `--vergleich=N` schätzt N GPs MIT altem Profil neu, ohne zu speichern,
 *  und schreibt alt gegen neu als CSV (Grundlage für Dominiques Entscheidung zur Neu-Schätzung).
 */
class GpAromaBackfillCommand extends Command
{
    protected $signature = 'foodalchemist:gp-aroma-backfill
        {--admin= : E-Mail des Nutzers, in dessen Namen die KI läuft (Pflicht für KI-Teil und --vergleich; Call-Log, Budget, Kill-Switch)}
        {--nur=beide : anker | profil | beide}
        {--apply : schreiben (ohne: Trockenlauf mit Zählung und Kostenschätzung)}
        {--nur-geschwister : nur den kostenlosen Geschwister-Teil, keine KI}
        {--limit=0 : höchstens so viele GPs in den KI-Teil (0 = alle)}
        {--chunk=25 : GPs je Paket im KI-Teil}
        {--pause=3 : Sekunden Pause zwischen den Paketen}
        {--vergleich=0 : N GPs mit vorhandenem Profil neu schätzen, NICHT speichern, alt/neu als CSV}
        {--bericht= : Ordner für Protokoll und CSV (Standard: storage/app/fa-gp-aroma-backfill)}';

    protected $description = 'Kern-Anker + Geschmacksprofil für GPs mit Lücke nachziehen: Geschwister zuerst (kostenlos), dann KI (Trockenlauf, Kosten, Chunks, Protokoll)';

    /** Token-Annahme ohne Call-Historie (gemessen an recipe.sensorik/gp.anker auf demo, 19.09.). */
    private const ANNAHME = ['gp.sensorik' => ['in' => 1200, 'out' => 250], 'gp.anker' => ['in' => 1500, 'out' => 120]];

    public function handle(GpAromaService $aroma, BulkEnrichService $bulk): int
    {
        if (($hinweis = \Platform\FoodAlchemist\Support\Speicher::anheben('1024M')) !== null) {
            $this->warn($hinweis);
        }
        $nur = (string) $this->option('nur');
        if (! in_array($nur, ['anker', 'profil', 'beide'], true)) {
            $this->error('--nur=anker|profil|beide');

            return self::FAILURE;
        }
        $bericht = (string) ($this->option('bericht') ?: storage_path('app/fa-gp-aroma-backfill'));
        File::ensureDirectoryExists($bericht);
        $stempel = now()->format('Ymd_His');

        if ((int) $this->option('vergleich') > 0) {
            return $this->vergleich($aroma, (int) $this->option('vergleich'), "{$bericht}/{$stempel}_vergleich_alt_neu.csv");
        }

        $ohneAnker = $nur !== 'profil' ? $this->ohneAnker() : [];
        $ohneProfil = $nur !== 'anker' ? $this->ohneProfil() : [];
        $alle = array_values(array_unique(array_merge($ohneAnker, $ohneProfil)));
        sort($alle);
        $istOhneAnker = array_flip($ohneAnker);
        $istOhneProfil = array_flip($ohneProfil);

        // ── Geschwister-Teil (kostenlos) ──
        $gAnker = ['einig' => 0, 'uneinig' => 0, 'keine' => 0];
        $gProfil = ['einig' => 0, 'uneinig' => 0, 'keine' => 0];
        $apply = (bool) $this->option('apply');
        $geschrieben = ['anker' => 0, 'profil' => 0];
        foreach (array_chunk($alle, 500) as $chunk) {
            foreach (FoodAlchemistGp::whereIn('id', $chunk)->get() as $gp) {
                if (isset($istOhneAnker[$gp->id])) {
                    $gAnker[$aroma->geschwisterAnker($gp)['status']]++;
                }
                if (isset($istOhneProfil[$gp->id])) {
                    $gProfil[$aroma->geschwisterProfil($gp)['status']]++;
                }
                if ($apply) {
                    try {
                        $geschrieben['anker'] += $nur !== 'profil' && $aroma->ankerVonGeschwistern($gp) > 0 ? 1 : 0;
                        $geschrieben['profil'] += $nur !== 'anker' && $aroma->profilVonGeschwistern($gp) ? 1 : 0;
                    } catch (\Throwable $e) {
                        $this->warn("GP {$gp->id}: {$e->getMessage()}");
                    }
                }
            }
        }
        $kiAnker = $gAnker['uneinig'] + $gAnker['keine'];
        $kiProfil = $gProfil['uneinig'] + $gProfil['keine'];
        $this->table(['', 'Lücke', 'Geschwister einig', 'Geschwister uneinig', 'ohne Geschwister', '→ KI'], [
            ['Anker', count($ohneAnker), $gAnker['einig'], $gAnker['uneinig'], $gAnker['keine'], $kiAnker],
            ['Profil', count($ohneProfil), $gProfil['einig'], $gProfil['uneinig'], $gProfil['keine'], $kiProfil],
        ]);
        $limit = (int) $this->option('limit');
        $nAnker = $limit > 0 ? min($limit, $kiAnker) : $kiAnker;
        $nProfil = $limit > 0 ? min($limit, $kiProfil) : $kiProfil;
        $kosten = $this->kosten('gp.anker', $nAnker) + $this->kosten('gp.sensorik', $nProfil);
        $this->line(sprintf('Kostenschätzung KI-Teil%s: %d Anker-Calls + %d Profil-Calls ≈ %s € (%s)', $limit > 0 ? " (--limit={$limit})" : '',
            $nAnker, $nProfil, number_format($kosten['eur'], 2, ',', '.'), $kosten['basis']));
        if ($apply) {
            $this->info("Geschwister übernommen: {$geschrieben['anker']} Anker, {$geschrieben['profil']} Profile.");
        }
        if (! $apply || $this->option('nur-geschwister')) {
            $this->comment($apply ? 'Nur Geschwister-Teil (--nur-geschwister), keine KI.' : 'Trockenlauf — nichts geschrieben.');

            return self::SUCCESS;
        }

        // ── KI-Teil ──
        $user = $this->nutzer();
        if ($user === null) {
            return self::FAILURE;
        }
        Auth::login($user);
        $team = $user->currentTeamRelation;
        if (! $team instanceof Team) {
            $this->error('Der Nutzer hat kein aktives Team.');

            return self::FAILURE;
        }
        $offen = array_values(array_unique(array_merge($nur !== 'profil' ? $this->ohneAnker() : [], $nur !== 'anker' ? $this->ohneProfil() : [])));
        sort($offen);
        if ($limit > 0) {
            $offen = array_slice($offen, 0, $limit);
        }
        $protokoll = "{$bericht}/{$stempel}_aroma_protokoll.jsonl";
        $n = 0;
        foreach (array_chunk($offen, max(1, (int) $this->option('chunk'))) as $i => $chunk) {
            if ($i > 0 && (int) $this->option('pause') > 0) {
                sleep((int) $this->option('pause'));
            }
            foreach ($chunk as $gpId) {
                $gp = FoodAlchemistGp::find($gpId);
                if (! $aroma->anreicherbar($gp) || ! $gp->isOwnedBy($team)) {
                    continue;
                }
                $schritte = array_values(array_filter(GpAromaNachholen::schritte($gpId),
                    fn ($s) => $nur === 'beide' || ($nur === 'anker' && $s === 'anker') || ($nur === 'profil' && $s === 'sensorik')));
                if ($schritte === []) {
                    continue;                                            // inzwischen gefüllt (Wiederaufnahme)
                }
                try {
                    $r = $bulk->nachziehenGp($team, [$gpId], $schritte, 'backfill');
                } catch (\Throwable $e) {
                    $r = ['fehler' => mb_strimwidth($e->getMessage(), 0, 300)];
                }
                File::append($protokoll, json_encode(['gp_id' => $gpId, 'schritte' => $schritte, 'zeit' => now()->toIso8601String()] + $r, JSON_UNESCAPED_UNICODE)."\n");
                $n++;
            }
            $this->line("Paket ".($i + 1).": {$n} GPs bearbeitet");
        }
        $this->info("KI-Teil fertig: {$n} GPs. Protokoll: {$protokoll}");

        return self::SUCCESS;
    }

    /** @return list<int> lebende, gepflegte GPs ohne Anker */
    private function ohneAnker(): array
    {
        return $this->basis()->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('foodalchemist_gp_anchor_mappings AS m')
            ->whereColumn('m.gp_id', 'g.id')->whereNull('m.deleted_at'))->pluck('g.id')->map(fn ($v) => (int) $v)->all();
    }

    /** @return list<int> lebende, gepflegte GPs ohne brauchbares Profil (keins oder lauter Nullen, nie manual) */
    private function ohneProfil(): array
    {
        $summe = implode(' + ', array_map(fn ($d) => "t.{$d}", SensorikService::DIMS));

        return $this->basis()->leftJoin('foodalchemist_gp_taste_vectors AS t', 't.gp_id', '=', 'g.id')
            ->where(fn ($q) => $q->whereNull('t.id')->orWhere(fn ($w) => $w->whereRaw("({$summe}) = 0")->where(fn ($s) => $s->whereNull('t.source')->orWhere('t.source', '!=', 'manual'))))
            ->pluck('g.id')->map(fn ($v) => (int) $v)->all();
    }

    private function basis(): \Illuminate\Database\Query\Builder
    {
        return DB::table('foodalchemist_gps AS g')->whereNull('g.deleted_at')->whereNull('g.merged_into_id')
            ->where('g.is_platzhalter', false)->whereIn('g.status', ['approved', 'tentative'])->orderBy('g.id');
    }

    /** @return array{eur: float, basis: string} */
    private function kosten(string $feature, int $n): array
    {
        if ($n === 0) {
            return ['eur' => 0.0, 'basis' => 'keine Calls'];
        }
        $h = DB::table('foodalchemist_ai_call_log')->where('feature', $feature)->whereNull('error')->orderByDesc('id')->limit(200)
            ->selectRaw('COUNT(*) AS n, AVG(kosten_usd * COALESCE(usd_eur, 0.92)) AS eur, AVG(tokens_in) AS tin, AVG(tokens_out) AS tout')->first();
        if ($h !== null && (int) $h->n > 0 && $h->eur !== null) {
            return ['eur' => $n * (float) $h->eur, 'basis' => "{$feature}: Ø ".number_format((float) $h->eur, 4, ',', '.')." € aus {$h->n} Calls"];
        }
        // Ohne Kosten-Historie: Token-Mittel bzw. -Annahme × Preis der Klasse (Preis je Mio. aus dem letzten Call irgendeines Features)
        $tin = $h !== null && (int) $h->n > 0 ? (float) $h->tin : self::ANNAHME[$feature]['in'];
        $tout = $h !== null && (int) $h->n > 0 ? (float) $h->tout : self::ANNAHME[$feature]['out'];
        $preis = DB::table('foodalchemist_ai_call_log')->whereNotNull('preis_in_mio')->orderByDesc('id')->first(['preis_in_mio', 'preis_out_mio', 'usd_eur', 'model']);
        if ($preis === null) {
            return ['eur' => 0.0, 'basis' => "{$feature}: keine Preisdaten — Token-Schätzung ".round($tin).' in / '.round($tout).' out je Call'];
        }
        $eur = ($tin * (float) $preis->preis_in_mio + $tout * (float) $preis->preis_out_mio) / 1_000_000 * (float) ($preis->usd_eur ?: 0.92);

        return ['eur' => $n * $eur, 'basis' => "{$feature}: ".round($tin).'/'.round($tout).' Token × Preis '.$preis->model.' (Annahme)'];
    }

    private function nutzer(): ?User
    {
        $mail = strtolower(trim((string) $this->option('admin')));
        $user = $mail !== '' ? User::whereRaw('LOWER(email) = ?', [$mail])->first() : null;
        if ($user === null) {
            $this->error($mail === '' ? '--admin=<E-Mail> fehlt (die KI läuft im Namen eines Nutzers).' : "Kein Benutzer mit der E-Mail {$mail}.");
        }

        return $user;
    }

    /** Probelauf: N GPs mit vorhandenem (altem) Profil neu schätzen, nichts speichern, alt/neu nebeneinander. */
    private function vergleich(GpAromaService $aroma, int $n, string $csv): int
    {
        $user = $this->nutzer();
        if ($user === null) {
            return self::FAILURE;
        }
        Auth::login($user);
        $ids = $this->basis()->join('foodalchemist_gp_taste_vectors AS t', 't.gp_id', '=', 'g.id')->whereIn('t.source', ['gemini', 'rule'])
            ->inRandomOrder(27102026)->limit($n)->pluck('g.id')->all();
        $h = fopen($csv, 'wb');
        fwrite($h, "\xEF\xBB\xBF");
        $kopf = ['gp_id', 'name', 'quelle_alt'];
        foreach (GpAromaService::NIE_GEMESSEN as $d) {
            array_push($kopf, "{$d}_alt", "{$d}_neu");
        }
        array_push($kopf, 'max_abweichung', 'gemessen', 'sicherheit', 'tokens_in', 'tokens_out', 'kosten_eur', 'begruendung');
        fputcsv($h, $kopf, ';', '"', '');
        $summe = 0.0;
        foreach ($ids as $id) {
            $gp = FoodAlchemistGp::with('commodity_group')->find($id);
            $alt = DB::table('foodalchemist_gp_taste_vectors')->where('gp_id', $id)->first();
            try {
                $v = $aroma->schaetze($gp);
            } catch (\Throwable $e) {
                $this->warn("GP {$id}: {$e->getMessage()}");

                continue;
            }
            if ($v === null) {
                continue;
            }
            $log = $v['call_log_id'] !== null ? DB::table('foodalchemist_ai_call_log')->where('id', $v['call_log_id'])->first(['tokens_in', 'tokens_out', 'kosten_usd', 'usd_eur']) : null;
            $eur = $log?->kosten_usd !== null ? (float) $log->kosten_usd * (float) ($log->usd_eur ?: 0.92) : null;
            $summe += (float) $eur;
            $zeile = [$id, $gp->name, $alt->source];
            $max = 0.0;
            foreach (GpAromaService::NIE_GEMESSEN as $d) {
                $max = max($max, abs((float) $alt->{$d} - (float) ($v['werte'][$d] ?? 0)));
                array_push($zeile, number_format((float) $alt->{$d}, 2, ',', ''), number_format((float) ($v['werte'][$d] ?? 0), 2, ',', ''));
            }
            array_push($zeile, number_format($max, 2, ',', ''), implode(' ', array_map(fn ($k, $w) => "{$k} {$w}", array_keys($v['gemessen']), $v['gemessen'])),
                number_format($v['confidence'], 2, ',', ''), $log?->tokens_in, $log?->tokens_out, $eur !== null ? number_format($eur, 5, ',', '') : '', $v['reasoning']);
            fputcsv($h, array_map(fn ($x) => $x === null ? '' : (string) $x, $zeile), ';', '"', '');
        }
        fclose($h);
        $this->info(count($ids)." GPs verglichen, Kosten {$this->eur($summe)} € (je GP {$this->eur(count($ids) ? $summe / count($ids) : 0)} €, auf 6.800 GPs ≈ {$this->eur(count($ids) ? $summe / count($ids) * 6800 : 0)} €). CSV: {$csv}");

        return self::SUCCESS;
    }

    private function eur(float $x): string
    {
        return number_format($x, 4, ',', '.');
    }
}
