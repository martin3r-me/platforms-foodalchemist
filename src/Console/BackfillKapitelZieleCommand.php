<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\FoodbookService;

/**
 * Spec 19 E4.5 — Backfill Slot-Ziele → Kapitel-Ziele.
 *
 * `strukturAusGeruest` stempelt die SOLL-Ziele (target_count/price_anchor/min/max) nur bei
 * der NEU-Anlage eines Kapitels (ab E4.1). Slot↔Kapitel-Kopplungen, die VOR E4.1 entstanden
 * sind, tragen ihre Slot-Ziele nie ans Kapitel weiter. Dieser Command holt das nach:
 * pro team-eigenem Kapitel werden nur die Ziel-Felder gestempelt, die am Kapitel noch NULL
 * sind (bereits gesetzte bleiben unangetastet) → **idempotent** (zweiter Lauf schreibt nichts).
 *
 * Default = Dry-Run (nur Report); --apply schreibt. --team=ID schränkt ein (sonst alle Teams),
 * --foodbook=ID nur ein Foodbook (erfordert --team, da team-scoped).
 */
class BackfillKapitelZieleCommand extends Command
{
    protected $signature = 'foodalchemist:backfill-kapitel-ziele
        {--team= : Team-ID (default: alle Teams)}
        {--foodbook= : nur dieses Foodbook (id) — erfordert --team}
        {--apply : ausführen; ohne = Dry-Run (nur Report)}';

    protected $description = 'E4.5: Slot-Ziele auf bestehende Kapitel-Kopplungen stempeln (idempotent).';

    public function handle(FoodbookService $svc): int
    {
        $apply = (bool) $this->option('apply');
        $fbId = $this->option('foodbook') !== null ? (int) $this->option('foodbook') : null;

        if ($fbId !== null && $this->option('team') === null) {
            $this->error('--foodbook erfordert --team (Kapitel sind team-scoped).');

            return self::FAILURE;
        }

        if ($this->option('team') !== null) {
            $team = Team::find((int) $this->option('team'));
            if ($team === null) {
                $this->error('Team ' . $this->option('team') . ' nicht gefunden.');

                return self::FAILURE;
            }
            $teams = collect([$team]);
        } else {
            $teams = Team::all();
        }

        $slots = 0;
        $kapitel = 0;
        $felder = 0;
        foreach ($teams as $team) {
            try {
                $r = $svc->backfillSlotZiele($team, $fbId, $apply);
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
                $this->error("Foodbook {$fbId} nicht sichtbar für Team {$team->id}.");

                return self::FAILURE;
            }
            $slots += $r['slots_geprueft'];
            $kapitel += $r['kapitel_gestempelt'];
            $felder += $r['felder_gesetzt'];
            foreach ($r['protokoll'] as $p) {
                $this->line(sprintf('  Team %d · Kapitel %d (%s): %s', $team->id, $p['chapter_id'], $p['slot'], implode(', ', $p['felder'])));
            }
        }

        $modus = $apply ? 'GESTEMPELT' : 'DRY-RUN (würde stempeln)';
        $this->info(sprintf('%s — %d Slots geprüft, %d Kapitel · %d Felder.', $modus, $slots, $kapitel, $felder));
        if (! $apply && $kapitel > 0) {
            $this->warn('Mit --apply ausführen (vorher DB-Backup).');
        }

        return self::SUCCESS;
    }
}
