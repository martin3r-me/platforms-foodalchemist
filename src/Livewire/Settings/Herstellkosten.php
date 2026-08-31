<?php

namespace Platform\FoodAlchemist\Livewire\Settings;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Component;
use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;
use Platform\FoodAlchemist\Services\FixkostenService;
use Platform\FoodAlchemist\Services\OutletSettingsService;
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

    public string $laborSource = 'team_flat';

    /** Alle Kostenblöcke: [{key,label,typ,aktiv,modus,wert}]. */
    public array $schema = [];

    /** Bezugsbasen monatlich (€) für die Fixkosten-Ableitung. */
    public array $bezugsbasen = ['mek' => '0', 'fek' => '0', 'hk' => '0'];

    public array $fixListe = [];

    /**
     * Ebene 2: Bearbeitungs-Scope der GANZEN Seite (null = Team-Standard, sonst ein Betrieb).
     * Rein lokaler Wähler — verändert NICHT die globale Brille (ActiveOutletContext).
     * Bei gewähltem Betrieb wird die Seite zum Voll-Editor dieses Betriebs (Skalare erben pro Feld,
     * Fixkosten ersetzen pro Block, Zuschlagsschema/Bezugsbasen via Toggle „eigenes Schema").
     */
    public ?int $outletId = null;

    /** Ebene 2: Hat der gewählte Betrieb ein EIGENES Zuschlagsschema + Bezugsbasen? Aus = erbt das Team. */
    public bool $eigenesSchema = false;

    public array $neuFix = ['label' => '', 'amount' => '', 'periode' => 'monatlich', 'block_key' => ''];

    /** Neuer Kostenblock (Phase 4 — vorher gab es nur das feste Default-Set). */
    public array $neuBlock = ['label' => '', 'type' => 'pct_mek'];

    public ?string $meldung = null;

    public ?string $fehler = null;

    public function mount(): void
    {
        $this->ladeWerte();
    }

    /**
     * Lädt alle Felder im aktuellen Scope. Team-Scope = wie bisher (Team-Werte).
     * Betrieb-Scope: Skalare als ROH-Override (leer = erbt, Placeholder zeigt Team),
     * Zuschlagsschema/Bezugsbasen als effektiv-kaskadierte Anzeige; `eigenesSchema` sagt,
     * ob der Betrieb dort einen eigenen Override hat.
     */
    private function ladeWerte(): void
    {
        $svc = app(TeamSettingsService::class);
        $team = $this->team();
        $outlet = $this->scopeOutlet();

        if ($outlet === null) {
            $this->marge = $this->fmt($svc->margePct($team));
            $this->zielWe = $this->fmt($svc->zielWareneinsatzPct($team));
            $this->lnk = $this->fmt($svc->lohnnebenkostenPct($team));
            $this->eigenesSchema = true;   // das Team-Schema ist immer „eigen"
        } else {
            $roh = app(OutletSettingsService::class)->for($outlet);
            $this->marge = $roh->margin_pct !== null ? $this->fmt((float) $roh->margin_pct) : '';
            $this->zielWe = $roh->target_food_cost_pct !== null ? $this->fmt((float) $roh->target_food_cost_pct) : '';
            $this->lnk = $roh->labor_overhead_pct !== null ? $this->fmt((float) $roh->labor_overhead_pct) : '';
            $this->eigenesSchema = $roh->calculation_schema !== null && $roh->calculation_schema !== [];
        }

        // Lohnquelle bleibt teamweit (keine Outlet-Spalte).
        $this->laborSource = $svc->laborCostSource($team);

        // Zuschlagsschema + Stundensatz + Bezugsbasen: effektiv (kaskadiert) fürs Anzeigen.
        $stundensatz = $svc->stundensatz($team, $outlet);
        $this->schema = [];
        foreach ($svc->kalkulationSchema($team, $outlet) as $b) {
            $wert = $b['type'] === 'arbeitszeit' && $b['value'] <= 0 ? $stundensatz : $b['value'];
            $this->schema[] = [
                'key' => $b['key'], 'label' => $b['label'], 'type' => $b['type'],
                'active' => $b['active'], 'mode' => $b['mode'], 'value' => $this->fmt((float) $wert),
            ];
        }

        $basen = $svc->bezugsbasen($team, $outlet);
        $this->bezugsbasen = ['mek' => $this->fmt($basen['mek']), 'fek' => $this->fmt($basen['fek']), 'hk' => $this->fmt($basen['hk'])];
        $this->ladeFix();
    }

    private function ladeFix(): void
    {
        $svc = app(FixkostenService::class);
        $outlet = $this->scopeOutlet();
        // Betrieb gewählt → NUR dessen eigene Zeilen (geerbte Team-Blöcke tauchen in der Σ auf, nicht in der Liste).
        $rows = $outlet !== null ? $svc->listeFuerOutlet($this->team(), $outlet) : $svc->liste($this->team());
        $this->fixListe = $rows->map(fn ($f) => [
            'id' => $f->id, 'label' => $f->label,
            'amount' => $this->fmt((float) $f->amount), 'periode' => $f->periode, 'block_key' => $f->block_key,
            'monatsbetrag' => round((float) $f->monatsbetrag(), 2),   // #379+: normalisiert (jährlich/12) für Σ-Anzeige
        ])->all();
    }

    /** Bearbeitungs-Scope der Seite (team-eigener, aktiver Betrieb) oder null (Team-Standard). */
    private function scopeOutlet(): ?FoodAlchemistOutlet
    {
        if ($this->outletId === null) {
            return null;
        }

        return FoodAlchemistOutlet::where('team_id', $this->team()->id)
            ->where('is_inactive', false)->find($this->outletId);
    }

    /** Scope-Wechsel (Team ↔ Betrieb) → ganze Seite neu laden (Livewire-Hook). */
    public function updatedOutletId(): void
    {
        $this->meldung = null;
        $this->fehler = null;
        $this->ladeWerte();
    }

    /** Toggle „eigenes Schema" ausgeschaltet → ungespeicherte Betriebs-Edits verwerfen, Team-Schema zeigen. */
    public function updatedEigenesSchema(): void
    {
        if (! $this->eigenesSchema) {
            $this->ladeWerte();
        }
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
    /** Zuschlagsschema editierbar? Team immer; Betrieb nur mit eigenem Schema (sonst geerbt = read-only). */
    private function schemaEditierbar(): bool
    {
        return $this->outletId === null || $this->eigenesSchema;
    }

    public function blockHinzu(): void
    {
        if (! $this->schemaEditierbar()) {
            return;
        }
        $label = trim($this->neuBlock['label'] ?? '');
        $typ = in_array($this->neuBlock['type'] ?? '', ['pct_mek', 'pct_fek', 'pct_hk', 'eur_pro_portion', 'arbeitszeit'], true)
            ? $this->neuBlock['type'] : 'pct_mek';
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
        $this->schema[] = ['key' => $key, 'label' => $label, 'type' => $typ, 'active' => true, 'mode' => $istGk ? 'abgeleitet' : 'manuell', 'value' => '0'];
        $this->neuBlock = ['label' => '', 'type' => 'pct_mek'];
        $this->fehler = null;
    }

    /** #379+: Alle Gemeinkosten-Blöcke auf automatische Ableitung aus den Fixkosten stellen. */
    public function alleAutomatisch(): void
    {
        if (! $this->schemaEditierbar()) {
            return;
        }
        foreach ($this->schema as $i => $b) {
            if (in_array($b['type'], ['pct_mek', 'pct_fek', 'pct_hk'], true)) {
                $this->schema[$i]['mode'] = 'abgeleitet';
            }
        }
        $this->meldung = 'Alle Gemeinkosten werden jetzt automatisch aus den Fixkosten berechnet.';
    }

    public function blockEntfernen(int $index): void
    {
        if (! $this->schemaEditierbar()) {
            return;
        }
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
        app(FixkostenService::class)->create($this->team(), $this->neuFix, $this->scopeOutlet());
        $this->neuFix = ['label' => '', 'amount' => '', 'periode' => 'monatlich', 'block_key' => ''];
        $this->ladeFix();
        $this->dispatch('kosten-aktualisiert');   // #379+: Werkstatt-Cockpit live nachziehen
    }

    public function fixLoeschen(int $id): void
    {
        app(FixkostenService::class)->delete($this->team(), $id);
        $this->ladeFix();
        $this->dispatch('kosten-aktualisiert');   // #379+: Werkstatt-Cockpit live nachziehen
    }

    public function cateringBeispielwerte(): void
    {
        try {
            app(FixkostenService::class)->cateringBeispielwerte($this->team());
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        $this->bezugsbasen = array_map(fn ($value) => $this->fmt((float) $value), FixkostenService::CATERING_EXAMPLE_BASES);
        $this->alleAutomatisch();
        $this->speichern();
        $this->ladeFix();
        $this->meldung = 'Catering-Beispielwerte berechnet. Bitte anschließend auf den eigenen Betrieb anpassen.';
        $this->fehler = null;
    }

    public function speichern(): void
    {
        $outlet = $this->scopeOutlet();
        if ($outlet !== null) {
            $this->speichereBetrieb($outlet);

            return;
        }

        $svc = app(TeamSettingsService::class);
        $gemeinWert = 0.0;
        $stundensatz = $svc->stundensatz($this->team());
        foreach ($this->schema as $b) {
            if ($b['key'] === 'gemeinkosten') {
                $gemeinWert = $this->num((string) $b['value']);
            }
            if ($b['type'] === 'arbeitszeit') {
                $stundensatz = $this->num((string) $b['value']);
            }
        }

        $svc->update($this->team(), [
            'hk2_surcharge_pct' => $gemeinWert,                  // Rückwärtskompatibel (= Material-GK manuell)
            'stundensatz_eur' => $stundensatz,
            'margin_pct' => $this->num($this->marge),
            'target_food_cost_pct' => $this->num($this->zielWe),
            'labor_overhead_pct' => $this->num($this->lnk),
            'labor_cost_source' => in_array($this->laborSource, ['team_flat', 'station_roles'], true) ? $this->laborSource : 'team_flat',
            'calculation_schema' => $this->baueSchema(),
            'calculation_reference_bases' => [
                'mek' => $this->num((string) $this->bezugsbasen['mek']),
                'fek' => $this->num((string) $this->bezugsbasen['fek']),
                'hk' => $this->num((string) $this->bezugsbasen['hk']),
            ],
        ]);
        app(\Platform\FoodAlchemist\Services\PricingCascadeService::class)->recomputeTeam($this->team());
        $this->meldung = 'Gespeichert — Kalkulation & Cockpits nutzen diese Werte.';
        $this->dispatch('kosten-aktualisiert');   // #379+: Werkstatt-Cockpit live nachziehen
    }

    /**
     * Ebene 2: Betriebs-Override schreiben. Skalare pro Feld (leer = null = erbt vom Team);
     * Zuschlagsschema/Bezugsbasen/Stundensatz/Material-GK nur wenn „eigenes Schema" an — sonst null (erben).
     * labor_cost_source hat keine Outlet-Spalte → bleibt teamweit, wird hier nicht geschrieben.
     */
    private function speichereBetrieb(FoodAlchemistOutlet $outlet): void
    {
        $attr = [
            'margin_pct' => $this->nullBeiLeer($this->marge),
            'target_food_cost_pct' => $this->nullBeiLeer($this->zielWe),
            'labor_overhead_pct' => $this->nullBeiLeer($this->lnk),
        ];

        if ($this->eigenesSchema) {
            $gemeinWert = 0.0;
            $stundensatz = 0.0;
            foreach ($this->schema as $b) {
                if ($b['key'] === 'gemeinkosten') {
                    $gemeinWert = $this->num((string) $b['value']);
                }
                if ($b['type'] === 'arbeitszeit') {
                    $stundensatz = $this->num((string) $b['value']);
                }
            }
            $attr['hk2_surcharge_pct'] = $gemeinWert;
            $attr['stundensatz_eur'] = $stundensatz;
            $attr['calculation_schema'] = $this->baueSchema();
            $attr['calculation_reference_bases'] = [
                'mek' => $this->num((string) $this->bezugsbasen['mek']),
                'fek' => $this->num((string) $this->bezugsbasen['fek']),
                'hk' => $this->num((string) $this->bezugsbasen['hk']),
            ];
        } else {
            // Erbt das Team-Schema komplett.
            $attr['hk2_surcharge_pct'] = null;
            $attr['stundensatz_eur'] = null;
            $attr['calculation_schema'] = null;
            $attr['calculation_reference_bases'] = null;
        }

        app(OutletSettingsService::class)->update($this->team(), $outlet, $attr);
        app(\Platform\FoodAlchemist\Services\PricingCascadeService::class)->recomputeTeam($this->team());
        $this->ladeWerte();   // Roh-Overrides + Erben-Placeholder neu spiegeln
        $this->meldung = 'Betrieb „' . $outlet->name . '" gespeichert — abweichende VK/Kalkulation greift on-the-fly.';
        $this->dispatch('kosten-aktualisiert');
    }

    /** Ebene 2: Betrieb wieder komplett vom Team erben lassen (alle Override-Spalten null). */
    public function aufTeamZuruecksetzen(): void
    {
        $outlet = $this->scopeOutlet();
        if ($outlet === null) {
            return;
        }
        app(OutletSettingsService::class)->update($this->team(), $outlet, [
            'margin_pct' => null, 'target_food_cost_pct' => null, 'labor_overhead_pct' => null,
            'hk2_surcharge_pct' => null, 'stundensatz_eur' => null,
            'calculation_schema' => null, 'calculation_reference_bases' => null,
        ]);
        app(\Platform\FoodAlchemist\Services\PricingCascadeService::class)->recomputeTeam($this->team());
        $this->ladeWerte();
        $this->meldung = 'Betrieb „' . $outlet->name . '" erbt jetzt wieder alle Werte vom Team.';
    }

    /** Leerer String = null (= erbt vom Team); sonst geparste Zahl ≥ 0. */
    private function nullBeiLeer(string $v): ?float
    {
        return trim($v) === '' ? null : $this->num($v);
    }

    /** Schema aus den editierten Zeilen (Reihenfolge = Index × 10). */
    private function baueSchema(): array
    {
        $schema = [];
        $sort = 10;
        foreach ($this->schema as $b) {
            $schema[] = [
                'key' => $b['key'], 'label' => $b['label'], 'type' => $b['type'],
                'value' => $this->num((string) $b['value']),
                'active' => (bool) ($b['active'] ?? false),
                'mode' => in_array($b['mode'] ?? 'manuell', ['manuell', 'abgeleitet'], true) ? $b['mode'] : 'manuell',
                'sort' => $sort,
            ];
            $sort += 10;
        }

        return $schema;
    }

    public function render(FixkostenService $fix)
    {
        $team = $this->team();
        $scopeOutlet = $this->scopeOutlet();
        // Σ je Block im gewählten Scope: Betriebs-Zeilen ersetzen pro Block die Team-Zeilen (sonst geerbt) —
        // spiegelt exakt, was CatalogPricingService::enterpriseBaseRate für diesen Betrieb rechnet.
        $summen = $fix->summeJeBlock($team, $scopeOutlet);

        // #379+: Abgeleitete %-Sätze aus den LIVE-Bezugsbasen rechnen (nicht aus dem DB-Stand),
        // damit der Satz beim Tippen der Basis sofort mitläuft — € rein → % automatisch.
        $liveBasen = [
            'mek' => $this->num((string) ($this->bezugsbasen['mek'] ?? '0')),
            'fek' => $this->num((string) ($this->bezugsbasen['fek'] ?? '0')),
            'hk' => $this->num((string) ($this->bezugsbasen['hk'] ?? '0')),
        ];
        $abgeleitet = [];
        foreach ($this->schema as $b) {
            if (($b['mode'] ?? 'manuell') === 'abgeleitet') {
                $abgeleitet[$b['key']] = $fix->abgeleiteterSatz($team, $b, $summen, $liveBasen);
            }
        }
        $gkBloecke = collect($this->schema)
            ->filter(fn ($b) => in_array($b['type'], ['pct_mek', 'pct_fek', 'pct_hk'], true))
            ->map(fn ($b) => ['key' => $b['key'], 'label' => $b['label']])->values()->all();

        $betriebeOptionen = FoodAlchemistOutlet::where('team_id', $team->id)
            ->where('is_inactive', false)->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'name'])->map(fn ($o) => ['id' => (int) $o->id, 'name' => (string) $o->name])->all();

        // Team-Werte als Placeholder „erbt (X)" im Betrieb-Scope.
        $svc = app(TeamSettingsService::class);
        $teamWerte = [
            'marge' => $this->fmt($svc->margePct($team)),
            'zielWe' => $this->fmt($svc->zielWareneinsatzPct($team)),
            'lnk' => $this->fmt($svc->lohnnebenkostenPct($team)),
        ];

        return view('foodalchemist::livewire.settings.herstellkosten', [
            'abgeleitet' => $abgeleitet,
            'fixSummen' => $summen,
            'liveBasen' => $liveBasen,
            'gkBloecke' => $gkBloecke,
            'betriebeOptionen' => $betriebeOptionen,
            'scopeOutletName' => $scopeOutlet?->name,
            'teamWerte' => $teamWerte,
            'schemaEditierbar' => $this->schemaEditierbar(),
        ]);
    }

    private function team()
    {
        return Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
    }
}
