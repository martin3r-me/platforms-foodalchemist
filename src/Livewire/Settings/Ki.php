<?php

namespace Platform\FoodAlchemist\Livewire\Settings;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
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
        $statistik = $team !== null
            ? DB::table('foodalchemist_ai_call_log')->where('team_id', $team->id)
                ->selectRaw('feature, tier, COUNT(*) AS calls, SUM(COALESCE(tokens_in,0)) AS t_in, '
                    . 'SUM(COALESCE(tokens_out,0)) AS t_out, SUM(CASE WHEN error IS NOT NULL THEN 1 ELSE 0 END) AS fehler, '
                    . 'SUM(CASE WHEN accepted_at IS NOT NULL THEN 1 ELSE 0 END) AS accepted')
                ->groupBy('feature', 'tier')->orderByDesc('calls')->limit(30)->get()
            : collect();

        // M9-04: €-Schätzung je Feature (Tokens × Tier-Preis aus der Deployment-Config)
        $preise = config('foodalchemist.ai.kosten_pro_mio', []);
        $euro = fn ($z) => ((float) $z->t_in * ($preise[$z->tier]['in'] ?? 0) + (float) $z->t_out * ($preise[$z->tier]['out'] ?? 0)) / 1_000_000;

        return view('foodalchemist::livewire.settings.ki', [
            'kosten' => $statistik->mapWithKeys(fn ($z) => [$z->feature . '|' . $z->tier => $euro($z)]),
            'kostenGesamt' => $statistik->sum($euro),
            'provider' => config('foodalchemist.ai.provider', 'core'),
            'tiers' => config('foodalchemist.ai.tiers', []),
            'fallbackModel' => config('foodalchemist.ai.fallback_model'),
            'registry' => collect(config('foodalchemist.prompts', []))
                ->except('demo.echo')->map(fn ($p) => $p['tier'] ?? '?')->sort(),
            'statistik' => $statistik,
        ]);
    }
}
