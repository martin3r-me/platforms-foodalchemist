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

    /** #379+: Ziel-Wareneinsatzquote (Food-Cost-%) — Controlling-Ziel. */
    public string $zielWe = '30';

    /** #379+: Lohnnebenkosten-Zuschlag % (AG-Anteil auf den Produktionslohn). */
    public string $lnk = '0';

    /** Alle Kostenblöcke: [{key,label,typ,aktiv,modus,wert}]. */
    public array $schema = [];

    /** Bezugsbasen monatlich (€) für die Fixkosten-Ableitung. */
    public array $bezugsbasen = ['mek' => '0', 'fek' => '0', 'hk' => '0'];

    public array $fixListe = [];

    public array $neuFix = ['label' => '', 'betrag' => '', 'periode' => 'monatlich', 'block_key' => ''];

    /** Neuer Kostenblock (Phase 4 — vorher gab es nur das feste Default-Set). */
    public array $neuBlock = ['label' => '', 'typ' => 'pct_mek'];

    public ?string $meldung = null;

    public ?string $fehler = null;

    public function mount(): void
    {
        $svc = app(TeamSettingsService::class);
        $this->marge = $this->fmt($svc->margePct($this->team()));
        $this->zielWe = $this->fmt($svc->zielWareneinsatzPct($this->team()));
        $this->lnk = $this->fmt($svc->lohnnebenkostenPct($this->team()));

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
            'id' => $f->id, 'label' => $f->label,
            'betrag' => $this->fmt((float) $f->betrag), 'periode' => $f->periode, 'block_key' => $f->block_key,
            'monatsbetrag' => round((float) $f->monatsbetrag(), 2),   // #379+: normalisiert (jährlich/12) für Σ-Anzeige
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
        // #379+: Gemeinkosten-Blöcke werden standardmäßig AUTOMATISCH aus den Fixkosten abgeleitet
        // (€ rein → % selbst gerechnet). Nur Direkt-Typen (Lohn/€-Portion) bleiben manuell.
        $istGk = in_array($typ, ['pct_mek', 'pct_fek', 'pct_hk'], true);
        $this->schema[] = ['key' => $key, 'label' => $label, 'typ' => $typ, 'aktiv' => true, 'modus' => $istGk ? 'abgeleitet' : 'manuell', 'wert' => '0'];
        $this->neuBlock = ['label' => '', 'typ' => 'pct_mek'];
        $this->fehler = null;
    }

    /** #379+: Alle Gemeinkosten-Blöcke auf automatische Ableitung aus den Fixkosten stellen. */
    public function alleAutomatisch(): void
    {
        foreach ($this->schema as $i => $b) {
            if (in_array($b['typ'], ['pct_mek', 'pct_fek', 'pct_hk'], true)) {
                $this->schema[$i]['modus'] = 'abgeleitet';
            }
        }
        $this->meldung = 'Alle Gemeinkosten werden jetzt automatisch aus den Fixkosten berechnet.';
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
        if (trim((string) $this->neuFix['label']) === '' || ($this->neuFix['block_key'] ?? '') === '') {
            return;
        }
        app(FixkostenService::class)->create($this->team(), $this->neuFix);
        $this->neuFix = ['label' => '', 'betrag' => '', 'periode' => 'monatlich', 'block_key' => ''];
        $this->ladeFix();
        $this->dispatch('kosten-aktualisiert');   // #379+: Werkstatt-Cockpit live nachziehen
    }

    public function fixLoeschen(int $id): void
    {
        app(FixkostenService::class)->delete($this->team(), $id);
        $this->ladeFix();
        $this->dispatch('kosten-aktualisiert');   // #379+: Werkstatt-Cockpit live nachziehen
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
            'ziel_wareneinsatz_pct' => $this->num($this->zielWe),
            'lohnnebenkosten_pct' => $this->num($this->lnk),
            'calculation_schema' => $this->baueSchema(),
            'calculation_bezugsbasen' => [
                'mek' => $this->num((string) $this->bezugsbasen['mek']),
                'fek' => $this->num((string) $this->bezugsbasen['fek']),
                'hk' => $this->num((string) $this->bezugsbasen['hk']),
            ],
        ]);
        $this->meldung = 'Gespeichert — Kalkulation & Cockpits nutzen diese Werte.';
        $this->dispatch('kosten-aktualisiert');   // #379+: Werkstatt-Cockpit live nachziehen
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

        // #379+: Abgeleitete %-Sätze aus den LIVE-Bezugsbasen rechnen (nicht aus dem DB-Stand),
        // damit der Satz beim Tippen der Basis sofort mitläuft — € rein → % automatisch.
        $liveBasen = [
            'mek' => $this->num((string) ($this->bezugsbasen['mek'] ?? '0')),
            'fek' => $this->num((string) ($this->bezugsbasen['fek'] ?? '0')),
            'hk' => $this->num((string) ($this->bezugsbasen['hk'] ?? '0')),
        ];
        $abgeleitet = [];
        foreach ($this->schema as $b) {
            if (($b['modus'] ?? 'manuell') === 'abgeleitet') {
                $abgeleitet[$b['key']] = $fix->abgeleiteterSatz($team, $b, $summen, $liveBasen);
            }
        }
        $gkBloecke = collect($this->schema)
            ->filter(fn ($b) => in_array($b['typ'], ['pct_mek', 'pct_fek', 'pct_hk'], true))
            ->map(fn ($b) => ['key' => $b['key'], 'label' => $b['label']])->values()->all();

        return view('foodalchemist::livewire.settings.herstellkosten', [
            'abgeleitet' => $abgeleitet,
            'fixSummen' => $summen,
            'liveBasen' => $liveBasen,
            'gkBloecke' => $gkBloecke,
        ]);
    }

    private function team()
    {
        return Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
    }
}
