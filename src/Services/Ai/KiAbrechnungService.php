<?php

namespace Platform\FoodAlchemist\Services\Ai;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistMandant;
use Platform\FoodAlchemist\Support\KiKlassen;
use Platform\FoodAlchemist\Support\KiVerursacher;
use Platform\FoodAlchemist\Support\MasterTeam;

/**
 * KI-Verbrauch je Mandant und Zeitraum (16.10.2026), gelesen aus ai_call_log. Kein Monatsabschluss: der Zeitraum ist frei (created_at),
 * die Beträge stammen aus den je Aufruf EINGEFRORENEN Spalten (kosten_usd, usd_eur) — eine spätere Preis- oder Kursänderung ändert alte Zeilen nie.
 * Zeilen vor der Einführung der Spalten (verursacher IS NULL) zählen nicht (keine Rückfüllung).
 *
 * Abrechenbar ist ein Mandant nur mit ausdrücklicher Mandanten-Zeile und Status „aktiv“ (dieselbe Regel wie der Gebühren-Zähler); der Verbrauch aller
 * anderen läuft sichtbar als „nicht abrechenbar“ mit. Plattform (Master-Team, Läufe ohne Nutzer) und „unbekannt“ stehen eigens.
 */
class KiAbrechnungService
{
    /**
     * @return array{mandanten: Collection<int, array<string, mixed>>, plattform: array<string, mixed>, unbekannt: array<string, mixed>, summe_abrechenbar: array<string, mixed>}
     */
    public function auswertung(CarbonInterface $von, CarbonInterface $bis): array
    {
        $zeilen = DB::table('foodalchemist_ai_call_log')
            ->whereNotNull('verursacher')
            ->where('created_at', '>=', $von)->where('created_at', '<=', $bis)
            ->selectRaw('verursacher, abrechnung_team_id, tier, COUNT(*) AS calls, '
                .'SUM(CASE WHEN error IS NOT NULL THEN 1 ELSE 0 END) AS fehler, '
                .'SUM(COALESCE(tokens_in,0)) AS t_in, SUM(COALESCE(tokens_cached,0)) AS t_cached, SUM(COALESCE(tokens_out,0)) AS t_out, '
                .'SUM(COALESCE(kosten_usd,0)) AS usd, '
                .'SUM(CASE WHEN kosten_usd IS NOT NULL AND usd_eur IS NOT NULL THEN kosten_usd * usd_eur ELSE 0 END) AS eur, '
                .'SUM(CASE WHEN kosten_usd IS NOT NULL AND usd_eur IS NULL THEN 1 ELSE 0 END) AS ohne_kurs, '
                .'SUM(CASE WHEN kosten_usd IS NULL AND error IS NULL AND model IS NOT NULL THEN 1 ELSE 0 END) AS ohne_preis')
            ->groupBy('verursacher', 'abrechnung_team_id', 'tier')
            ->get();

        $aktiv = FoodAlchemistMandant::where('status', 'aktiv')->pluck('team_id')->map(fn ($i) => (int) $i)->all();
        $namen = Team::whereIn('id', $zeilen->pluck('abrechnung_team_id')->filter()->unique())->pluck('name', 'id');

        $mandanten = $zeilen->where('verursacher', KiVerursacher::MANDANT)->groupBy('abrechnung_team_id')->map(function (Collection $z, $teamId) use ($aktiv, $namen) {
            return [
                'team_id' => (int) $teamId,
                'name' => $namen[$teamId] ?? '#'.$teamId,
                'abrechenbar' => in_array((int) $teamId, $aktiv, true),
                'klassen' => $z->sortBy(fn ($r) => ($i = array_search($r->tier, ['S', 'M', 'L', 'D', 'I'], true)) === false ? 99 : $i)->map(fn ($r) => $this->zeile([$r], KiKlassen::logLabel($r->tier) ?? '–'))->values()->all(),
                'summe' => $this->zeile($z->all(), 'Summe'),
            ];
        })->sortByDesc(fn ($m) => $m['summe']['usd'])->values();

        $abrechenbar = $mandanten->where('abrechenbar', true);

        return [
            'mandanten' => $mandanten,
            'plattform' => $this->zeile($zeilen->where('verursacher', KiVerursacher::PLATTFORM)->all(), 'Plattform'),
            'unbekannt' => $this->zeile($zeilen->where('verursacher', KiVerursacher::UNBEKANNT)->all(), 'Unbekannt'),
            'summe_abrechenbar' => $this->zeile($zeilen->whereIn('abrechnung_team_id', $abrechenbar->pluck('team_id')->all())->where('verursacher', KiVerursacher::MANDANT)->all(), 'Abrechenbar'),
        ];
    }

    /**
     * KI-Verbrauch aus Sicht EINES Teams (Mandanten-Sicht, Dominique 23.10.2026): ein Standort sieht nur seinen eigenen Verbrauch
     * (Daten-Team im eigenen Teilbaum), das Kunden-Hauptteam alles — Summe des Mandanten und je Standort. Gezählt werden nur Zeilen,
     * die dieser Mandant zahlt (verursacher mandant, abrechnung_team_id = Kunden-Hauptteam); Euro aus Preis und Kurs beim Aufruf.
     * Embeddings stehen nicht im Log und fehlen hier (ausgewiesen).
     *
     * @return array{ist_haupt: bool, plattform: bool, mandant_id: int, summe: array<string, mixed>, klassen: list<array<string, mixed>>, standorte: list<array<string, mixed>>, deckel: ?array<string, mixed>}
     */
    public function fuerTeam(Team $team, CarbonInterface $von, CarbonInterface $bis): array
    {
        $haupt = KiVerursacher::hauptteam($team);
        $istHaupt = (int) $haupt->id === (int) $team->id;
        $teamIds = \Platform\FoodAlchemist\Jobs\RecomputeTeamRecipesJob::teamUndNachfahren((int) $team->id);

        // Das Master-Team zahlt nicht selbst (Läufe sind „plattform“, ohne Abrechnungs-Team): es sieht die Plattform-Zeilen seines Teilbaums
        // statt einer leeren Summe (Kurator 29.10.). Der Mandanten-Verbrauch bleibt in der Auswertung je Mandant.
        $plattform = MasterTeam::istMaster($haupt);

        $zeilen = DB::table('foodalchemist_ai_call_log')
            ->when($plattform,
                fn ($q) => $q->where('verursacher', KiVerursacher::PLATTFORM),
                fn ($q) => $q->where('verursacher', KiVerursacher::MANDANT)->where('abrechnung_team_id', $haupt->id))
            ->whereIn('team_id', $teamIds)
            ->where('created_at', '>=', $von)->where('created_at', '<=', $bis)
            ->selectRaw('team_id, tier, COUNT(*) AS calls, '
                .'SUM(CASE WHEN error IS NOT NULL THEN 1 ELSE 0 END) AS fehler, '
                .'SUM(COALESCE(tokens_in,0)) AS t_in, SUM(COALESCE(tokens_cached,0)) AS t_cached, SUM(COALESCE(tokens_out,0)) AS t_out, '
                .'SUM(COALESCE(kosten_usd,0)) AS usd, '
                .'SUM(CASE WHEN kosten_usd IS NOT NULL AND usd_eur IS NOT NULL THEN kosten_usd * usd_eur ELSE 0 END) AS eur, '
                .'SUM(CASE WHEN kosten_usd IS NOT NULL AND usd_eur IS NULL THEN 1 ELSE 0 END) AS ohne_kurs, '
                .'SUM(CASE WHEN kosten_usd IS NULL AND error IS NULL AND model IS NOT NULL THEN 1 ELSE 0 END) AS ohne_preis')
            ->groupBy('team_id', 'tier')
            ->get();

        $reihenfolge = fn ($tier) => ($i = array_search($tier, ['S', 'M', 'L', 'D', 'I'], true)) === false ? 99 : $i;
        $klassen = $zeilen->groupBy(fn ($r) => (string) $r->tier)->sortBy(fn ($z, $tier) => $reihenfolge($tier))
            ->map(fn (Collection $z, $tier) => $this->zeile($z->all(), KiKlassen::logLabel($tier === '' ? null : $tier) ?? '–'))->values()->all();

        $standorte = [];
        if ($istHaupt) {
            $namen = Team::whereIn('id', $zeilen->pluck('team_id')->filter()->unique())->pluck('name', 'id');
            $standorte = $zeilen->groupBy('team_id')->map(fn (Collection $z, $tid) => ['team_id' => (int) $tid, 'ist_haupt' => (int) $tid === (int) $haupt->id]
                + $this->zeile($z->all(), (string) ($namen[$tid] ?? '#'.$tid)))
                ->sortByDesc(fn ($r) => $r['eur'])->values()->all();
        }

        $deckel = null;
        if ($istHaupt && ! $plattform) {
            $svc = app(KiDeckelService::class);
            $deckel = $svc->deckel((int) $haupt->id) + ['verbrauch_monat' => round($svc->verbrauchMonat((int) $haupt->id), 2)];
        }

        return ['ist_haupt' => $istHaupt, 'plattform' => $plattform, 'mandant_id' => (int) $haupt->id, 'summe' => $this->zeile($zeilen->all(), 'Summe'),
            'klassen' => $klassen, 'standorte' => $standorte, 'deckel' => $deckel];
    }

    /** @param list<object> $zeilen @return array<string, mixed> */
    private function zeile(array $zeilen, string $label): array
    {
        $z = collect($zeilen);

        return [
            'label' => $label,
            'calls' => (int) $z->sum('calls'),
            'fehler' => (int) $z->sum('fehler'),
            't_in' => (int) $z->sum('t_in'), 't_cached' => (int) $z->sum('t_cached'), 't_out' => (int) $z->sum('t_out'),
            'usd' => round((float) $z->sum('usd'), 6),
            'eur' => round((float) $z->sum('eur'), 6),
            'ohne_kurs' => (int) $z->sum('ohne_kurs'),
            'ohne_preis' => (int) $z->sum('ohne_preis'),
        ];
    }
}
