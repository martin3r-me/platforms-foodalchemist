<?php

namespace Platform\FoodAlchemist\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\Core\Models\TeamBillableUsage;
use Platform\FoodAlchemist\Models\FoodAlchemistMandant;
use Platform\FoodAlchemist\Models\FoodAlchemistPlanPreis;
use Platform\FoodAlchemist\Models\FoodAlchemistTeamStammOptin;
use Platform\FoodAlchemist\Support\MasterTeam;

/**
 * Spec 82 §13 D — täglicher Zähl-Lauf je Kunden-Hauptteam (Variante B).
 *
 * Zählt je Mandant Basis, weitere User, Unter-Teams, Stamm-Paket und Master-Abo, holt den Preis am Stichtag aus
 * foodalchemist_plan_preise und schreibt Tageswerte in die Core-Tabelle team_billable_usages (billable_model
 * `foodalchemist.<baustein>`). FA übergibt `billables` NICHT an registerModule (sonst zählte der Core doppelt).
 * Unter-Teams zahlen nicht selbst: ihre Nutzung läuft über das Kunden-Hauptteam.
 *
 * Rechnerei: Monatspreis × Menge ÷ Tage des Monats, je Tag auf Cent gerundet; der letzte Tag des Monats gleicht die
 * Rundung aus (Summe der exakten Tageswerte minus die schon gebuchten gerundeten). Der exakte Tageswert steht im
 * Preis-Snapshot. Abgerechnet werden nur Mandanten mit ausdrücklicher Mandanten-Zeile und Status „aktiv“ (Projekt = vor der Lizenz, beendet = vorbei; ohne Zeile nie).
 * Reine Zählung: liest nie Inhalte (Rezepte, Preise, Foodbooks) eines Mandanten.
 */
class GebuehrenZaehler
{
    public function __construct(
        private readonly PlanPreisService $preise,
        private readonly AboAuskunft $abos,
    ) {}

    /**
     * Mandanten, für die gezählt wird: Kunden-Hauptteams ohne das Master-Team mit einer EXPLIZITEN Mandanten-Zeile und Status „aktiv“.
     * Fürs Geld gilt „ohne Zeile“ NICHT als aktiv: Auf der Plattform ist jedes persönliche Team ein Haupt-Team ohne Eltern-Team und
     * würde sonst abgerechnet. Für die Anzeige (Stammblatt) bleibt „ohne Zeile = aktiv + autark“.
     */
    public function abrechenbareMandanten(): \Illuminate\Support\Collection
    {
        $master = MasterTeam::id();
        $aktiv = FoodAlchemistMandant::where('status', 'aktiv')->pluck('team_id')->map(fn ($i) => (int) $i)->all();

        return Team::whereNull('parent_team_id')->whereIn('id', $aktiv)->orderBy('id')->get()
            ->reject(fn ($t) => $master !== null && (int) $t->id === $master)
            ->values();
    }

    /**
     * Mengen je Baustein für einen Mandanten.
     *
     * @return array{basis:int, user:int, unterteam:int, stamm_paket:int, master_abo:int, user_gesamt:int}
     */
    public function mengen(Team $mandant): array
    {
        $teamIds = $this->teamIdsMitUnterTeams($mandant);
        $userGesamt = DB::table('team_user')->join('users', 'users.id', '=', 'team_user.user_id')
            ->whereIn('team_user.team_id', $teamIds)->where(fn ($q) => $q->whereNull('users.type')->orWhere('users.type', '!=', 'ai_user'))
            ->distinct()->count('team_user.user_id');

        return [
            'basis' => 1,
            'user' => max(0, $userGesamt - (int) config('foodalchemist.plan.basis_inklusive_user', 2)),
            'unterteam' => count($teamIds) - 1,
            'stamm_paket' => FoodAlchemistTeamStammOptin::where('team_id', $mandant->id)->exists() ? 1 : 0,
            'master_abo' => $this->abos->abosAmStammPaket($mandant) !== [] ? 1 : 0,
            'user_gesamt' => $userGesamt,
        ];
    }

    /**
     * Zählt einen Tag für einen Mandanten und schreibt die Zeilen. Mit `$schreiben=false` nur rechnen.
     * Bausteine ohne Preis am Stichtag werden übersprungen und gemeldet.
     *
     * @return array{zeilen: list<array<string,mixed>>, ohne_preis: list<string>}
     */
    public function zaehle(Team $mandant, Carbon|string $tag, bool $schreiben = true): array
    {
        $tag = Carbon::parse($tag)->startOfDay();
        $mengen = $this->mengen($mandant);
        $tage = $tag->daysInMonth;
        $zeilen = $ohnePreis = [];

        foreach (FoodAlchemistPlanPreis::BAUSTEINE as $baustein => $def) {
            $menge = (int) $mengen[$baustein];
            if ($menge <= 0) {
                continue;
            }
            $preis = $this->preise->preisAm($baustein, $tag);
            if ($preis === null) {
                $ohnePreis[] = $baustein;

                continue;
            }
            $modell = FoodAlchemistPlanPreis::modell($baustein);
            $exakt = (float) $preis->betrag_monat * $menge / $tage;
            $bereitsExakt = $this->bereitsExakt($mandant, $modell, $tag);
            $gebucht = $this->bereitsGebucht($mandant, $modell, $tag);
            $summeExakt = $bereitsExakt + $exakt;
            $gesamt = $tag->isSameDay($tag->copy()->endOfMonth())
                ? round($summeExakt - $gebucht, 2)         // letzter Tag: Rundung ausgleichen
                : round($exakt, 2);
            $zeile = ['team_id' => (int) $mandant->id, 'baustein' => $baustein, 'datum' => $tag->toDateString(), 'menge' => $menge, 'betrag_monat' => (string) $preis->betrag_monat, 'tageswert' => $gesamt];
            $zeilen[] = $zeile;

            if ($schreiben) {
                // Bewusst nicht updateOrCreate: der Datums-Cast des Core-Models schreibt „Y-m-d 00:00:00“, die Suche nach „Y-m-d“ träfe auf SQLite nie.
                $z = TeamBillableUsage::where('team_id', $mandant->id)->where('billable_model', $modell)->whereDate('usage_date', $tag->toDateString())->first()
                    ?? new TeamBillableUsage(['team_id' => $mandant->id, 'billable_model' => $modell, 'usage_date' => $tag->toDateString()]);
                $z->fill(['billable_type' => $def[1], 'label' => 'Food Alchemist · '.$def[0], 'count' => $menge,
                    'cost_per_unit' => round((float) $preis->betrag_monat / $tage, 4), 'total_cost' => $gesamt,
                    'pricing_snapshot' => ['quelle' => 'foodalchemist_plan_preise', 'plan_preis_id' => (int) $preis->id, 'baustein' => $baustein,
                        'betrag_monat' => (string) $preis->betrag_monat, 'tage_im_monat' => $tage, 'tageswert_exakt' => $exakt]]);
                $z->save();
            }
        }

        return ['zeilen' => $zeilen, 'ohne_preis' => $ohnePreis];
    }

    /** Das Kunden-Hauptteam und alle seine Unter-Teams (jede Ebene), als Team-IDs mit dem Mandanten zuerst. @return list<int> */
    private function teamIdsMitUnterTeams(Team $mandant): array
    {
        $ids = [(int) $mandant->id];
        $naechste = $ids;
        while ($naechste !== []) {
            $naechste = Team::whereIn('parent_team_id', $naechste)->pluck('id')->map(fn ($i) => (int) $i)->diff($ids)->values()->all();
            $ids = array_merge($ids, $naechste);
        }

        return $ids;
    }

    /** Summe der exakten Tageswerte der Vortage dieses Monats. */
    private function bereitsExakt(Team $mandant, string $modell, Carbon $tag): float
    {
        return (float) TeamBillableUsage::where('team_id', $mandant->id)->where('billable_model', $modell)
            ->whereDate('usage_date', '>=', $tag->copy()->startOfMonth()->toDateString())->whereDate('usage_date', '<', $tag->toDateString())
            ->get()->sum(fn ($z) => (float) ($z->pricing_snapshot['tageswert_exakt'] ?? $z->total_cost));
    }

    /** Summe der schon gebuchten (gerundeten) Tageswerte der Vortage dieses Monats. */
    private function bereitsGebucht(Team $mandant, string $modell, Carbon $tag): float
    {
        return (float) TeamBillableUsage::where('team_id', $mandant->id)->where('billable_model', $modell)
            ->whereDate('usage_date', '>=', $tag->copy()->startOfMonth()->toDateString())->whereDate('usage_date', '<', $tag->toDateString())->sum('total_cost');
    }
}
