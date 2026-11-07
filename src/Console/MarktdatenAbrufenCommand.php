<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\MarktdatenService;
use Platform\FoodAlchemist\Support\FaTeam;
use Platform\FoodAlchemist\Support\MasterTeam;

/**
 * Monatlicher Abruf „Markt in Zahlen“ (Dominique 07.11.2026). Holt die festen Destatis-Reihen (GENESIS, kostenlos) und speichert
 * sie im Master-Team — das Observatorium ist zentral kuratiert, alle Mandanten lesen dort ({@see \Platform\FoodAlchemist\Support\ObservatoriumSicht}).
 * Nach dem Speichern stößt {@see MarktdatenService::abrufen} die KI-Einordnung „Was heißt das für dich?“ selbst an, aber nur bei
 * geändertem Zahlenstand. Ohne Master-Team und ohne --team läuft nichts.
 */
class MarktdatenAbrufenCommand extends Command
{
    protected $signature = 'foodalchemist:marktdaten-abrufen
        {--team= : dieses Team (ID) statt des Master-Teams}
        {--probelauf : nur lesen, nichts speichern, keine Einordnung}';

    protected $description = 'Observatorium → Markt in Zahlen von Destatis holen und Einordnung anstoßen';

    public function handle(MarktdatenService $markt): int
    {
        $id = $this->option('team') ? (int) $this->option('team') : MasterTeam::id();
        $team = $id !== null ? Team::find($id) : null;
        if ($team === null) {
            $this->warn('Kein Master-Team konfiguriert und kein --team angegeben — nichts zu tun.');

            return self::SUCCESS;
        }
        $apply = ! $this->option('probelauf');
        $r = FaTeam::imTeam($team, fn () => $markt->abrufen($team, $apply));
        $fehler = 0;
        foreach ($r['ergebnisse'] as $e) {
            if ($e['fehler'] !== null) {
                $fehler++;
                $this->warn(sprintf('  %s: %s', $e['serie'], $e['fehler']));
            } else {
                $this->line(sprintf('  %s: %d Monate, letzter Wert %s', $e['serie'], $e['monate'], $e['letzter_wert_monat'] ?? '—'));
            }
        }
        $this->info(sprintf('Team %d (%s): %d Reihen, %d mit Fehler, %s, Einordnung %s.', $team->id, $team->name, count($r['ergebnisse']), $fehler,
            $apply ? 'gespeichert' : 'Probelauf', $r['einordnung_angestossen'] ? 'angestoßen' : 'nicht nötig'));

        return self::SUCCESS;
    }
}
