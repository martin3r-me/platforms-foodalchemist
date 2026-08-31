<?php

namespace Platform\FoodAlchemist\Livewire\Settings;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Component;
use Platform\FoodAlchemist\Services\Ai\AiCostCalculator;
use Platform\FoodAlchemist\Services\RecipeImageService;
use Platform\FoodAlchemist\Services\TeamSettingsService;

/**
 * M7-08 / 06_KI §5: KI-Settings — Provider-Status, Tier-Zuordnung
 * (read-only aus Registry + Deployment-Mapping), Nutzungs-Statistik aus
 * ai_call_log (Transparenz + Tiering-Kontrolle, V-09) und der Kill-Switch
 * (Team-Schalter; Gateway wirft typisiert, Autopilot-Buttons gaten).
 */
class Ki extends Component
{
    public bool $kiAktiv = true;

    public ?string $meldung = null;

    /** Nutzungs-Zeitraum: '7' | '30' | '90' | 'all' (Tage; all = gesamte Historie). */
    public string $zeitraum = '30';

    public function mount(): void
    {
        $team = Auth::user()?->currentTeamRelation;
        $this->kiAktiv = $team === null || app(TeamSettingsService::class)->kiAktiv($team);
    }

    public function umschalten(): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null) {
            return;
        }
        $this->kiAktiv = ! $this->kiAktiv;
        app(TeamSettingsService::class)->update($team, ['ai_active' => $this->kiAktiv]);
        $this->meldung = $this->kiAktiv
            ? 'KI aktiviert — Autopilot-Buttons sind wieder nutzbar.'
            : 'Kill-Switch AKTIV — alle KI-Calls dieses Teams werden im Gateway gestoppt.';
    }

    public function render()
    {
        $team = Auth::user()?->currentTeamRelation;
        $tage = in_array($this->zeitraum, ['7', '30', '90'], true) ? (int) $this->zeitraum : null;
        $cachedSelect = Schema::hasColumn('foodalchemist_ai_call_log', 'tokens_cached')
            ? 'SUM(COALESCE(tokens_cached,0))'
            : '0';
        $statistik = $team !== null
            ? DB::table('foodalchemist_ai_call_log')->where('team_id', $team->id)
                ->when($tage !== null, fn ($q) => $q->where('created_at', '>=', now()->subDays($tage)))
                ->selectRaw('feature, tier, model, COUNT(*) AS calls, SUM(COALESCE(tokens_in,0)) AS t_in, '
                    . $cachedSelect . ' AS t_cached, SUM(COALESCE(tokens_out,0)) AS t_out, '
                    . 'SUM(CASE WHEN error IS NOT NULL THEN 1 ELSE 0 END) AS errors, '
                    . 'SUM(CASE WHEN accepted_at IS NOT NULL THEN 1 ELSE 0 END) AS accepted')
                ->groupBy('feature', 'tier', 'model')->orderByDesc('calls')->get()
            : collect();

        $rechner = app(AiCostCalculator::class);
        $key = fn ($z) => $z->feature . '|' . ($z->tier ?? '') . '|' . ($z->model ?? '');
        $kostenUsd = $statistik->mapWithKeys(fn ($z) => [$key($z) => $rechner->costUsd($z)]);
        $kosten = $kostenUsd->map(fn ($usd) => $rechner->displayCost($usd));
        $bekannteKosten = $kosten->filter(fn ($betrag) => $betrag !== null);

        $registry = collect(config('foodalchemist.prompts', []))
            ->except('demo.echo')->map(fn ($p) => $p['tier'] ?? '?')->sort();
        $auditOhnePrompt = collect([...RecipeImageService::BILD_FEATURES, 'voice.command']);
        $registryLuecken = $statistik->pluck('feature')->unique()->diff($registry->keys()->merge($auditOhnePrompt))->sort()->values();

        return view('foodalchemist::livewire.settings.ki', [
            'kosten' => $kosten,
            'kostenGesamt' => $bekannteKosten->sum(),
            'kostenUnbekannt' => $kosten->count() - $bekannteKosten->count(),
            'kostenWaehrung' => $rechner->currency(),
            'kostenSymbol' => $rechner->symbol(),
            'provider' => config('foodalchemist.ai.provider', 'core'),
            'tiers' => config('foodalchemist.ai.tiers', []),
            'fallbackModel' => config('foodalchemist.ai.fallback_model'),
            'registry' => $registry,
            'registryLuecken' => $registryLuecken,
            'aktiveModelle' => $statistik->whereNotNull('model')->pluck('model')->unique()->sort()->values(),
            'statistik' => $statistik,
            'zeitraumOptionen' => ['7' => 'Letzte 7 Tage', '30' => 'Letzte 30 Tage', '90' => 'Letzte 90 Tage', 'all' => 'Gesamte Historie'],
        ]);
    }
}
