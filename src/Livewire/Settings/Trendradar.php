<?php

namespace Platform\FoodAlchemist\Livewire\Settings;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\FoodAlchemist\Models\FoodAlchemistTrend;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Services\TrendSignalService;

/**
 * Einstellungen → Trendradar: Google-Trends-Messung über DataForSEO (Spec 79: an/aus, Monatsbudget,
 * Verbindung) und die 08:00-Konzept-Automatisierung (aus Top-Trends → Konzeptentwürfe → Signal).
 * Der frühere Anstoß „Trends aus dem Vault einlesen" ist mit Spec 79 entfallen.
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
    public bool $autoEnabled = false;

    public int $limit = 3;

    public bool $signalEnabled = true;

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
        $this->autoEnabled = $s->trendAutoAktiv($team);
        $this->limit = $s->trendAutoLimit($team);
        $this->signalEnabled = $s->trendSignalAktiv($team);
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
        $this->limit = max(1, min(10, $this->limit));
        $budget = max(0, min(500, (float) str_replace(',', '.', $this->dfsBudget)));
        app(TeamSettingsService::class)->update($team, [
            'trend_auto_enabled' => $this->autoEnabled,
            'trend_auto_limit' => $this->limit,
            'trend_signal_enabled' => $this->signalEnabled,
            'trend_dataforseo_enabled' => $this->dfsAktiv,
            'trend_dataforseo_budget_usd' => $budget,
            'trend_dataforseo_connection_id' => (int) $this->dfsVerbindung > 0 ? (int) $this->dfsVerbindung : null,
        ]);
        $this->dfsBudget = rtrim(rtrim(number_format($budget, 2, '.', ''), '0'), '.');
        $this->meldung = $this->autoEnabled
            ? 'Gespeichert. Automatisierung ist an: jeden Morgen '
                . ($this->limit === 1 ? 'ein Konzept-Entwurf' : "bis zu {$this->limit} Konzept-Entwürfe")
                . ($this->signalEnabled ? ', mit Signal.' : ', ohne Signal.')
            : 'Gespeichert. Automatisierung ist aus, aus Trends entstehen keine Konzepte.';
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
            'zeit' => config('foodalchemist.scheduler.trend_konzepte_zeit', '08:00'),
            'hostAktiv' => (bool) config('foodalchemist.scheduler.trend_konzepte_enabled', true)
                && (bool) config('foodalchemist.scheduler.enabled', true),
            'kiAktiv' => $team === null || app(TeamSettingsService::class)->kiAktiv($team),
        ]);
    }
}
