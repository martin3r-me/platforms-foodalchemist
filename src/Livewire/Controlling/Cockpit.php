<?php

namespace Platform\FoodAlchemist\Livewire\Controlling;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\BenchmarkService;
use Platform\FoodAlchemist\Services\FixkostenService;
use Platform\FoodAlchemist\Services\MargeService;
use Platform\FoodAlchemist\Services\PurchaseJournalService;
use Platform\FoodAlchemist\Services\SignalService;
use Platform\FoodAlchemist\Services\SignalTrendService;
use Platform\FoodAlchemist\Services\TeamSettingsService;

/**
 * Spec 32/33 — Controlling-Zentrum.
 *
 * Die Fläche existiert, weil die Controlling-Teile zwar alle gebaut, aber über fünf Seiten
 * verstreut waren (Preisvergleich und Optimierung unter „Einkauf", Simulation hinter
 * `/kalkulation`, Benchmark auf dem Dashboard, Geld-Befunde in den Signalen). Vor allem lagen
 * **Befund und Hebel nie nebeneinander**: man sah den teuren Lieferanten, konnte die
 * Bezugsquelle aber nur im GP-Detail umstellen.
 *
 * Das hier ist deshalb eine **Werkbank, keine Berichtsseite**. Jeder Tab zeigt eine Lage UND
 * trägt die Aktion dazu. Geschrieben wird ausschließlich über die bestehenden Services
 * ({@see \Platform\FoodAlchemist\Services\LeadLaService}, `OrderService`, `VkSnapshotService`,
 * `SignalFixService`) — dort hängen Tenant-Guard, Recompute-Kaskade und Protokoll.
 *
 * **Kein eigenes Objekt.** Jede Maßnahme landet auf einem Objekt, das schon Lifecycle und Audit
 * hat (Lead-LA am GP, Zeile in der Bestellschiene, VK-Snapshot, Signal-Status). Ein zusätzlicher
 * „Controlling-Fall" wäre eine zweite Wahrheit ohne eigenen Nutzen.
 *
 * **Server-Modus-Tabs, bewusst.** Der Alpine-Modus von `editor-tabs` hält alle Panels im DOM;
 * dann liefe bei jedem Livewire-Roundtrip auch die Journal-Optimierung über den ganzen Bestand.
 * Hier hält die Komponente den Tab und rendert nur das aktive Panel.
 *
 * **Der KPI-Kopf muss billig bleiben** — er steht in jedem Tab. Deshalb nur Aggregate mit
 * konstanter Query-Zahl (eigene Portfolio-KPIs, ein Journal-SUM, ein gruppierter Signal-Count,
 * Fixkosten-Summe). Das Einsparpotenzial ist bewusst NICHT im Kopf: es kostet den Optimizer-Lauf
 * und lebt darum im Wareneinsatz-Tab.
 */
class Cockpit extends Component
{
    /**
     * Reihenfolge = Anzeige. Links die Lage, dann die Mehrbetriebs-Sicht, dann die Kostenseite,
     * dann die Erlösseite, zuletzt Befunde und Bewegung.
     */
    public const TABS = [
        'lage' => 'Lage',
        // Spec 33 P4: die Mehrbetriebs-Sicht steht direkt hinter der Lage — „wer fährt gerade
        // was" ist die zweite Frage nach „wie stehen wir da", vor allen Kostendetails.
        'portfolio' => 'Portfolio',
        'preise' => 'Preise',
        'wareneinsatz' => 'Wareneinsatz',
        'simulation' => 'Simulation',
        'erfolg' => 'Erfolg',
        'signale' => 'Geld-Signale',
        'kennzahlen' => 'Kennzahlen',
        // Spec 33 P7: der Signal-Verlauf hat den Lage-Tab überladen (20+ Zeilen unter zwei
        // Kacheln). Lage ist die Momentaufnahme, Verlauf die Bewegung — zwei Fragen, zwei Tabs.
        'verlauf' => 'Verlauf',
    ];

    /**
     * Die geldrelevanten Signaltypen — die Auswahl aus den 39 Fällen von
     * {@see \Platform\FoodAlchemist\Enums\SignalTyp}, die wirtschaftlich und nicht
     * datenqualitativ sind. Die Signale-Seite bleibt die Datenqualitäts-Werkbank;
     * hier wird gefiltert, nicht dupliziert.
     */
    public const GELD_SIGNALE = [
        'preis_sprung_marge_impact',
        'preis_anomalie',
        'veraltete_preise',
        'marge_unter_ziel',
        'wareneinsatz_ueber_ziel',
        'vk_anpassung_empfohlen',
    ];

    #[Url(as: 'tab')]
    public string $tab = 'lage';

    public function mount(): void
    {
        if (! array_key_exists($this->tab, self::TABS)) {
            $this->tab = 'lage';
        }

        // Der Sidebar-Klick soll direkt im Voll-Editor landen (Dominique). `?editor=0` lässt das
        // Lagebild stehen — für Deep-Links, die bewusst NICHT sofort ein Modal aufreißen sollen.
        if (! request()->has('editor') || request()->boolean('editor')) {
            $this->dispatch('modal.open', name: 'controlling-editor');
        }
    }

    public function setTab(string $tab): void
    {
        if (array_key_exists($tab, self::TABS)) {
            $this->tab = $tab;
        }
    }

    /** Aus dem Lagebild heraus in einen bestimmten Tab des Editors springen. */
    public function oeffnen(?string $tab = null): void
    {
        if ($tab !== null) {
            $this->setTab($tab);
        }

        $this->dispatch('modal.open', name: 'controlling-editor');
    }

    #[On('modal.closed')]
    public function beiModalClosed(?string $name = null): void
    {
        // Kein Form-State, der lecken könnte. Der Handler existiert allein, damit das Lagebild
        // nach dem Schließen neu rendert — Maßnahmen im Editor (Lead-LA, Freigabe, Signal)
        // verändern die Kennzahlen darunter. Livewire rendert nach jeder Aktion ohnehin neu,
        // deshalb reicht der leere Rumpf; ein zusätzliches Refresh-Event wäre ein zweiter Lauf.
    }

    private function team(): ?Team
    {
        return Auth::user()?->currentTeamRelation;
    }

    public function render(
        BenchmarkService $benchmark,
        PurchaseJournalService $journal,
        FixkostenService $fix,
        TeamSettingsService $settings,
        SignalService $signale,
        SignalTrendService $trend,
        MargeService $marge,
    ) {
        $team = $this->team();

        return view('foodalchemist::livewire.controlling.cockpit', [
            'kpi' => $this->kpiKopf($team, $benchmark, $journal, $fix, $settings, $signale, $marge),
            // Peer-Vergleich und Signal-Verlauf kosten je einen Lauf über alle Peer-Teams bzw. die
            // Zeitreihe — nur im Lage-Tab holen, nicht im Kopf.
            'benchmark' => $team !== null && $this->tab === 'lage' ? $benchmark->benchmark($team) : null,
            'verlauf' => $team !== null && $this->tab === 'verlauf'
                ? $trend->uebersicht($team)
                : ['measured_at' => null, 'previous_at' => null, 'metriken' => []],
        ])->layout('platform::layouts.app');
    }

    /**
     * Der immer sichtbare Kennzahlen-Streifen.
     *
     * `ek_coverage_pct` steht bewusst neben dem Ø Wareneinsatz: der Durchschnitt zählt nur
     * Gerichte mit VK **und** EK — ohne die Abdeckung daneben wäre eine schöne Quote aus drei
     * bepreisten Gerichten nicht von einer aus dreihundert zu unterscheiden.
     *
     * @return array<string,mixed>
     */
    private function kpiKopf(
        ?Team $team,
        BenchmarkService $benchmark,
        PurchaseJournalService $journal,
        FixkostenService $fix,
        TeamSettingsService $settings,
        SignalService $signale,
        MargeService $marge,
    ): array {
        if ($team === null) {
            return ['leer' => true];
        }

        $eigen = $benchmark->kpisFuerTeam((int) $team->id);
        $zielWe = $settings->zielWareneinsatzPct($team);

        // Break-even-Umsatz/Monat = Σ Fixkosten ÷ Deckungsbeitragsquote (= 1 − Zielwareneinsatz).
        // Gastro-Standardformel, Planungs-Näherung — dieselbe Rechnung wie bisher auf `/kalkulation`
        // (jetzt Kennzahlen-Tab), damit nicht zwei Break-even-Zahlen im Umlauf sind.
        $fixMonat = array_sum($fix->summeJeBlock($team));
        $dbQuote = max(0.01, 1 - $zielWe / 100);

        $offeneNachTyp = $signale->offeneNachTyp($team);
        $jeTyp = [];
        $geldSignale = 0;
        foreach (self::GELD_SIGNALE as $typ) {
            $n = (int) ($offeneNachTyp[$typ] ?? 0);
            $jeTyp[$typ] = $n;
            $geldSignale += $n;
        }

        return [
            'leer' => false,
            'avg_w_pct' => $eigen['avg_w_pct'],
            'ziel_we_pct' => $zielWe,
            'we_ampel' => $marge->weAmpel($eigen['avg_w_pct'], $zielWe),
            'ek_coverage_pct' => $eigen['ek_coverage_pct'],
            'n_dishes' => $eigen['n_dishes'],
            'spend_30d' => $journal->spend($team, null, now()->subDays(30)->toDateString()),
            'fixkosten_monat' => $fixMonat,
            'break_even' => $fixMonat > 0 ? $fixMonat / $dbQuote : 0.0,
            'geld_signale' => $geldSignale,
            'geld_signale_je_typ' => $jeTyp,
        ];
    }
}
