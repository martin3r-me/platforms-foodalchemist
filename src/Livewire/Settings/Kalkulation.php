<?php

namespace Platform\FoodAlchemist\Livewire\Settings;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Services\VocabularyService;

/**
 * M1-07: Kalkulations-Defaults je Team auf GL-02-Buchungsebene —
 * Garverlust + Putzverlust (je WG, Recompute-Kaskade), MwSt, Rundung.
 * Die Herstellkosten (Zuschlagsschema/Fixkosten/Bezugsbasen/Marge) wohnen
 * seit Phase 4 (2026-06-15) in der eigenen Sektion `Herstellkosten`.
 */
class Kalkulation extends Component
{
    /** @var array<string, string> WG-Code|'*' => Prozent */
    public array $garverlust = [];

    /** @var array<string, string> WG-Code|'*' => Prozent */
    public array $putzverlust = [];

    public array $mwst = [];

    public array $rundung = [];

    public ?string $meldung = null;

    public function mount(): void
    {
        $settings = app(TeamSettingsService::class)->for($this->team());
        $this->garverlust = array_map(strval(...), $settings->cooking_loss_defaults ?? []);
        $this->putzverlust = array_map(strval(...), $settings->trimming_loss_defaults ?? []);
        $this->mwst = array_replace(TeamSettingsService::MWST_DEFAULTS, $settings->mwst_defaults ?? []);
        $this->rundung = array_replace(TeamSettingsService::RUNDUNG_DEFAULTS, $settings->rundungsregeln ?? []);
    }

    public function speichern(): void
    {
        $verlustClean = fn (array $werte) => collect($werte)
            ->map(fn ($v) => trim(str_replace(',', '.', (string) $v)))
            ->filter(fn ($v) => $v !== '' && is_numeric($v))
            ->map(fn ($v) => (float) $v)->all();

        app(TeamSettingsService::class)->update($this->team(), [
            'cooking_loss_defaults' => $verlustClean($this->garverlust) ?: null,
            'trimming_loss_defaults' => $verlustClean($this->putzverlust) ?: null,
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
        $this->meldung = 'Gespeichert — Recompute & Cockpits nutzen diese Werte.';
    }

    public function render(VocabularyService $vocab)
    {
        return view('foodalchemist::livewire.settings.kalkulation', [
            'warengruppen' => $vocab->listWarengruppen($this->team()),
        ]);
    }

    private function team()
    {
        return Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
    }
}
