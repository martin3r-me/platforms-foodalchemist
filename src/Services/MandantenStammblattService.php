<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\Team;
use Platform\Core\Models\User;
use Platform\FoodAlchemist\Models\FoodAlchemistTeamStammOptin;
use Platform\FoodAlchemist\Support\FaBereiche;
use Platform\FoodAlchemist\Support\MasterTeam;

/**
 * Spec 82 §13 C1 — Mandanten-Stammblatt: ein Blick je Mandant, lesend, NUR Verwaltungsspalten.
 *
 * Enthält: Status, Modus, CRM-Firma, gebuchte Bereiche, Stamm-Paket, Zahl der Abos, Unter-Teams, Kontingente und Nutzung,
 * Gebühren (Vorschau/gebucht). Enthält NIE Rezepte, Preise, Foodbooks, Angebote, DNA oder Teamprofile (§13 G):
 * Bei autarken Mandanten ist das ohnehin alles, bei kuratierten kommen inhaltliche Rückschlüsse erst später dazu.
 * Wer sehen darf: Plattform-Admin oder Admin im Master-Team (MandantenService::pruefeVerwaltung).
 */
class MandantenStammblattService
{
    public function __construct(
        private readonly MandantenService $mandanten,
        private readonly FaRechte $rechte,
        private readonly AboAuskunft $abos,
        private readonly PlanNutzungService $nutzung,
    ) {}

    /**
     * Alle Mandanten als Stammblatt.
     *
     * @return list<array<string,mixed>>
     */
    public function alle(?User $user): array
    {
        $this->mandanten->pruefeVerwaltung($user, 'Mandanten-Stammblatt');

        return array_map(fn (array $m) => $this->blatt(Team::findOrFail($m['team_id']), $m, $user), $this->mandanten->uebersicht());
    }

    /** @return array<string,mixed> */
    public function stammblatt(Team $mandant, ?User $user): array
    {
        $this->mandanten->pruefeVerwaltung($user, 'Mandanten-Stammblatt');

        return $this->blatt($mandant, $this->mandanten->zeige($mandant), $user);
    }

    /**
     * @param  array<string,mixed>  $verwaltung
     * @return array<string,mixed>
     */
    private function blatt(Team $mandant, array $verwaltung, ?User $user): array
    {
        $optin = FoodAlchemistTeamStammOptin::where('team_id', $mandant->id)->first();
        $bereiche = array_keys(array_filter($this->rechte->teamBereiche($mandant)));
        $kontingent = $this->rechte->kontingente($mandant);
        $nutzung = $this->rechte->kontingentNutzung($mandant);
        $gebuehren = $this->nutzung->monat($mandant, $user);
        $unterTeams = Team::where('parent_team_id', $mandant->id)->orderBy('name')->pluck('name')->all();

        return $verwaltung + [
            'stamm_paket' => ['gebucht' => $optin !== null, 'seit' => $optin?->opted_in_at?->toDateString()],
            'abos' => count($this->abos->abosAmStammPaket($mandant)),
            'unter_teams' => $unterTeams,
            'bereiche' => array_map(fn ($b) => ['key' => $b, 'label' => FaBereiche::KATALOG[$b] ?? $b], $bereiche),
            'bereiche_gebucht' => count($bereiche),
            'bereiche_gesamt' => count(FaBereiche::KATALOG),
            'kontingente' => ['max_standorte' => $kontingent['max_standorte'], 'max_user' => $kontingent['max_user'], 'ki_budget_eur_monat' => $kontingent['ki_budget_eur_monat'],
                'standorte' => $nutzung['standorte'], 'user' => $nutzung['user'], 'ki_eur_monat' => $nutzung['ki_eur_monat']],
            'gebuehren' => ['monat' => $gebuehren['monat'], 'summe_gebucht' => $gebuehren['summe_gebucht'], 'summe_vorschau' => $gebuehren['summe_vorschau']],
            'inhalte_sichtbar' => false,   // §13 G: das Stammblatt trägt nie Inhalte, auch bei kuratierten Mandanten nicht
            'ist_master' => MasterTeam::istMaster($mandant),
        ];
    }
}
