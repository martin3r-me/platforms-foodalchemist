<?php

namespace Platform\FoodAlchemist\Livewire\Settings;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Component;
use Platform\FoodAlchemist\Services\FixkostenService;
use Platform\FoodAlchemist\Services\TeamSettingsService;

/**
 * Phase 4 (Settings-Audit 2026-06-15): Herstellkosten als EIGENE Sektion —
 * herausgelöst aus „Kalkulation" (die behält nur GL-02-Buchungs-Defaults:
 * Gar-/Putzverlust, MwSt, Rundung). Hier wohnt die mehrstufige Zuschlags-
 * kalkulation (MEK→MGK→FEK→FGK→HK→VwGK/Logistik = HK2 → Marge), die Fixkosten
 * + Bezugsbasen (abgeleitete Sätze, M-K6) und der Stundensatz.
 *
 * Neu ggü. der alten Sammel-Sektion: Kostenblöcke sind anlegbar/entfernbar
 * (vorher festes 7er-Set), Bezugsbasen mit Erklärtext.
 */
class Herstellkosten extends Component
{
    public string $marge = '15';

    /** Alle Kostenblöcke: [{key,label,typ,aktiv,modus,wert}]. */
    public array $schema = [];

    /** Bezugsbasen monatlich (€) für die Fixkosten-Ableitung. */
    public array $bezugsbasen = ['mek' => '0', 'fek' => '0', 'hk' => '0'];

    public array $fixListe = [];

    public array $neuFix = ['bezeichnung' => '', 'betrag' => '', 'periode' => 'monatlich', 'block_key' => ''];

    /** Neuer Kostenblock (Phase 4 — vorher gab es nur das feste Default-Set). */
    public array $neuBlock = ['label' => '', 'typ' => 'pct_mek'];

    public ?string $meldung = null;

    public ?string $fehler = null;

    public function mount(): void
    {
        $svc = app(TeamSettingsService::class);
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

    /** Neuen Kostenblock anlegen (key = Slug des Labels, eindeutig im Schema). */
    public function blockHinzu(): void
    {
        $label = trim($this->neuBlock['label'] ?? '');
        $typ = in_array($this->neuBlock['typ'] ?? '', ['pct_mek', 'pct_fek', 'pct_hk', 'eur_pro_portion', 'arbeitszeit'], true)
            ? $this->neuBlock['typ'] : 'pct_mek';
        if ($label === '') {
            $this->fehler = 'Block braucht eine Bezeichnung.';

            return;
        }
        $basis = Str::slug($label, '_') ?: 'block';
        $key = $basis;
        $i = 2;
        $vorhanden = array_column($this->schema, 'key');
        while (in_array($key, $vorhanden, true)) {
            $key = $basis . '_' . $i++;
        }
        $this->schema[] = ['key' => $key, 'label' => $label, 'typ' => $typ, 'aktiv' => true, 'modus' => 'manuell', 'wert' => '0'];
        $this->neuBlock = ['label' => '', 'typ' => 'pct_mek'];
        $this->fehler = null;
    }

    public function blockEntfernen(int $index): void
    {
        if (isset($this->schema[$index])) {
            unset($this->schema[$index]);
            $this->schema = array_values($this->schema);
        }
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

    public function render(FixkostenService $fix)
    {
        $team = $this->team();
        $summen = $fix->summeJeBlock($team);
        $abgeleitet = [];
        foreach ($fix->aufgeloestesSchema($team) as $b) {
            if (($b['modus'] ?? 'manuell') === 'abgeleitet') {
                $abgeleitet[$b['key']] = $b['wert'];
            }
        }
        $gkBloecke = collect($this->schema)
            ->filter(fn ($b) => in_array($b['typ'], ['pct_mek', 'pct_fek', 'pct_hk'], true))
            ->map(fn ($b) => ['key' => $b['key'], 'label' => $b['label']])->values()->all();

        return view('foodalchemist::livewire.settings.herstellkosten', [
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
