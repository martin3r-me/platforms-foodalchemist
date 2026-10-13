<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\Team;
use Platform\Core\Models\User;
use Platform\FoodAlchemist\Models\FoodAlchemistTeamStammOptin;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Support\StammPaket;

/**
 * Spec 82 §13 B1 — Stamm-Paket buchen und abbestellen (nur Plattform-Admin).
 *
 * Das Paket gehört dem Mandanten = Kunden-Hauptteam (Unter-Teams erben, das Master-Team hat es immer). Beim Abbestellen
 * zeigt das System, welche Abos dadurch ungültig würden (Abo setzt Paket voraus, Entscheidung 1 = C); mit Abos ist eine
 * ausdrückliche Bestätigung nötig. Jede Aktion wird protokolliert. Die Tabelle foodalchemist_team_stamm_optins bleibt die Quelle der Sichtbarkeit.
 */
class StammPaketService
{
    use PlattformAdminPruefung;

    /** Für lesende Aufrufer (MCP, Oberfläche): wirft FaRechtFehltException, wenn nicht Plattform-Admin. */
    public function nurPlattformAdminOeffentlich(?User $user, string $wofuer): User
    {
        return $this->nurPlattformAdmin($user, $wofuer);
    }

    public function __construct(
        private readonly FaRechte $rechte,
        private readonly AboAuskunft $abos,
        private readonly PlattformProtokoll $protokoll,
    ) {}

    /**
     * Alle Mandanten (Kunden-Hauptteams ohne das Master-Team) mit Paketstatus.
     *
     * @return list<array{team_id:int, name:string, gebucht:bool, seit:?string, von:?int, abos:int}>
     */
    public function uebersicht(): array
    {
        $optins = FoodAlchemistTeamStammOptin::all()->keyBy('team_id');
        $master = MasterTeam::id();

        return Team::whereNull('parent_team_id')->orderBy('name')->get(['id', 'name'])
            ->reject(fn ($t) => $master !== null && (int) $t->id === $master)
            ->map(function ($t) use ($optins) {
                $o = $optins->get($t->id);

                return ['team_id' => (int) $t->id, 'name' => (string) $t->name, 'gebucht' => $o !== null, 'seit' => $o?->opted_in_at?->format('d.m.Y'),
                    'von' => $o?->opted_in_by, 'abos' => count($this->abos->abosAmStammPaket($t))];
            })->values()->all();
    }

    /** @return array{team_id:int, gebucht:bool, seit:?string, ist_master:bool} */
    public function status(Team $mandant): array
    {
        $o = FoodAlchemistTeamStammOptin::where('team_id', $mandant->id)->first();

        return ['team_id' => (int) $mandant->id, 'gebucht' => $o !== null, 'seit' => $o?->opted_in_at?->toDateString(), 'ist_master' => MasterTeam::istMaster($mandant)];
    }

    /** Was das Abbestellen bewirkt. @return array{team_id:int, gebucht:bool, abos:list<array<string,mixed>>, bestaetigung_noetig:bool} */
    public function vorschauAbbestellen(Team $mandant): array
    {
        $abos = $this->abos->abosAmStammPaket($mandant);

        return ['team_id' => (int) $mandant->id, 'gebucht' => $this->status($mandant)['gebucht'], 'abos' => $abos, 'bestaetigung_noetig' => $abos !== []];
    }

    public function buchen(Team $mandant, ?User $user): array
    {
        $admin = $this->nurPlattformAdmin($user, 'Stamm-Paket buchen');
        $this->pruefeMandant($mandant);
        $war = $this->status($mandant)['gebucht'];
        $optin = StammPaket::freischalten($mandant, $admin->id);
        if (! $war) {
            $this->protokoll->schreibe('stamm_paket_gebucht', ['team_id' => $mandant->id, 'team' => $mandant->name], $admin, $optin);
        }

        return $this->status($mandant);
    }

    public function abbestellen(Team $mandant, ?User $user, bool $bestaetigt = false): array
    {
        $admin = $this->nurPlattformAdmin($user, 'Stamm-Paket abbestellen');
        $this->pruefeMandant($mandant);
        $vorschau = $this->vorschauAbbestellen($mandant);
        if ($vorschau['gebucht'] && $vorschau['bestaetigung_noetig'] && ! $bestaetigt) {
            throw new \RuntimeException(sprintf('Das Abbestellen macht %d Abo(s) ungültig. Bitte die Vorschau prüfen und ausdrücklich bestätigen.', count($vorschau['abos'])));
        }
        $optin = FoodAlchemistTeamStammOptin::where('team_id', $mandant->id)->first();   // Subjekt des Eintrags: auch die gelöschte Zeile trägt ihn
        if (StammPaket::abbestellen($mandant)) {
            $this->protokoll->schreibe('stamm_paket_abbestellt', ['team_id' => $mandant->id, 'team' => $mandant->name, 'abos_betroffen' => count($vorschau['abos']), 'bestaetigt' => $bestaetigt], $admin, $optin);
        }

        return $this->status($mandant);
    }

    private function pruefeMandant(Team $mandant): void
    {
        if (MasterTeam::istMaster($mandant)) {
            throw new \RuntimeException('Das Master-Team hat das Stamm-Paket immer, es lässt sich weder buchen noch abbestellen.');
        }
        if ((int) $this->rechte->kundenHauptTeam($mandant)->id !== (int) $mandant->id) {
            throw new \RuntimeException('Das Stamm-Paket wird am Haupt-Team des Kunden gebucht. Unter-Teams erben es.');
        }
    }
}
