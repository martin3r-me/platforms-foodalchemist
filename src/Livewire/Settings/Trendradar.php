<?php

namespace Platform\FoodAlchemist\Livewire\Settings;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\FoodAlchemist\Models\FoodAlchemistTrend;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Services\TrendSignalService;

/**
 * Einstellungen → Trendradar: Google-Trends-Messung über DataForSEO (Spec 79: an/aus, Monatsbudget,
 * Verbindung). Die frühere 08:00-Konzept-Automatisierung und der Vault-Import sind mit Spec 79 entfallen
 * (Trends gehen jetzt über den Planungs-Reiter „Trendradar" in die Kaskade).
 *
 * Team-lokale Settings (eigene Zeile) — wie die KI-Sektion; kein Cross-Team-Write.
 */
class Trendradar extends Component
{
    use Concerns\MitEinstellungsSperre;   // Spec 65: Bereich settings.trendradar je Team

    protected function sperrBereich(): string
    {
        return 'trendradar';
    }
    public bool $dfsAktiv = false;

    public string $dfsBudget = '5';

    public string $dfsVerbindung = '';

    public ?string $meldung = null;

    public function mount(): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null) {
            return;
        }
        $s = app(TeamSettingsService::class);
        $this->dfsAktiv = $s->trendDataForSeoAktiv($team);
        $this->dfsBudget = rtrim(rtrim(number_format($s->trendDataForSeoBudget($team), 2, '.', ''), '0'), '.');
        $this->dfsVerbindung = (string) ($s->trendDataForSeoConnectionId($team) ?? '');
    }

    public function speichern(): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null) {
            return;
        }
        $budget = max(0, min(500, (float) str_replace(',', '.', $this->dfsBudget)));
        app(TeamSettingsService::class)->update($team, [
            'trend_dataforseo_enabled' => $this->dfsAktiv,
            'trend_dataforseo_budget_usd' => $budget,
            'trend_dataforseo_connection_id' => (int) $this->dfsVerbindung > 0 ? (int) $this->dfsVerbindung : null,
        ]);
        $this->dfsBudget = rtrim(rtrim(number_format($budget, 2, '.', ''), '0'), '.');
        $this->meldung = $this->dfsAktiv
            ? 'Gespeichert. Google Trends wird wöchentlich gemessen, Budget '.$this->dfsBudget.' $ im Monat.'
            : 'Gespeichert. Google Trends wird nur auf Knopfdruck gemessen.';
        $this->bearbeitenBeenden();   // Spec 65: Speichern gibt frei, Ansicht bleibt im Lesemodus
    }

    public function render(TrendSignalService $signale)
    {
        $team = Auth::user()?->currentTeamRelation;
        $trends = $team ? FoodAlchemistTrend::where('team_id', $team->id) : null;

        return view('foodalchemist::livewire.settings.trendradar', [
            'sperr' => $this->sperrZustand(),   // Spec 65
            'anzahlTrends' => $trends ? (clone $trends)->count() : 0,
            'aufRadar' => $trends ? (clone $trends)->whereIn('status', ['auf_radar', 'in_umsetzung'])->count() : 0,
            'verbraucht' => $team ? $signale->verbrauchDiesenMonat($team) : 0.0,
            'kostenJeAbfrage' => $signale->kostenJeAbfrage(),
            'anbindung' => $signale->anbindungVorhanden(),
            'messZeit' => config('foodalchemist.scheduler.trends_messen_zeit', '06:10'),
            'hostAktiv' => (bool) config('foodalchemist.scheduler.trends_messen_enabled', true)
                && (bool) config('foodalchemist.scheduler.enabled', true),
        ]);
    }
}
