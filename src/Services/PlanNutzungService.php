<?php

namespace Platform\FoodAlchemist\Services;

use Carbon\Carbon;
use Platform\Core\Models\Team;
use Platform\Core\Models\TeamBillableUsage;
use Platform\Core\Models\User;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Models\FoodAlchemistPlanPreis;
use Platform\FoodAlchemist\Support\MasterTeam;

/**
 * Spec 82 §13 D/C1 — „Plan und Nutzung“: was ein Mandant im Monat zahlt (lesend, nur Verwaltungsspalten).
 *
 * Wer sehen darf: Plattform-Admin, Admin im Master-Team (Sicht je Mandant, §13 C1) und Inhaber/Admin des Mandanten selbst.
 * Die Auskunft enthält nur Gebühren und Mengen, nie Rezepte, Preise, Foodbooks, DNA oder Teamprofile (§13 G).
 * Gebucht = Tageswerte aus team_billable_usages (billable_model foodalchemist.*); Vorschau = Menge heute × Monatspreis heute.
 */
class PlanNutzungService
{
    public function __construct(
        private readonly FaRechte $rechte,
        private readonly MandantenService $mandanten,
        private readonly GebuehrenZaehler $zaehler,
        private readonly PlanPreisService $preise,
    ) {}

    /** Der Mandant (Kunden-Hauptteam), zu dem `$team` gehört. */
    public function mandantVon(Team $team): Team
    {
        if (MasterTeam::istMaster($team)) {
            throw new \RuntimeException('Das Master-Team ist kein Mandant und hat keine Gebühren.');
        }

        return $this->rechte->kundenHauptTeam($team);
    }

    /** @throws FaRechtFehltException */
    public function pruefeLesen(?User $user, Team $mandant): User
    {
        if ($user === null || $user->isAiUser()) {
            throw new FaRechtFehltException(FaRolle::Admin, null, 'Plan und Nutzung einsehen');
        }
        try {
            return $this->mandanten->pruefeVerwaltung($user, 'Plan und Nutzung einsehen');   // Plattform-Admin oder Admin im Master-Team
        } catch (FaRechtFehltException) {
            $this->rechte->pruefe($user, $mandant, FaRolle::Admin, 'Plan und Nutzung einsehen');   // Inhaber/Admin des Mandanten

            return $user;
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function monat(Team $team, ?User $user, ?string $monat = null): array
    {
        $mandant = $this->mandantVon($team);
        $this->pruefeLesen($user, $mandant);
        $start = Carbon::parse(($monat ?: now()->format('Y-m')).'-01')->startOfMonth();
        $ende = $start->copy()->endOfMonth();

        $gebucht = TeamBillableUsage::where('team_id', $mandant->id)->where('billable_model', 'like', 'foodalchemist.%')
            ->whereDate('usage_date', '>=', $start->toDateString())->whereDate('usage_date', '<=', $ende->toDateString())->get()->groupBy('billable_model');
        $mengen = $this->zaehler->mengen($mandant);
        $heute = now()->startOfDay();
        $stichtag = $heute->between($start, $ende) ? $heute : $ende;

        $positionen = [];
        $summeGebucht = $summeVorschau = 0.0;
        foreach (FoodAlchemistPlanPreis::BAUSTEINE as $baustein => $def) {
            $zeilen = $gebucht->get(FoodAlchemistPlanPreis::modell($baustein), collect());
            $preis = $this->preise->preisAm($baustein, $stichtag);
            $menge = (int) $mengen[$baustein];
            $monatsbetrag = $preis !== null ? round((float) $preis->betrag_monat * $menge, 2) : null;
            $bisher = round((float) $zeilen->sum('total_cost'), 2);
            if ($menge === 0 && $zeilen->isEmpty()) {
                continue;
            }
            $summeGebucht += $bisher;
            $summeVorschau += $monatsbetrag ?? 0;
            $positionen[] = ['baustein' => $baustein, 'label' => $def[0], 'menge' => $menge, 'betrag_monat' => $preis !== null ? (string) $preis->betrag_monat : null,
                'monatsbetrag_vorschau' => $monatsbetrag, 'tage_gebucht' => $zeilen->count(), 'gebucht_bisher' => $bisher];
        }

        return [
            'team_id' => (int) $mandant->id, 'name' => (string) $mandant->name, 'monat' => $start->format('Y-m'),
            'user_gesamt' => $mengen['user_gesamt'], 'inklusive_user' => (int) config('foodalchemist.plan.basis_inklusive_user', 2),
            'positionen' => $positionen, 'summe_gebucht' => round($summeGebucht, 2), 'summe_vorschau' => round($summeVorschau, 2),
            'hinweis' => 'Vorschau = Menge heute × Monatspreis; gebucht = Tageswerte bis heute. Preise netto.',
        ];
    }

    /**
     * Auswirkung eines neuen Preises auf die aktiven Mandanten (Pflegeseite): Mehr- oder Minderbetrag je Monat.
     * Reine Zählung, keine Inhalte.
     *
     * @return array{baustein:string, aktuell:?string, neu:string, mandanten:list<array<string,mixed>>, summe_differenz:float}
     */
    public function auswirkung(string $baustein, string|float|int $neuerBetrag): array
    {
        if (! array_key_exists($baustein, FoodAlchemistPlanPreis::BAUSTEINE)) {
            throw new \RuntimeException('Baustein muss basis, user, unterteam, stamm_paket oder master_abo sein.');
        }
        $aktuell = $this->preise->preisAm($baustein, now());
        $alt = $aktuell !== null ? (float) $aktuell->betrag_monat : 0.0;
        $neu = (float) $neuerBetrag;
        $zeilen = [];
        $summe = 0.0;
        foreach ($this->zaehler->abrechenbareMandanten() as $mandant) {
            $menge = (int) $this->zaehler->mengen($mandant)[$baustein];
            if ($menge === 0) {
                continue;
            }
            $diff = round(($neu - $alt) * $menge, 2);
            $summe += $diff;
            $zeilen[] = ['team_id' => (int) $mandant->id, 'name' => (string) $mandant->name, 'menge' => $menge, 'differenz_monat' => $diff];
        }

        return ['baustein' => $baustein, 'aktuell' => $aktuell !== null ? (string) $aktuell->betrag_monat : null, 'neu' => number_format($neu, 2, '.', ''), 'mandanten' => $zeilen, 'summe_differenz' => round($summe, 2)];
    }
}
