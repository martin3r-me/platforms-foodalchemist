<?php

namespace Platform\FoodAlchemist\Livewire\Settings;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\FoodAlchemist\Services\FixkostenService;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Services\VocabularyService;

/**
 * M1-07 + Doc 16 (Ausbau v2): Kalkulations-Cockpit je Team.
 *   - Garverlust/MwSt/Rundung (GL-02-Defaults).
 *   - Mehrstufiges Kostenblock-Schema (MEK/FEK/HK-Basis, je Block manuell o. abgeleitet).
 *   - Fixkosten-Erfassung + Bezugsbasen → abgeleitete Gemeinkosten-Sätze (M-K6).
 *   - Stundensatz (Lohn) + Marge.
 */
class Kalkulation extends Component
{
    /** @var array<string, string> WG-Code|'*' => Prozent */
    public array $garverlust = [];

    public array $mwst = [];

    public array $rundung = [];

    public string $marge = '15';

    /** Alle Kostenblöcke: [{key,label,typ,aktiv,modus,wert}]. */
    public array $schema = [];

    /** Bezugsbasen monatlich (€) für die Fixkosten-Ableitung. */
    public array $bezugsbasen = ['mek' => '0', 'fek' => '0', 'hk' => '0'];

    /** Geladene Fixkosten-Zeilen (Anzeige). */
    public array $fixListe = [];

    public array $neuFix = ['bezeichnung' => '', 'betrag' => '', 'periode' => 'monatlich', 'block_key' => ''];

    public ?string $meldung = null;

    public function mount(): void
    {
        $svc = app(TeamSettingsService::class);
        $settings = $svc->for($this->team());
        $this->garverlust = array_map(strval(...), $settings->garverlust_defaults ?? []);
        $this->mwst = array_replace(TeamSettingsService::MWST_DEFAULTS, $settings->mwst_defaults ?? []);
        $this->rundung = array_replace(TeamSettingsService::RUNDUNG_DEFAULTS, $settings->rundungsregeln ?? []);
        $this->marge = $this->fmt($svc->margePct($this->team()));

        $stundensatz = $svc->stundensatz($this->team());
        foreach ($svc->kalkulationSchema($this->team()) as $b) {
            $wert = $b['typ'] === 'arbeitszeit' && $b['wert'] <= 0 ? $stundensatz : $b['wert'];
            $this->schema[] = [
                'key' => $b['key'], 'label' => $b['label'], 'typ' => $b['typ'],
                'aktiv' => $b['aktiv'], 'modus' => $b['modus'], 'wert' => $this->fmt((float) $wert),
            ];
        }

        $basen = $svc->bezugsbasen($this->team());
        $this->bezugsbasen = ['mek' => $this->fmt($basen['mek']), 'fek' => $this->fmt($basen['fek']), 'hk' => $this->fmt($basen['hk'])];
        $this->ladeFix();
    }

    private function ladeFix(): void
    {
        $this->fixListe = app(FixkostenService::class)->liste($this->team())->map(fn ($f) => [
            'id' => $f->id, 'bezeichnung' => $f->bezeichnung,
            'betrag' => $this->fmt((float) $f->betrag), 'periode' => $f->periode, 'block_key' => $f->block_key,
        ])->all();
    }

    private function fmt(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.') ?: '0';
    }

    private function num(string $v): float
    {
        return max(0, (float) str_replace(',', '.', $v));
    }

    public function fixHinzu(): void
    {
        if (trim((string) $this->neuFix['bezeichnung']) === '' || ($this->neuFix['block_key'] ?? '') === '') {
            return;
        }
        app(FixkostenService::class)->create($this->team(), $this->neuFix);
        $this->neuFix = ['bezeichnung' => '', 'betrag' => '', 'periode' => 'monatlich', 'block_key' => ''];
        $this->ladeFix();
    }

    public function fixLoeschen(int $id): void
    {
        app(FixkostenService::class)->delete($this->team(), $id);
        $this->ladeFix();
    }

    public function speichern(): void
    {
        $garverlust = collect($this->garverlust)
            ->map(fn ($v) => trim(str_replace(',', '.', (string) $v)))
            ->filter(fn ($v) => $v !== '' && is_numeric($v))
            ->map(fn ($v) => (float) $v)->all();

        $svc = app(TeamSettingsService::class);
        $gemeinWert = 0.0;
        $stundensatz = $svc->stundensatz($this->team());
        foreach ($this->schema as $b) {
            if ($b['key'] === 'gemeinkosten') {
                $gemeinWert = $this->num((string) $b['wert']);
            }
            if ($b['typ'] === 'arbeitszeit') {
                $stundensatz = $this->num((string) $b['wert']);
            }
        }

        $svc->update($this->team(), [
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
            'hk2_zuschlag_pct' => $gemeinWert,                  // Rückwärtskompatibel (= Material-GK manuell)
            'stundensatz_eur' => $stundensatz,
            'marge_pct' => $this->num($this->marge),
            'kalkulation_schema' => $this->baueSchema(),
            'kalkulation_bezugsbasen' => [
                'mek' => $this->num((string) $this->bezugsbasen['mek']),
                'fek' => $this->num((string) $this->bezugsbasen['fek']),
                'hk' => $this->num((string) $this->bezugsbasen['hk']),
            ],
        ]);
        $this->meldung = 'Gespeichert — Kalkulation & Cockpits nutzen diese Werte.';
    }

    /** Schema aus den editierten Zeilen (Reihenfolge = Index × 10). */
    private function baueSchema(): array
    {
        $schema = [];
        $sort = 10;
        foreach ($this->schema as $b) {
            $schema[] = [
                'key' => $b['key'], 'label' => $b['label'], 'typ' => $b['typ'],
                'wert' => $this->num((string) $b['wert']),
                'aktiv' => (bool) ($b['aktiv'] ?? false),
                'modus' => in_array($b['modus'] ?? 'manuell', ['manuell', 'abgeleitet'], true) ? $b['modus'] : 'manuell',
                'sort' => $sort,
            ];
            $sort += 10;
        }

        return $schema;
    }

    public function render(VocabularyService $vocab, FixkostenService $fix)
    {
        $team = $this->team();
        // Abgeleitete Sätze + Σ Fixkosten je Block für die Live-Anzeige.
        $summen = $fix->summeJeBlock($team);
        $abgeleitet = [];
        foreach ($fix->aufgeloestesSchema($team) as $b) {
            if (($b['modus'] ?? 'manuell') === 'abgeleitet') {
                $abgeleitet[$b['key']] = $b['wert'];
            }
        }
        // GK-Blöcke (für die Fixkosten-Zuordnung).
        $gkBloecke = collect($this->schema)
            ->filter(fn ($b) => in_array($b['typ'], ['pct_mek', 'pct_fek', 'pct_hk'], true))
            ->map(fn ($b) => ['key' => $b['key'], 'label' => $b['label']])->values()->all();

        return view('foodalchemist::livewire.settings.kalkulation', [
            'team' => $team,
            'warengruppen' => $vocab->listWarengruppen($team),
            'abgeleitet' => $abgeleitet,
            'fixSummen' => $summen,
            'gkBloecke' => $gkBloecke,
        ]);
    }

    private function team()
    {
        return Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
    }
}
