<?php

namespace Platform\FoodAlchemist\Livewire\Settings;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Services\VocabularyService;

/**
 * M1-07 / GL-02: Kalkulations-Defaults je Team — Garverlust je GP-Klasse
 * (Warengruppe, '*' = global), MwSt-Sätze, Rundungsregeln.
 * Der RecomputeService (M4-03) liest dieselben TeamSettingsService-Getter.
 */
class Kalkulation extends Component
{
    /** @var array<string, string> WG-Code|'*' => Prozent (leer = kein Default) */
    public array $garverlust = [];

    public array $mwst = [];

    public array $rundung = [];

    public ?string $meldung = null;

    public function mount(): void
    {
        $settings = app(TeamSettingsService::class)->for($this->team());
        $this->garverlust = array_map(strval(...), $settings->garverlust_defaults ?? []);
        $this->mwst = array_replace(TeamSettingsService::MWST_DEFAULTS, $settings->mwst_defaults ?? []);
        $this->rundung = array_replace(TeamSettingsService::RUNDUNG_DEFAULTS, $settings->rundungsregeln ?? []);
    }

    public function speichern(): void
    {
        $garverlust = collect($this->garverlust)
            ->map(fn ($v) => trim(str_replace(',', '.', (string) $v)))
            ->filter(fn ($v) => $v !== '' && is_numeric($v))
            ->map(fn ($v) => (float) $v)
            ->all();

        app(TeamSettingsService::class)->update($this->team(), [
            'garverlust_defaults' => $garverlust ?: null,
            'mwst_defaults' => [
                'regulaer' => (float) str_replace(',', '.', (string) $this->mwst['regulaer']),
                'ermaessigt' => (float) str_replace(',', '.', (string) $this->mwst['ermaessigt']),
                'default_satz' => in_array($this->mwst['default_satz'], ['regulaer', 'ermaessigt'], true) ? $this->mwst['default_satz'] : 'ermaessigt',
            ],
            'rundungsregeln' => [
                'nachkommastellen' => max(0, min(4, (int) $this->rundung['nachkommastellen'])),
                'modus' => in_array($this->rundung['modus'], ['kaufmaennisch', 'auf', 'ab'], true) ? $this->rundung['modus'] : 'kaufmaennisch',
            ],
        ]);
        $this->meldung = 'Gespeichert — der Rezept-Editor (M4) liest diese Defaults.';
    }

    public function render(VocabularyService $vocab)
    {
        $team = $this->team();

        return view('foodalchemist::livewire.settings.kalkulation', [
            'team' => $team,
            'warengruppen' => $vocab->listWarengruppen($team),
        ]);
    }

    private function team()
    {
        return Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
    }
}
