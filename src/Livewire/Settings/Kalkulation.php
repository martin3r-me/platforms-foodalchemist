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

    /** M12: Gemeinkosten-Zuschlag % (HK1 → HK2, Zuschlagskalkulation). */
    public string $hk2Zuschlag = '0';

    /** M-K1/Doc 16: editierbare Kostenblöcke (ohne Gemeinkosten = hk2Zuschlag). */
    public array $schema = [];

    /** Marge % auf HK → VK-Vorschlag (Doc 16). */
    public string $marge = '15';

    public ?string $meldung = null;

    public function mount(): void
    {
        $svc = app(TeamSettingsService::class);
        $settings = $svc->for($this->team());
        $this->garverlust = array_map(strval(...), $settings->garverlust_defaults ?? []);
        $this->mwst = array_replace(TeamSettingsService::MWST_DEFAULTS, $settings->mwst_defaults ?? []);
        $this->rundung = array_replace(TeamSettingsService::RUNDUNG_DEFAULTS, $settings->rundungsregeln ?? []);
        $this->hk2Zuschlag = $this->fmt((float) ($settings->hk2_zuschlag_pct ?? 0));
        $this->marge = $this->fmt($svc->margePct($this->team()));

        $stundensatz = $svc->stundensatz($this->team());
        foreach ($svc->kalkulationSchema($this->team()) as $b) {
            if ($b['key'] === 'gemeinkosten') {
                continue; // wird über hk2Zuschlag gepflegt
            }
            // Lohn-Block ohne eigenen Satz → zeige den Team-Stundensatz als editierbaren Wert.
            $wert = $b['typ'] === 'arbeitszeit' && $b['wert'] <= 0 ? $stundensatz : $b['wert'];
            $this->schema[] = [
                'key' => $b['key'], 'label' => $b['label'], 'typ' => $b['typ'],
                'aktiv' => $b['aktiv'], 'wert' => $this->fmt((float) $wert),
            ];
        }
    }

    private function fmt(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.') ?: '0';
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
            'hk2_zuschlag_pct' => max(0, (float) str_replace(',', '.', $this->hk2Zuschlag)),
            'marge_pct' => max(0, (float) str_replace(',', '.', $this->marge)),
            'kalkulation_schema' => $this->baueSchema(),
        ]);
        $this->meldung = 'Gespeichert — der Rezept-Editor (M4) liest diese Defaults.';
    }

    /** Editierte Blöcke + Gemeinkosten (aus hk2Zuschlag) → vollständiges Schema. */
    private function baueSchema(): array
    {
        $schema = [];
        $sort = 10;
        foreach ($this->schema as $b) {
            $schema[] = [
                'key' => $b['key'], 'label' => $b['label'], 'typ' => $b['typ'],
                'wert' => max(0, (float) str_replace(',', '.', (string) $b['wert'])),
                'aktiv' => (bool) ($b['aktiv'] ?? false), 'sort' => $sort,
            ];
            $sort += 10;
        }
        $schema[] = [
            'key' => 'gemeinkosten', 'label' => 'Gemeinkosten', 'typ' => 'pct_hk',
            'wert' => max(0, (float) str_replace(',', '.', $this->hk2Zuschlag)), 'aktiv' => true, 'sort' => 50,
        ];

        return $schema;
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
