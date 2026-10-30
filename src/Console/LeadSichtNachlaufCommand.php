<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\Preis\LeadSichtNachlauf;

/**
 * Lead-Sicht (Kurator 29.10.2026): Mandanten, deren globaler Lead-Artikel für sie nicht sichtbar ist, rechnen ihr EK jetzt mit dem Ersatz nach Rangliste
 * statt dem Ø. Ohne --apply nur die Vorschau (dieselbe wie MCP `foodalchemist.lead_sicht.GET`); --apply markiert die Team-Kosten veraltet und rechnet
 * die eigenen Rezepte neu.
 */
class LeadSichtNachlaufCommand extends Command
{
    protected $signature = 'foodalchemist:lead-sicht-nachlauf
        {--team= : nur dieses Team (ID); default: alle Mandanten mit Stamm-Paket}
        {--apply : ausführen; ohne = nur Vorschau}';

    protected $description = 'Lead-Sicht: Vorschau/Nachlauf für Mandanten mit unsichtbarem globalem Lead (Team-Kosten veraltet + eigene Rezepte neu).';

    public function handle(LeadSichtNachlauf $nachlauf): int
    {
        $team = $this->option('team') ? Team::find((int) $this->option('team')) : null;
        if ($this->option('team') && $team === null) {
            $this->error('Kein Team gefunden (--team=ID prüfen).');

            return self::FAILURE;
        }

        $vorschau = $nachlauf->vorschau($team);
        foreach ($vorschau as $t) {
            $this->info("Team {$t['team_id']} ({$t['team']}) — " . count($t['gps']) . ' GPs mit unsichtbarem Lead, '
                . count($t['rezepte_eigen']) . ' eigene Rezepte, ' . $t['kosten_zeilen'] . ' Team-Kosten-Zeilen');
            $this->table(['GP', 'Lead bisher (unsichtbar)', 'künftig'], array_map(fn ($g) => [
                $g['gp_id'] . ' ' . $g['gp'], $g['lead_la_id'] . ' ' . $g['lead_lieferant'],
                $g['neu_la_id'] !== null ? $g['neu_la_id'] . ' ' . $g['neu_lieferant'] : '— kein sichtbarer Ersatz (Ø/unbepreist)',
            ], $t['gps']));
        }
        if ($vorschau === []) {
            $this->info('Keine Mandanten mit unsichtbarem Lead in Rezepten.');
        }
        if (! $this->option('apply')) {
            $this->warn('Vorschau — nichts geschrieben. Ausführen mit --apply.');

            return self::SUCCESS;
        }

        $erg = $nachlauf->anwenden($team);
        $this->info("Nachlauf: {$erg['teams']} Teams, {$erg['kosten_veraltet']} Team-Kosten veraltet, {$erg['rezepte_neu']} Rezepte neu gerechnet.");
        foreach ($erg['fehler'] as $id => $msg) {
            $this->warn("Rezept {$id}: {$msg}");
        }

        return $erg['fehler'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
