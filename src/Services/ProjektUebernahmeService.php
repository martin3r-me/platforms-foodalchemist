<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\Core\Models\User;
use Platform\FoodAlchemist\Models\FoodAlchemistProjekt;
use Platform\FoodAlchemist\Models\FoodAlchemistProjektStandort;
use Platform\FoodAlchemist\Support\MasterTeam;

/**
 * Spec 82 §9 · „Lizenz erteilt“: Die Agentur-Akte (Projekt im Master-Team) wird in den Mandanten übernommen. KOPIE, nicht live.
 *
 *  - Das Oberteam ist ein VORHANDENES Host-Team (das Modul legt nur Unter-Teams an, {@see TeamAnlegenService}).
 *  - Je Kunden-Standort ein Unter-Team (Pilot zuerst) mit dem Standortprofil als Teamprofil.
 *  - Die Kunden-DNA der CRM-Firma wird zur Team-DNA des Oberteams (feste Feld-Zuordnung unten); „Bleibt“ wird zu „verbindlich“.
 *    Felder, die das Oberteam schon gefüllt hat, bleiben unberührt (werden gemeldet).
 *  - Mandanten-Zeile: aktiv + kuratiert (die Agentur arbeitet weiter im Mandanten), CRM-Firma verknüpft.
 *  - Das Projekt bekommt `mandant_team_id` und wechselt in die Phase „Übergabe & Pflege“. Foodbook und Akte bleiben im Master.
 *
 * Nur Plattform-Admin (der Modus kuratiert öffnet dem Master den Inhaltszugriff, §13 G). Alles in einer Transaktion, einmalig je Projekt.
 */
class ProjektUebernahmeService
{
    use PlattformAdminPruefung;

    /** Kunden-DNA-Feld → Team-DNA-Feld. Der Default-Schreibstil wird NICHT kopiert: Schreibstile gehören dem Master-Team und wären im Oberteam ein toter Verweis. */
    public const DNA_ZUORDNUNG = [
        'marke_positionierung' => 'leitbild',
        'ziel_gaeste_anlaesse' => 'ziel_gaeste',
        'kommunikation_ton' => 'signature_stil',
        'erwartungen_nogos' => 'no_gos',
        'preis_erwartung' => 'preis_positionierung',
    ];

    public function __construct(
        private readonly ProjektService $projekte,
        private readonly TeamAnlegenService $teams,
        private readonly TeamProfilService $profile,
        private readonly CanvasService $canvas,
        private readonly MandantenService $mandanten,
        private readonly PlattformProtokoll $protokoll,
    ) {}

    /**
     * Was die Übernahme täte, ohne etwas zu schreiben.
     *
     * @return array{oberteam: string, unter_teams: list<string>, dna_felder: list<string>, dna_bleibt: int, dna_uebersprungen: list<string>, hindernisse: list<string>}
     */
    public function vorschau(Team $master, ?User $user, int $projektId, Team $oberteam): array
    {
        $this->nurPlattformAdmin($user, 'Lizenz-Übernahme ansehen');
        $p = $this->projekte->finde($master, $projektId);
        $hindernisse = $this->hindernisse($p, $oberteam);
        $standorte = FoodAlchemistProjektStandort::where('projekt_id', $p->id)->orderByDesc('ist_pilot')->orderBy('sort_order')->orderBy('id')->get();
        foreach ($standorte as $s) {
            if (Team::where('parent_team_id', $oberteam->id)->where('name', $s->name)->exists()) {
                $hindernisse[] = 'Im Oberteam gibt es schon ein Unter-Team „'.$s->name.'“.';
            }
        }
        [$felder, $bleibt, $uebersprungen] = $this->dnaPlan($p, $oberteam);

        return ['oberteam' => (string) $oberteam->name, 'unter_teams' => $standorte->pluck('name')->all(), 'dna_felder' => $felder, 'dna_bleibt' => $bleibt,
            'dna_uebersprungen' => $uebersprungen, 'hindernisse' => array_values(array_unique($hindernisse))];
    }

    /** Übernahme ausführen. @return array<string,mixed> Bericht (was entstand) */
    public function uebernehmen(Team $master, ?User $user, int $projektId, Team $oberteam): array
    {
        $akteur = $this->nurPlattformAdmin($user, 'Lizenz-Übernahme');
        $vorschau = $this->vorschau($master, $akteur, $projektId, $oberteam);
        if ($vorschau['hindernisse'] !== []) {
            throw new \RuntimeException('Übernahme nicht möglich: '.implode(' ', $vorschau['hindernisse']));
        }
        $p = $this->projekte->finde($master, $projektId);

        $bericht = DB::transaction(function () use ($master, $akteur, $p, $oberteam) {
            $unterTeams = [];
            $standorte = FoodAlchemistProjektStandort::where('projekt_id', $p->id)->orderByDesc('ist_pilot')->orderBy('sort_order')->orderBy('id')->get();
            foreach ($standorte as $s) {
                $team = $this->teams->unterTeam($oberteam, (string) $s->name, (int) $oberteam->user_id);
                $kopie = $this->profile->kopiereStandortProfil($master, (int) $s->id, $team);
                $unterTeams[] = ['team_id' => (int) $team->id, 'name' => (string) $s->name, 'pilot' => (bool) $s->ist_pilot] + $kopie;
            }

            [$felder, $bleibt, $uebersprungen] = $this->dnaPlan($p, $oberteam);
            $this->dnaKopieren($p, $oberteam, $felder);

            $this->mandanten->setze($oberteam, ['status' => 'aktiv', 'modus' => 'kuratiert', 'crm_company_id' => (int) $p->crm_company_id], $akteur);

            $p->update(['mandant_team_id' => $oberteam->id, 'phase' => 'uebergabe', 'updated_by' => $akteur->id]);

            return ['oberteam_id' => (int) $oberteam->id, 'unter_teams' => $unterTeams, 'dna_felder' => $felder, 'dna_bleibt' => $bleibt, 'dna_uebersprungen' => $uebersprungen];
        });

        $this->protokoll->schreibe('projekt_lizenz_uebernommen', ['projekt_id' => (int) $p->id, 'oberteam_id' => (int) $oberteam->id,
            'unter_teams' => count($bericht['unter_teams']), 'dna_felder' => count($bericht['dna_felder'])], $akteur, $p);

        return $bericht;
    }

    /** @return list<string> */
    private function hindernisse(FoodAlchemistProjekt $p, Team $oberteam): array
    {
        $h = [];
        if ($p->mandant_team_id !== null) {
            $h[] = 'Das Projekt wurde schon in einen Mandanten übernommen.';
        }
        if (MasterTeam::istMaster($oberteam)) {
            $h[] = 'Das Master-Team ist kein Mandant.';
        }
        if ($oberteam->parent_team_id !== null) {
            $h[] = 'Das Oberteam muss ein Haupt-Team sein, kein Unter-Team.';
        }
        if (FoodAlchemistProjekt::where('mandant_team_id', $oberteam->id)->where('id', '!=', $p->id)->exists()) {
            $h[] = 'Dieses Oberteam gehört schon zu einem anderen Projekt.';
        }

        return $h;
    }

    /**
     * Welche Kunden-DNA-Felder landen im Oberteam?
     *
     * @return array{0: list<string>, 1: int, 2: list<string>} [zu kopierende Zielfelder, davon „Bleibt“, übersprungen (Ziel schon gefüllt)]
     */
    private function dnaPlan(FoodAlchemistProjekt $p, Team $oberteam): array
    {
        $kunde = $this->canvas->find('kunde_dna', 'crm_company', (int) $p->crm_company_id);
        if ($kunde === null) {
            return [[], 0, []];
        }
        $ziel = $this->canvas->find('food_dna', 'team', (int) $oberteam->id);
        $felder = [];
        $bleibt = 0;
        $uebersprungen = [];
        foreach (self::DNA_ZUORDNUNG as $von => $nach) {
            $eintrag = $kunde->entries->where('field_key', $von)->firstWhere('position', 0);
            if ($eintrag === null || trim((string) $eintrag->value) === '') {
                continue;
            }
            if ($ziel !== null && trim((string) $ziel->entries->where('field_key', $nach)->firstWhere('position', 0)?->value) !== '') {
                $uebersprungen[] = $nach;

                continue;
            }
            $felder[] = $nach;
            $bleibt += $eintrag->is_binding ? 1 : 0;
        }

        return [$felder, $bleibt, $uebersprungen];
    }

    /** @param list<string> $felder Zielfelder aus dnaPlan */
    private function dnaKopieren(FoodAlchemistProjekt $p, Team $oberteam, array $felder): void
    {
        if ($felder === []) {
            return;
        }
        $kunde = $this->canvas->find('kunde_dna', 'crm_company', (int) $p->crm_company_id);
        $ziel = $this->canvas->canvasFor($oberteam, 'food_dna', 'team', (int) $oberteam->id);
        foreach (self::DNA_ZUORDNUNG as $von => $nach) {
            if (! in_array($nach, $felder, true)) {
                continue;
            }
            $eintrag = $kunde->entries->where('field_key', $von)->firstWhere('position', 0);
            $this->canvas->setSkalar($ziel, $nach, (string) $eintrag->value, (bool) $eintrag->is_binding);   // „Bleibt“ = verbindlich im Oberteam
        }
    }
}
