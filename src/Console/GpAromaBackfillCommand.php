<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Platform\Core\Models\Team;
use Platform\Core\Models\User;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\GpAromaBackfillService;
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
        {--anbieter=plattform : plattform (OpenAI über den Core) | extern (legt KI-Aufträge an, ein MCP-Client beantwortet sie über ki_auftraege.*, danach denselben Lauf wiederholen)}
        {--chunk=25 : GPs je Paket im KI-Teil}
        {--pause=3 : Sekunden Pause zwischen den Paketen}
        {--vergleich=0 : N GPs mit vorhandenem Profil neu schätzen, NICHT speichern, alt/neu als CSV}
        {--neu-schaetzen= : vorhandene Profile dieser Quellen neu schätzen und überschreiben (ai,geschwister,gemini,rule; dazu rezept = gegarte Rezept-Profile). Ein manuelles Profil wird nie angefasst}
        {--bericht= : Ordner für Protokoll und CSV (Standard: storage/app/fa-gp-aroma-backfill)}';

    protected $description = 'Kern-Anker + Geschmacksprofil für GPs mit Lücke nachziehen: Geschwister zuerst (kostenlos), dann KI (Trockenlauf, Kosten, Chunks, Protokoll)';

    public function handle(GpAromaService $aroma, GpAromaBackfillService $service): int
    {
        if (($hinweis = \Platform\FoodAlchemist\Support\Speicher::anheben('1024M')) !== null) {
            $this->warn($hinweis);
        }
        $nur = (string) $this->option('nur');
        if (! in_array($nur, ['anker', 'profil', 'beide'], true)) {
            $this->error('--nur=anker|profil|beide');

            return self::FAILURE;
        }
        $neuQuellen = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('neu-schaetzen')))));
        if (array_diff($neuQuellen, GpAromaBackfillService::NEU_QUELLEN) !== []) {
            $this->error('--neu-schaetzen=' . implode(',', GpAromaBackfillService::NEU_QUELLEN) . ' (manual wird nie neu geschätzt)');

            return self::FAILURE;
        }
        $bericht = (string) ($this->option('bericht') ?: storage_path('app/fa-gp-aroma-backfill'));
        File::ensureDirectoryExists($bericht);
        $stempel = now()->format('Ymd_His');

        if ((int) $this->option('vergleich') > 0) {
            return $this->vergleich($aroma, $service, (int) $this->option('vergleich'), "{$bericht}/{$stempel}_vergleich_alt_neu.csv");
        }

        $apply = (bool) $this->option('apply');
        $team = null;
        $user = null;
        if ($apply && ! $this->option('nur-geschwister')) {          // der KI-Teil braucht einen Nutzer mit Team (Call-Log, Budget, Kill-Switch)
            $user = $this->nutzer();
            if ($user === null) {
                return self::FAILURE;
            }
            $team = $user->currentTeamRelation;
            if (! $team instanceof Team) {
                $this->error('Der Nutzer hat kein aktives Team.');

                return self::FAILURE;
            }
        }

        $ausgabe = function (string $art, mixed $text) use ($apply): void {
            match ($art) {
                'warn' => $this->warn((string) $text),
                'line' => $this->line((string) $text),
                'ergebnis' => $this->zeigeZaehlung($text, $apply),
                default => null,
            };
        };
        $r = $service->lauf($team, $user, [
            'nur' => $nur, 'apply' => $apply, 'nur_geschwister' => (bool) $this->option('nur-geschwister'), 'limit' => (int) $this->option('limit'),
            'extern' => $this->option('anbieter') === 'extern', 'neu_quellen' => $neuQuellen, 'chunk' => (int) $this->option('chunk'),
            'pause' => (int) $this->option('pause'), 'bericht' => $bericht,
        ], $ausgabe);

        if (! $apply || $this->option('nur-geschwister')) {
            $this->comment($apply ? 'Nur Geschwister-Teil (--nur-geschwister), keine KI.' : 'Trockenlauf — nichts geschrieben.');

            return self::SUCCESS;
        }
        $ki = $r['ki'];
        $this->info("KI-Teil fertig: {$ki['bearbeitet']} GPs/Rezepte. Protokoll: {$ki['protokoll']}");
        if ($r['extern']) {
            $z = $ki['zaehler'];
            $this->line(sprintf('Aufträge dieses Laufs: %d angelegt, %d beantwortet, %d verbraucht · Bestand: %d offen, %d beantwortet-liegt, %d verwaist · doppelt: %d (Fingerabdruck instabil, wenn > 0)',
                $z['angelegt'], $z['beantwortet'], $z['verbraucht'], $z['offen'], $z['liegt_beantwortet'], $z['verwaist'], $z['doppelt']));
            $this->comment("Externer Weg: {$ki['offene_auftraege']} offene KI-Aufträge (ki_auftraege.GET → ANTWORT), danach diesen Befehl erneut ausführen — beantwortete Aufträge werden dabei übernommen.");
        }

        return self::SUCCESS;
    }

    /** Tabelle, Kostenschätzung und Geschwister-Zahl vor dem KI-Teil (wie bisher). */
    private function zeigeZaehlung(array $r, bool $apply): void
    {
        $gA = $r['geschwister']['anker'];
        $gP = $r['geschwister']['profil'];
        $this->table(['', 'Lücke', 'nicht pflegbar (Basis-Stamm)', 'Geschwister einig', 'Geschwister uneinig', 'ohne Geschwister', '→ KI'], [
            ['Anker', $r['luecken']['anker'], $gA['gesperrt'], $gA['einig'], $gA['uneinig'], $gA['keine'], $r['ki_geplant']['anker']],
            ['Profil', $r['luecken']['profil'], $gP['gesperrt'], $gP['einig'], $gP['uneinig'], $gP['keine'], $r['ki_geplant']['profil']],
        ]);
        $limit = $r['limit'];
        if ($r['neu'] !== null) {
            $this->line(sprintf('Neu schätzen (%s): %d GP-Profile (%s), %d Rezept-Profile. Manuelle Profile bleiben.', implode(',', $r['neu']['quellen']),
                $r['neu']['gp'], $limit > 0 ? "--limit={$limit} gilt je Teil" : 'alle', $r['neu']['rezepte']));
        }
        $k = $r['kosten'];
        $this->line(sprintf('Kostenschätzung KI-Teil%s: %d Anker-Calls + %d Profil-Calls ≈ %s · Tokens ≈ %s in / %s out', $limit > 0 ? " (--limit={$limit})" : '',
            $r['ki_calls']['anker'], $r['ki_calls']['profil'], $k['eur_unbekannt'] ? 'nicht in € schätzbar (keine Preisdaten im Call-Log)' : number_format($k['eur'], 2, ',', '.').' €',
            number_format($k['tin'], 0, ',', '.'), number_format($k['tout'], 0, ',', '.')));
        foreach ($k['basis'] as $b) {
            $this->line('  · '.$b);
        }
        if ($apply) {
            $this->info("Geschwister übernommen: {$r['geschrieben']['anker']} Anker, {$r['geschrieben']['profil']} Profile.");
        }
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
    private function vergleich(GpAromaService $aroma, GpAromaBackfillService $service, int $n, string $csv): int
    {
        $user = $this->nutzer();
        if ($user === null) {
            return self::FAILURE;
        }
        Auth::login($user);
        $ids = $service->basis()->join('foodalchemist_gp_taste_vectors AS t', 't.gp_id', '=', 'g.id')->whereIn('t.source', ['gemini', 'rule'])
            ->inRandomOrder(27102026)->limit($n)->pluck('g.id')->all();
        $h = fopen($csv, 'wb');
        fwrite($h, "\xEF\xBB\xBF");
        $kopf = ['gp_id', 'name', 'quelle_alt'];
        foreach (SensorikService::DIMS as $d) {
            array_push($kopf, "{$d}_alt", "{$d}_neu");
        }
        array_push($kopf, 'max_abweichung', 'naehrwerte', 'sicherheit', 'tokens_in', 'tokens_out', 'kosten_eur', 'begruendung');
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
            foreach (SensorikService::DIMS as $d) {
                $max = max($max, abs((float) $alt->{$d} - (float) ($v['werte'][$d] ?? 0)));
                array_push($zeile, number_format((float) $alt->{$d}, 2, ',', ''), number_format((float) ($v['werte'][$d] ?? 0), 2, ',', ''));
            }
            array_push($zeile, number_format($max, 2, ',', ''), implode(' ', array_map(fn ($k, $w) => "{$k} {$w}", array_keys($v['naehrwerte']), $v['naehrwerte'])),
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
