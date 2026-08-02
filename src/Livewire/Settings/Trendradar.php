<?php

namespace Platform\FoodAlchemist\Livewire\Settings;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Platform\FoodAlchemist\Jobs\TrendRefreshJob;
use Platform\FoodAlchemist\Services\TeamSettingsService;

/**
 * Einstellungen → Trendradar: pro-Team-Steuerung der 08:00-Konzept-Automatisierung
 * (aus Top-Trends → Konzeptentwürfe → Signal) plus der manuelle Anstoß „Trends jetzt
 * importieren & clustern" (dispatcht {@see TrendRefreshJob}).
 *
 * Team-lokale Settings (eigene Zeile) — wie die KI-Sektion; kein Cross-Team-Write.
 */
class Trendradar extends Component
{
    public bool $autoEnabled = false;

    public int $limit = 3;

    public bool $signalEnabled = true;

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
    }

    public function speichern(): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null) {
            return;
        }
        $this->limit = max(1, min(10, $this->limit));
        app(TeamSettingsService::class)->update($team, [
            'trend_auto_enabled' => $this->autoEnabled,
            'trend_auto_limit' => $this->limit,
            'trend_signal_enabled' => $this->signalEnabled,
        ]);
        $this->meldung = $this->autoEnabled
            ? "Automatisierung AN — täglich morgens {$this->limit} Konzeptvorschlag(e)"
                . ($this->signalEnabled ? ' mit Signal in der Inbox.' : ' (ohne Signal).')
            : 'Automatisierung AUS — es werden keine Trend-Konzepte generiert.';
    }

    public function jetztImportieren(): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null) {
            return;
        }
        TrendRefreshJob::dispatch();
        $this->meldung = 'Import & Clustern gestartet — läuft im Hintergrund. '
            . 'Der Trendradar füllt sich, sobald der Lauf durch ist (bei vielen Trends einige Minuten).';
    }

    public function render()
    {
        $trendDocs = (int) DB::table('foodalchemist_knowledge_documents')
            ->where('category', 'trend')->where('active', 1)->whereNull('deleted_at')->count();
        $geclustert = (int) DB::table('foodalchemist_trend_meta')->whereNotNull('category')->count();
        $tentativeKlassen = (int) DB::table('foodalchemist_trend_taxonomy')
            ->whereNull('deleted_at')->whereNotNull('trend_class')->where('status', 'tentative')->count();
        $team = Auth::user()?->currentTeamRelation;

        return view('foodalchemist::livewire.settings.trendradar', [
            'trendDocs' => $trendDocs,
            'geclustert' => $geclustert,
            'ungeclustert' => max(0, $trendDocs - $geclustert),
            'tentativeKlassen' => $tentativeKlassen,
            'zeit' => config('foodalchemist.scheduler.trend_konzepte_zeit', '08:00'),
            'hostAktiv' => (bool) config('foodalchemist.scheduler.trend_konzepte_enabled', true)
                && (bool) config('foodalchemist.scheduler.enabled', true),
            'kiAktiv' => $team === null || app(TeamSettingsService::class)->kiAktiv($team),
        ]);
    }
}
