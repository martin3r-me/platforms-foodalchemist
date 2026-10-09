<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Services\GpNamingService;

/**
 * ONE-SHOT (Spec 80, Paket 11): Verarbeitung/Form dort aus dem GP-NAMEN übernehmen, wo beide FELDER leer sind.
 *
 * Anlass (demo 2026-10-09): von 6.950 freigegebenen GPs tragen 210 eine Verarbeitung und 3 eine Form — „Würfel
 * 5 mm", „gehackt", „ganz" stehen nur im Namen, weil der Editor die Felder bis Paket 11 nur bei der Anlage
 * schrieb. Die Rohform-Korrektur und die Code-Prüfungen (Spec 80 G1) lesen die Felder zuerst.
 *
 * Zerlegung über {@see GpNamingService::felderAusName} (Umkehrung des §6-Renderers, Verarbeitungs-Suffixe aus
 * dem §2-Dossier). Der NAME bleibt unverändert. Standard = Bericht mit Stichprobe; `--apply` schreibt.
 * Auf demo nur nach Freigabe durch Dominique.
 */
class GpFelderBackfillCommand extends Command
{
    protected $signature = 'foodalchemist:gp-felder-backfill
        {--team= : nur dieses Team (Default: alle)}
        {--apply : schreiben (ohne dieses Flag nur Bericht)}
        {--stichprobe=25 : so viele Beispiele im Bericht}';

    protected $description = 'Übernimmt die Verarbeitung (§2-Suffix) aus dem GP-Namen, wo Verarbeitung und Form leer sind (Bericht, --apply schreibt)';

    public function handle(GpNamingService $naming): int
    {
        $q = DB::table('foodalchemist_gps')->whereNull('deleted_at')
            ->where(fn ($w) => $w->whereNull('processing')->orWhere('processing', ''))
            ->where(fn ($w) => $w->whereNull('form')->orWhere('form', ''));
        if (($team = (int) $this->option('team')) > 0) {
            $q->where('team_id', $team);
        }

        $setzbar = [];
        $leer = 0;
        foreach ($q->orderBy('id')->cursor() as $gp) {
            $f = $naming->felderAusName((string) $gp->name);
            // Nur Verarbeitung, und nur wenn ein §2-Suffix sie belegt. Die Form schreibt der Befehl NICHT: das
            // Attribut nach dem Zustand ist oft eine Eigenschaft („Olivenoel: trocken, hochwertig") — das entscheidet
            // der Mensch im Editor („Felder aus dem Namen übernehmen").
            if ($f['processing'] === '') {
                $leer++;

                continue;
            }
            $setzbar[] = ['id' => (int) $gp->id, 'name' => (string) $gp->name, 'processing' => $f['processing']];
        }

        $this->line(sprintf('  %d GPs mit setzbarer Verarbeitung · %d ohne Verarbeitungs-Suffix im Namen', count($setzbar), $leer));
        $this->table(['id', 'Name', 'Verarbeitung'], array_map(
            fn ($r) => [$r['id'], mb_strimwidth($r['name'], 0, 60, '…'), $r['processing']],
            array_slice($setzbar, 0, max(0, (int) $this->option('stichprobe'))),
        ));

        if (! $this->option('apply')) {
            $this->info('Nur Bericht. Mit --apply schreiben.');

            return self::SUCCESS;
        }
        foreach (array_chunk($setzbar, 500) as $block) {
            DB::transaction(function () use ($block) {
                foreach ($block as $r) {
                    DB::table('foodalchemist_gps')->where('id', $r['id'])
                        ->update(['processing' => $r['processing'], 'updated_at' => now()]);
                }
            });
        }
        $this->info(count($setzbar) . ' GPs geschrieben.');

        return self::SUCCESS;
    }
}
