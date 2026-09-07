<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Services\GpNamingService;

/**
 * ONE-SHOT: `condition` (§9-Zustand) dort nachtragen, wo der GP-NAME ihn trägt, das FELD
 * aber leer ist.
 *
 * Anlass (2026-09-07, Lauf 65 „Creme-Suppe: Tomate-Speck"): GP 13757 heisst
 * „Tomaten: TK, getrocknet" und hat `condition = NULL`. Das ist kein Schoenheitsfehler,
 * es kippt den Matcher:
 *
 *   `MatchHeuristics::zustandClassResolved` nimmt das FELD, wenn es gefuellt ist, und faellt
 *   sonst auf Namens-Token zurueck. Bei NULL greift der Fallback, findet „getrocknet" und
 *   klassifiziert das TK-Produkt als **`preserved`** — es steht damit direkt gegen
 *   „Tomaten / Pelati: konserviert, ganz". Unter `preserved_first` sind beide +3, beide nicht
 *   `isProcessed`, es entscheidet die kleinere `id`. Mit `condition = 'TK'` wird die Klasse
 *   `frozen` (+1) und Pelati gewinnt mit +3 — sauber statt per ID-Glueck.
 *
 * Zweitschaden: jede zustand-basierte Pruefung ist bei NULL blind, und der Kandidaten-Payload
 * an den Generator zeigt „Zustand unbekannt" fuer ein Produkt, dessen Zustand im Namen steht.
 *
 * KONSERVATIV wie das Vault-Skript 208: nur EINDEUTIGE Faelle. Steht mehr als ein
 * §9-Token im Namen (z. B. „frisch / TK"), bleibt das Feld NULL und der Fall kommt in die
 * Review-Liste — Raten waere hier schlimmer als Luecke lassen. „getrocknet" allein ist KEIN
 * Zustand (das ist Verarbeitung) und setzt nichts.
 */
class GpZustandBackfillCommand extends Command
{
    protected $signature = 'foodalchemist:gp-zustand-backfill
        {--team= : nur dieses Team (Default: alle)}
        {--apply : schreiben (ohne dieses Flag nur Bericht)}
        {--verify : nur nachzaehlen, was noch offen ist}';

    protected $description = 'Traegt condition (§9-Zustand) nach, wo der GP-Name ihn eindeutig nennt und das Feld leer ist';

    /**
     * Erkennung im NAMEN. Wort-Boundary, damit „TKühlung" o. ae. nicht traegt; die
     * Reihenfolge ist irrelevant, weil MEHRDEUTIGKEIT ohnehin zur Ablehnung fuehrt.
     */
    private const MUSTER = [
        'frisch' => '/(?<![\p{L}])frisch(?![\p{L}])/u',
        'TK' => '/(?<![\p{L}])(TK|tiefgek(ue|ü)hlt)(?![\p{L}])/u',
        'trocken' => '/(?<![\p{L}])trocken(?![\p{L}])/u',
        'konserviert' => '/(?<![\p{L}])konserviert(?![\p{L}])/u',
    ];

    public function handle(GpNamingService $naming): int
    {
        $q = DB::table('foodalchemist_gps')
            ->whereNull('deleted_at')
            ->where(fn ($w) => $w->whereNull('condition')->orWhere('condition', ''));
        if (($team = (int) $this->option('team')) > 0) {
            $q->where('team_id', $team);
        }
        $offen = $q->orderBy('id')->get(['id', 'team_id', 'name', 'status']);

        $setzbar = [];
        $mehrdeutig = [];
        foreach ($offen as $gp) {
            $treffer = [];
            foreach (self::MUSTER as $zustand => $muster) {
                if (preg_match($muster, (string) $gp->name) === 1) {
                    $treffer[] = $zustand;
                }
            }
            $treffer = array_values(array_unique($treffer));
            if (count($treffer) === 1 && in_array($treffer[0], GpNamingService::ZUSTAND_VOCAB, true)) {
                $setzbar[] = [$gp, $treffer[0]];
            } elseif (count($treffer) > 1) {
                $mehrdeutig[] = [$gp, $treffer];
            }
        }

        $ohneToken = $offen->count() - count($setzbar) - count($mehrdeutig);
        $this->line(sprintf('  %d GPs mit leerem condition · %d eindeutig setzbar · %d mehrdeutig · %d ohne §9-Token im Namen',
            $offen->count(), count($setzbar), count($mehrdeutig), $ohneToken));

        if ($this->option('verify')) {
            // „Offen" heisst hier: NAME nennt einen Zustand, FELD ist leer. Ein GP ohne Token
            // im Namen ist kein Backfill-Fall (er braucht eine Kuration, keine Ableitung).
            $this->line(count($setzbar) === 0
                ? '  ✓ kein eindeutiger Fall mehr offen'
                : '  ⚠ '.count($setzbar).' eindeutige Faelle noch offen — mit --apply nachtragen');

            return count($setzbar) === 0 ? self::SUCCESS : self::FAILURE;
        }

        foreach (array_slice($setzbar, 0, 40) as [$gp, $zustand]) {
            $this->line(sprintf('    %-8s %-12s %s', $gp->id, $zustand, mb_strimwidth((string) $gp->name, 0, 80, '…')));
        }
        if (count($setzbar) > 40) {
            $this->line('    … und '.(count($setzbar) - 40).' weitere');
        }
        if ($mehrdeutig !== []) {
            $this->newLine();
            $this->line('  MEHRDEUTIG — bleiben NULL, Kuration von Hand:');
            foreach (array_slice($mehrdeutig, 0, 20) as [$gp, $treffer]) {
                $this->line(sprintf('    %-8s [%s] %s', $gp->id, implode('|', $treffer), mb_strimwidth((string) $gp->name, 0, 70, '…')));
            }
            if (count($mehrdeutig) > 20) {
                $this->line('    … und '.(count($mehrdeutig) - 20).' weitere');
            }
        }

        if (! $this->option('apply')) {
            $this->newLine();
            $this->line('  Bericht only — mit --apply schreiben.');

            return self::SUCCESS;
        }

        // Einzeln statt Bulk: die Zuordnung id→zustand ist je Zeile verschieden, und ein
        // fehlgeschlagener Einzel-Update soll die anderen nicht mitnehmen.
        $geschrieben = 0;
        foreach ($setzbar as [$gp, $zustand]) {
            $geschrieben += DB::table('foodalchemist_gps')->where('id', $gp->id)
                // Doppelt gesichert: nur solange das Feld WIRKLICH leer ist (paralleler Lauf).
                ->where(fn ($w) => $w->whereNull('condition')->orWhere('condition', ''))
                ->update(['condition' => $zustand, 'updated_at' => now()]);
        }
        $this->info(sprintf('  %d condition-Felder nachgetragen.', $geschrieben));
        $this->line('  Danach faellig: Matcher-Gegenprobe (gps.MATCH) — die Zustands-Klasse aendert sich.');

        return self::SUCCESS;
    }
}
