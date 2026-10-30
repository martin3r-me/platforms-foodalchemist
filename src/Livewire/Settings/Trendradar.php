<?php

namespace Platform\FoodAlchemist\Livewire\Settings;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\FoodAlchemist\Models\FoodAlchemistTrend;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Services\TrendSignalService;
use Platform\FoodAlchemist\Support\StichwortFilter;
use Platform\FoodAlchemist\Support\TrendAutomatikRegeln;
use Platform\FoodAlchemist\Support\TrendVokabular;

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

    /** Spec 93 A: Team-Standard für neue Quellen und für den Stichwort-Filter (je Liste, wenn die Quelle keine eigene hat). */
    public string $quellenRhythmus = 'aus';

    public string $stichworteEin = '';

    public string $stichworteAus = '';

    /** Spec 93 C: Trend-Automatik — Stufe, Schatten-Modus, Schwellen (leer = Startwert), Trend-KI-Budget (leer = kein eigener Deckel). */
    public string $automatikStufe = 'aus';

    public bool $automatikSchatten = true;

    /** @var array<string, string> */
    public array $regeln = [];

    public string $kiBudget = '';

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
        $this->quellenRhythmus = $s->trendQuellenRhythmus($team);
        $w = $s->trendStichworte($team);
        $this->stichworteEin = implode(', ', $w['ein']);
        $this->stichworteAus = implode(', ', $w['aus']);
        $this->automatikStufe = $s->trendAutomatik($team);
        $this->automatikSchatten = $s->trendAutomatikSchatten($team);
        $this->regeln = array_map(fn ($v) => (string) $v, $s->trendAutomatikRegeln($team) ?? []);
        $this->kiBudget = ($b = $s->trendKiBudget($team)) !== null ? rtrim(rtrim(number_format($b, 2, '.', ''), '0'), '.') : '';
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
            'trend_quellen_rhythmus' => array_key_exists($this->quellenRhythmus, TrendVokabular::RHYTHMEN) ? $this->quellenRhythmus : 'aus',
            'trend_stichworte_ein' => StichwortFilter::liste($this->stichworteEin) ?: null,
            'trend_stichworte_aus' => StichwortFilter::liste($this->stichworteAus) ?: null,
            'trend_automatik' => array_key_exists($this->automatikStufe, TrendAutomatikRegeln::STUFEN) ? $this->automatikStufe : 'aus',
            'trend_automatik_schatten' => $this->automatikSchatten,
            'trend_automatik_regeln' => TrendAutomatikRegeln::pruefen(array_filter($this->regeln, fn ($v) => trim((string) $v) !== ''), false) ?: null,
            'trend_ki_budget_eur' => trim($this->kiBudget) === '' ? null : max(0, min(10000, (float) str_replace(',', '.', $this->kiBudget))),
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
            'rhythmen' => TrendVokabular::RHYTHMEN,
            'stufen' => TrendAutomatikRegeln::STUFEN,
            'regelSchluessel' => TrendAutomatikRegeln::SCHLUESSEL,
            'startwerte' => TrendAutomatikRegeln::startwerte(),
            'quellenHostAktiv' => (bool) config('foodalchemist.scheduler.trend_quellen_enabled', false) && (bool) config('foodalchemist.scheduler.enabled', true),
            'hostAktiv' => (bool) config('foodalchemist.scheduler.trends_messen_enabled', true)
                && (bool) config('foodalchemist.scheduler.enabled', true),
        ]);
    }
}
