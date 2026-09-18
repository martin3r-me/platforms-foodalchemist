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

    /** Spec 53/F: Agenten-Modus des Sprachbefehls — fragen (Default)|auto_sicher|nur_lesen. */
    public string $sprachAgentModus = TeamSettingsService::VOICE_AGENT_MODE_DEFAULT;

    /** Spec 55: Agenten-Panel in der Planungs-Leitstelle sichtbar (Default AN). */
    public bool $sprachAgentPanelPlanung = true;

    /** Spec 53/F (3): Antworten des Sprach-Agenten laut vorlesen (Konversations-Modus, Default AUS). */
    public bool $sprachTtsVorlesen = false;

    /** Spec 53/F (3): OpenAI-TTS-Stimme. */
    public string $sprachTtsStimme = TeamSettingsService::VOICE_TTS_STIMME_DEFAULT;

    public function mount(): void
    {
        $team = Auth::user()?->currentTeamRelation;
        $this->kiAktiv = $team === null || app(TeamSettingsService::class)->kiAktiv($team);
        if ($team !== null) {
            $svc = app(TeamSettingsService::class);
            $this->sprachAgentModus = $svc->voiceAgentModus($team);
            $this->sprachAgentPanelPlanung = $svc->voiceAgentPanelPlanung($team);
            $this->sprachTtsVorlesen = $svc->voiceTtsVorlesen($team);
            $this->sprachTtsStimme = $svc->voiceTtsStimme($team);
        }
    }

    /** Sofort speichern, Muster wie {@see sprachAgentPanelPlanungUmschalten()}. */
    public function sprachTtsVorlesenUmschalten(): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null) {
            return;
        }
        $this->sprachTtsVorlesen = ! $this->sprachTtsVorlesen;
        app(TeamSettingsService::class)->update($team, ['voice_tts_vorlesen' => $this->sprachTtsVorlesen]);
        $this->meldung = $this->sprachTtsVorlesen
            ? 'Antworten des Sprachbefehls werden ab jetzt vorgelesen.'
            : 'Antworten werden nicht mehr vorgelesen.';
    }

    /** Livewire-Hook: `wire:model.live="sprachTtsStimme"` speichert sofort bei Auswahl. */
    public function updatedSprachTtsStimme(string $wert): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || ! in_array($wert, TeamSettingsService::VOICE_TTS_STIMMEN, true)) {
            return;
        }
        app(TeamSettingsService::class)->update($team, ['voice_tts_stimme' => $wert]);
        $this->meldung = 'Stimme gespeichert: ' . $wert;
    }

    /**
     * Spec 55: Agenten-Panel in der Planungs-Leitstelle an/aus — eigener Schlüssel
     * `voice_agent_panel_planung` (NICHT das alte `voice_agent_dauerhaft_aktiv`, das trägt
     * Entscheidungen zum entfernten schwebenden Element). Reine Server-Einstellung — die
     * Planungsseite liest sie beim Laden, kein Live-Browser-Event nötig (kein globales
     * Element mehr, das sofort reagieren müsste).
     */
    public function sprachAgentPanelPlanungUmschalten(): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null) {
            return;
        }
        $this->sprachAgentPanelPlanung = ! $this->sprachAgentPanelPlanung;
        app(TeamSettingsService::class)->update($team, ['voice_agent_panel_planung' => $this->sprachAgentPanelPlanung]);
        $this->meldung = $this->sprachAgentPanelPlanung
            ? 'Agenten-Panel ist in der Planungs-Leitstelle sichtbar.'
            : 'Agenten-Panel ist in der Planungs-Leitstelle ausgeblendet.';
    }

    /** Livewire-Hook: `wire:model.live="sprachAgentModus"` speichert sofort bei Auswahl. */
    public function updatedSprachAgentModus(string $wert): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || ! in_array($wert, TeamSettingsService::VOICE_AGENT_MODES, true)) {
            return;
        }
        app(TeamSettingsService::class)->update($team, ['voice_agent_mode' => $wert]);
        $this->meldung = 'Sprachbefehl-Modus gespeichert: ' . self::MODUS_LABEL[$wert];
    }

    /** Label + Beschreibung je Modus — geteilt zwischen Blade (Radio-Gruppe) und Pill im Voice-Modal. */
    public const MODUS_LABEL = ['fragen' => 'Fragen', 'auto_sicher' => 'Automatisch (sicher)', 'nur_lesen' => 'Nur lesen'];

    public const MODUS_BESCHREIBUNG = [
        'fragen' => 'Der Agent liest frei, jede Schreibaktion (Planung anlegen, Rezept anreichern, Klasse '
            . 'übernehmen) bleibt ein Vorschlag mit Bestätigen-Klick.',
        'auto_sicher' => 'Reversible Vorschläge (Planung anlegen + Editor öffnen, Anreicherung starten, '
            . 'Speisen-Klasse übernehmen) laufen sofort und werden als „ausgeführt" gemeldet. Unumkehrbares '
            . '(löschen, veröffentlichen, bestellen) bleibt Vorschlag mit Klick.',
        'nur_lesen' => 'Der Agent antwortet nur — keine Vorschläge, keine Schreibaktionen. Aufnahme und '
            . 'Tippen bleiben verfügbar.',
    ];

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
