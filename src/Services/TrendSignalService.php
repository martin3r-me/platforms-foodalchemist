<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Collection;
use Platform\Core\Models\Team;
use Platform\Core\Models\User;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Models\FoodAlchemistTrend;
use Platform\FoodAlchemist\Models\FoodAlchemistTrendBeleg;
use Platform\FoodAlchemist\Models\FoodAlchemistTrendSignal;

/**
 * Spec 79 · Google Trends je Trend über die DataForSEO-Anbindung des Integrations-Moduls
 * (`DataForSeoApiService::getGoogleTrendsExplore`, Deutschland, letzte 12 Monate).
 *
 * Jede Abfrage kostet (Live-Endpunkt, ~0,009 $ je Suchbegriff). Deshalb: nur auf Knopfdruck oder im
 * wöchentlichen Lauf, nie beim Anzeigen; Monatsbudget je Team; jede Abfrage mit Kosten protokolliert.
 * Das Ergebnis ist ein Signal plus ein automatischer Beleg „Google Trends" — der zählt nach Kap. 3.3 als
 * Social-/Suchsignal und bestätigt einen Trend nie allein.
 */
class TrendSignalService
{
    public function __construct(
        private readonly TrendService $trends,
        private readonly TeamSettingsService $settings,
        private readonly FaRechte $rechte,
        private readonly Trends\GoogleTrendsQuelle $quelle,
    ) {}

    /** Kosten einer Explore-Live-Abfrage in USD (DataForSEO-Preisliste, ein Suchbegriff je Abfrage). */
    public function kostenJeAbfrage(): float
    {
        return (float) config('foodalchemist.trends.dataforseo_kosten_je_abfrage', 0.009);
    }

    public function verbrauchDiesenMonat(Team $team): float
    {
        return (float) FoodAlchemistTrendSignal::where('team_id', $team->id)
            ->where('created_at', '>=', now()->startOfMonth())->sum('kosten_usd');
    }

    public function anbindungVorhanden(): bool
    {
        return $this->quelle->verfuegbar();
    }

    /**
     * Misst alle Suchbegriffe eines Trends. Ohne Suchbegriffe wird der Trend-Name genommen.
     *
     * @return Collection<int, FoodAlchemistTrendSignal>
     */
    public function messen(Team $team, int $trendId, ?User $user = null): Collection
    {
        if ($user !== null) {
            $this->rechte->pruefe($user, $team, FaRolle::Kuratieren, 'Google Trends abfragen');
        }
        $trend = FoodAlchemistTrend::where('team_id', $team->id)->findOrFail($trendId);
        $begriffe = $trend->suchbegriffe ?: [$trend->name];
        $begriffe = array_slice(array_values($begriffe), 0, 3);

        $budget = $this->settings->trendDataForSeoBudget($team);
        $kosten = $this->kostenJeAbfrage();
        $verbraucht = $this->verbrauchDiesenMonat($team);
        if ($verbraucht + $kosten * count($begriffe) > $budget + 1e-9) {
            throw new \RuntimeException(sprintf(
                'Monatsbudget für Google Trends erreicht (%.2f $ von %.2f $ verbraucht). Budget in den Einstellungen anheben oder bis zum Monatswechsel warten.',
                $verbraucht, $budget,
            ));
        }

        $signale = collect();
        foreach ($begriffe as $begriff) {
            $werte = $this->quelle->interesse($team, $begriff);
            $signale->push($this->speichern($team, $trend, $begriff, $werte, $kosten, $user?->id));
        }
        $this->trends->neuBewerten($trend->refresh());

        return $signale;
    }

    /**
     * Wöchentlicher Lauf: alle Trends des Teams mit Status gesichtet/geprüft/auf dem Radar/in Umsetzung,
     * deren letzte Messung älter als 6 Tage ist. Stoppt am Budget.
     *
     * @return array{gemessen:int, uebersprungen:int, fehler:list<string>}
     */
    public function wochenlauf(Team $team): array
    {
        $ergebnis = ['gemessen' => 0, 'uebersprungen' => 0, 'fehler' => []];
        if (! $this->settings->trendDataForSeoAktiv($team)) {
            return $ergebnis;
        }
        $trends = FoodAlchemistTrend::where('team_id', $team->id)
            ->whereIn('status', ['gesichtet', 'geprueft', 'auf_radar', 'in_umsetzung'])->orderBy('id')->get();
        foreach ($trends as $trend) {
            $letzte = FoodAlchemistTrendSignal::where('trend_id', $trend->id)->where('quelle', 'google_trends')->max('created_at');
            if ($letzte !== null && now()->diffInDays($letzte, true) < 6) {
                $ergebnis['uebersprungen']++;
                continue;
            }
            try {
                $this->messen($team, $trend->id);
                $ergebnis['gemessen']++;
            } catch (\RuntimeException $e) {
                $ergebnis['fehler'][] = $trend->name.': '.$e->getMessage();
                if (str_starts_with($e->getMessage(), 'Monatsbudget')) {
                    break;
                }
            }
        }

        return $ergebnis;
    }

    /**
     * Auswertung einer Kurve (0–100): Durchschnitt, Spitze, Veränderung letztes Viertel gegen erstes Viertel,
     * Richtung und „Spitze ohne Sockel" (Hype-Indiz: Spitze ≥ 2,5 × Durchschnitt und heute unter der Hälfte der Spitze).
     *
     * @param  list<array{date_from:?string, date_to:?string, value:?int}>  $werte
     * @return array{durchschnitt:?int, spitze:?int, spitze_am:?string, veraenderung:?float, richtung:?string, spitze_ohne_sockel:bool}
     */
    public function auswerten(array $werte): array
    {
        $zahlen = array_values(array_filter(array_map(fn ($p) => $p['value'] ?? null, $werte), fn ($v) => $v !== null));
        $n = count($zahlen);
        if ($n === 0) {
            return ['durchschnitt' => null, 'spitze' => null, 'spitze_am' => null, 'veraenderung' => null, 'richtung' => null, 'spitze_ohne_sockel' => false];
        }
        $schnitt = array_sum($zahlen) / $n;
        $spitze = max($zahlen);
        $spitzeAm = null;
        foreach ($werte as $p) {
            if (($p['value'] ?? null) === $spitze) {
                $spitzeAm = $p['date_from'] ?? null;
                break;
            }
        }
        $viertel = max(1, intdiv($n, 4));
        $anfang = array_sum(array_slice($zahlen, 0, $viertel)) / $viertel;
        $ende = array_sum(array_slice($zahlen, -$viertel)) / $viertel;
        $veraenderung = round($ende - $anfang, 2);
        $richtung = $veraenderung >= 10 ? 'steigend' : ($veraenderung <= -10 ? 'fallend' : 'stabil');
        $letzter = $zahlen[$n - 1];

        return [
            'durchschnitt' => (int) round($schnitt),
            'spitze' => (int) $spitze,
            'spitze_am' => $spitzeAm,
            'veraenderung' => $veraenderung,
            'richtung' => $richtung,
            'spitze_ohne_sockel' => $spitze > 0 && $spitze >= 2.5 * max(1, $schnitt) && $letzter < $spitze / 2,
        ];
    }

    private function speichern(Team $team, FoodAlchemistTrend $trend, string $begriff, array $werte, float $kosten, ?int $userId): FoodAlchemistTrendSignal
    {
        $a = $this->auswerten($werte);
        $signal = FoodAlchemistTrendSignal::create([
            'team_id' => $team->id,
            'trend_id' => $trend->id,
            'quelle' => 'google_trends',
            'suchbegriff' => mb_substr($begriff, 0, 160),
            'region' => 'Deutschland',
            'zeitraum' => 'past_12_months',
            'werte' => $werte,
            'durchschnitt' => $a['durchschnitt'],
            'spitze' => $a['spitze'],
            'spitze_am' => $a['spitze_am'] ? substr($a['spitze_am'], 0, 10) : null,
            'veraenderung' => $a['veraenderung'],
            'richtung' => $a['richtung'],
            'spitze_ohne_sockel' => $a['spitze_ohne_sockel'],
            'kosten_usd' => $kosten,
            'created_by' => $userId,
        ]);

        // Ein automatischer Beleg je Suchbegriff, bei jeder Messung aktualisiert statt vervielfacht
        $titel = 'Google Trends: „'.$begriff.'" (Deutschland, 12 Monate)';
        $notiz = $a['richtung'] === null
            ? 'Keine Daten — zu wenig Suchvolumen.'
            : sprintf('%s (%+.0f Punkte), Ø %d, Spitze %d%s.%s',
                ucfirst($a['richtung']), $a['veraenderung'], $a['durchschnitt'], $a['spitze'],
                $a['spitze_am'] ? ' am '.date('d.m.Y', strtotime($a['spitze_am'])) : '',
                $a['spitze_ohne_sockel'] ? ' Kurze Spitze ohne Sockel — Hinweis auf Hype.' : '');
        $beleg = FoodAlchemistTrendBeleg::where('trend_id', $trend->id)->where('quelle', 'google_trends')->where('titel', $titel)->first();
        $felder = ['notiz' => $notiz, 'beobachtet_am' => now()->toDateString(), 'signal_id' => $signal->id,
            'url' => 'https://trends.google.de/trends/explore?geo=DE&q='.rawurlencode($begriff)];
        if ($beleg !== null) {
            $beleg->update($felder);
        } else {
            FoodAlchemistTrendBeleg::create($felder + ['team_id' => $team->id, 'trend_id' => $trend->id,
                'quelle' => 'google_trends', 'titel' => $titel, 'created_by' => $userId]);
        }

        return $signal;
    }
}
