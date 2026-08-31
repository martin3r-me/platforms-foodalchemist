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
     * „Volle Kopie, KEINE Vererbung": ein gewählter Betrieb ist eine eigenständige Kalkulation —
     * die Felder werden mit den Team-Werten vorbefüllt (real, editierbar) und beim Speichern als
     * EIGENE Werte geschrieben; die Team-Fixkosten werden als eigene Zeilen übernommen.
     */
    public ?int $outletId = null;

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
     * Lädt alle Felder als EFFEKTIVE Werte (Betrieb-Override, sonst Team) — reale, editierbare Zahlen.
     * Kein „erbt"-Platzhalter mehr: ein gewählter Betrieb zeigt die Team-Werte als Startpunkt und
     * speichert sie beim Speichern als EIGENE (volle Kopie, keine Vererbung).
     */
    private function ladeWerte(): void
    {
        $svc = app(TeamSettingsService::class);
        $team = $this->team();
        $outlet = $this->scopeOutlet();

        $this->marge = $this->fmt($svc->margePct($team, $outlet));
        $this->zielWe = $this->fmt($svc->zielWareneinsatzPct($team, $outlet));
        $this->lnk = $this->fmt($svc->lohnnebenkostenPct($team, $outlet));
        $this->laborSource = $svc->laborCostSource($team, $outlet);

        // Zuschlagsschema + Bezugsbasen: effektiv (kaskadiert). Arbeitszeit-Block = effektiver Stundensatz.
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
        foreach ($this->schema as $i => $b) {
            if (in_array($b['type'], ['pct_mek', 'pct_fek', 'pct_hk'], true)) {
                $this->schema[$i]['mode'] = 'abgeleitet';
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
     * Ebene 2 (volle Kopie, KEINE Vererbung): ALLE Felder als EIGENE Werte des Betriebs schreiben
     * (nie null) — Skalare + Lohnquelle + ganzes Zuschlagsschema + Bezugsbasen. Beim ersten Speichern
     * werden zusätzlich die Team-Fixkosten als eigene Zeilen übernommen (Startpunkt statt 0).
     */
    private function speichereBetrieb(FoodAlchemistOutlet $outlet): void
    {
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

        app(OutletSettingsService::class)->update($this->team(), $outlet, [
            'hk2_surcharge_pct' => $gemeinWert,
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
        // Volle Kopie: beim ersten Mal die Team-Fixkosten als eigene übernehmen (idempotent — nur wenn keine eigenen da).
        $kopiert = app(FixkostenService::class)->uebernimmTeamFixkosten($this->team(), $outlet);
        app(\Platform\FoodAlchemist\Services\PricingCascadeService::class)->recomputeTeam($this->team());
        $this->ladeWerte();
        $this->meldung = 'Betrieb „' . $outlet->name . '" gespeichert — eigenständige Kalkulation'
            . ($kopiert > 0 ? " ($kopiert Team-Fixkosten übernommen, jetzt eigenständig editierbar)." : '.');
        $this->dispatch('kosten-aktualisiert');
    }

    /** Ebene 2: die Team-Fixkosten explizit als eigene Zeilen für den Betrieb übernehmen (Startpunkt). */
    public function teamFixkostenUebernehmen(): void
    {
        $outlet = $this->scopeOutlet();
        if ($outlet === null) {
            return;
        }
        $n = app(FixkostenService::class)->uebernimmTeamFixkosten($this->team(), $outlet);
        app(\Platform\FoodAlchemist\Services\PricingCascadeService::class)->recomputeTeam($this->team());
        $this->ladeFix();
        $this->meldung = $n > 0
            ? $n . ' Team-Fixkosten für „' . $outlet->name . '" übernommen.'
            : 'Betrieb hat bereits eigene Fixkosten.';
        $this->dispatch('kosten-aktualisiert');
    }

    /** Ebene 2: Betrieb komplett zurücksetzen — alle Overrides + eigenen Fixkosten löschen (Neustart vom Team). */
    public function aufTeamZuruecksetzen(): void
    {
        $outlet = $this->scopeOutlet();
        if ($outlet === null) {
            return;
        }
        app(OutletSettingsService::class)->update($this->team(), $outlet, [
            'margin_pct' => null, 'target_food_cost_pct' => null, 'labor_overhead_pct' => null,
            'hk2_surcharge_pct' => null, 'stundensatz_eur' => null, 'labor_cost_source' => null,
            'calculation_schema' => null, 'calculation_reference_bases' => null,
        ]);
        app(FixkostenService::class)->loescheAlleFuerOutlet($this->team(), $outlet);
        app(\Platform\FoodAlchemist\Services\PricingCascadeService::class)->recomputeTeam($this->team());
        $this->ladeWerte();
        $this->meldung = 'Betrieb „' . $outlet->name . '" zurückgesetzt — zeigt wieder die Team-Werte als Startpunkt.';
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
        // Σ je Block im gewählten Scope: KEINE Vererbung — Betrieb zählt nur eigene Zeilen (sonst 0);
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

        return view('foodalchemist::livewire.settings.herstellkosten', [
            'abgeleitet' => $abgeleitet,
            'fixSummen' => $summen,
            'liveBasen' => $liveBasen,
            'gkBloecke' => $gkBloecke,
            'betriebeOptionen' => $betriebeOptionen,
            'scopeOutletName' => $scopeOutlet?->name,
            'hatEigeneFix' => count($this->fixListe) > 0,
        ]);
    }

    private function team()
    {
        return Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
    }
}
