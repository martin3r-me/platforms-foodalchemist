{{-- Spec 32 — Controlling-Zentrum: Lagebild (Seite) + Werkbank (Voll-Editor).

     Die Seite darunter ist bewusst schmal. Sie existiert, damit das Schließen des Editors nicht
     auf einer leeren Fläche landet und damit Deep-Links (`?editor=0`) ein Ziel haben. Gearbeitet
     wird im Editor.

     fa-pass 2026-10-05: auf Bausteine <x-fa::…> und --fa-*-Tokens umgestellt. Lagebild als
     klickbare Kennzahl-Leiste (fa-kpis-Optik, eine Hauptzahl), Seitenkopf mit der einen
     Hauptaktion, fehlende Werte als Hinweis statt 0,00 €. Anordnung, Tabs, Panels und alle
     wire:-/data-Marker unverändert. --}}
@php
    $eur = fn ($v) => number_format((float) $v, 2, ',', '.') . ' €';
    $pct = fn ($v) => $v === null ? null : number_format((float) $v, 1, ',', '.') . ' %';
    // Ampel → kpi-tiles-Tone. „unbekannt" bleibt neutral: ohne bepreiste Gerichte gibt es nichts
    // zu bewerten, und eine graue Kachel lügt weniger als eine grüne.
    $ampelTone = ['gruen' => 'good', 'gelb' => 'warn', 'rot' => 'bad', 'unbekannt' => 'neutral'];
    $ampelBadge = [
        'gruen' => ['ok', 'Im Ziel'],
        'gelb' => ['warn', 'Über Ziel'],
        'rot' => ['crit', 'Deutlich über Ziel'],
    ];

    // Fehlende Werte zeigen, nicht als 0 ausgeben: ohne Fixkosten gibt es keinen Break-even,
    // ohne Einkaufsjournal keinen Einkauf. Der Food.Alchemist schätzt nicht.
    $kacheln = [];
    if (! ($kpi['leer'] ?? true)) {
        $breakEvenText = ($kpi['fixkosten_monat'] ?? 0) > 0 ? $eur($kpi['break_even']) : null;
        $spendText = ($kpi['spend_30d'] ?? 0) > 0 ? $eur($kpi['spend_30d']) : null;
        $kacheln = [
            ['label' => 'Ø Wareneinsatz', 'wert' => $pct($kpi['avg_w_pct']), 'fehlt' => 'Keine bepreisten Gerichte', 'tab' => 'wareneinsatz', 'primary' => true,
                'satz' => 'Einkaufspreis gegen Verkaufspreis über alle bepreisten Gerichte'],
            ['label' => 'Ziel-Wareneinsatz', 'wert' => $pct($kpi['ziel_we_pct']), 'fehlt' => 'Kein Ziel gesetzt', 'tab' => 'kennzahlen',
                'satz' => 'Zielquote aus den Kalkulations-Einstellungen'],
            ['label' => 'EK-Abdeckung', 'wert' => $pct($kpi['ek_coverage_pct']), 'fehlt' => 'Keine Gerichte', 'tab' => 'lage',
                'satz' => number_format((int) $kpi['n_dishes'], 0, ',', '.') . ' Gerichte im Portfolio, Anteil mit Einkaufspreis'],
            ['label' => 'Einkauf 30 Tage', 'wert' => $spendText, 'fehlt' => 'Kein Einkauf erfasst', 'tab' => 'wareneinsatz',
                'satz' => 'Tatsächliche Ausgaben laut Einkaufsjournal'],
            ['label' => 'Break-even je Monat', 'wert' => $breakEvenText, 'fehlt' => 'Fixkosten fehlen', 'tab' => 'kennzahlen',
                'satz' => 'Fixkosten geteilt durch die Deckungsbeitragsquote, eine Planungsgröße'],
            ['label' => 'Offene Geld-Signale', 'wert' => number_format($kpi['geld_signale'], 0, ',', '.'), 'fehlt' => null, 'tab' => 'signale',
                'satz' => 'Befunde zu Preis, Marge und Wareneinsatz', 'ton' => $kpi['geld_signale'] > 0 ? 'warn' : null],
        ];
    }
@endphp

<x-ui-page>
    <x-slot name="navbar">
        <x-foodalchemist::shell.page-navbar title="Controlling" icon="heroicon-o-presentation-chart-line" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Food Alchemist', 'href' => route('foodalchemist.dashboard'), 'icon' => 'cube'],
            ['label' => 'Controlling'],
        ]" />
    </x-slot>

    <x-ui-page-container padding="px-6 py-6" spacing="space-y-5">
        <x-fa::page-header title="Controlling" subtitle="Wareneinsatz, Preise, Erlöse und Kennzahlen eines Betriebs an einem Ort.">
            <x-slot:actions>
                <x-fa::button variant="primary" icon="heroicon-o-arrows-pointing-out" wire:click="oeffnen" data-ctrl-oeffnen>
                    Werkbank öffnen
                </x-fa::button>
            </x-slot:actions>
        </x-fa::page-header>

        @if($kpi['leer'])
            <div class="fa-surface">
                <x-fa::empty icon="heroicon-o-user-group" title="Kein Team zugeordnet">
                    Ohne Team gibt es keine Zahlen. Wähle oben ein Team aus oder lass dich einem Team zuordnen.
                </x-fa::empty>
            </div>
        @else
            {{-- Ebene 2: welcher Betrieb treibt die Kostenstruktur-Werte (aktiver Betrieb aus dem Sidebar-Balken). --}}
            @if(!empty($kpi['betrieb_name']))
                <div data-ctrl-betrieb>
                    <x-fa::notice tone="info" :title="'Betrieb ' . $kpi['betrieb_name']">
                        Ziel-Wareneinsatz und Break-even gelten für diesen Betrieb. Ø Wareneinsatz, EK-Abdeckung und Einkauf gelten für das ganze Team.
                    </x-fa::notice>
                </div>
            @endif

            {{-- Lagebild: dieselben sechs Werte wie im Editor-Kopf, hier als Sprungbrett.
                 Ein Klick öffnet die Werkbank direkt im zuständigen Tab. Optik der Kennzahl-Leiste
                 (fa-kpis), eine Hauptzahl: der Ø Wareneinsatz. --}}
            <div class="fa-kpis" data-ctrl-lagebild>
                @foreach($kacheln as $k)
                    <button type="button" wire:click="oeffnen('{{ $k['tab'] }}')" title="{{ $k['satz'] }}"
                            class="fa-kpi text-left transition-colors duration-150 hover:bg-[var(--fa-hover)] focus-visible:outline-2 focus-visible:outline-[color:var(--fa-accent)]">
                        <span class="text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)] truncate">{{ $k['label'] }}</span>
                        @if($k['wert'] === null)
                            <span class="mt-1"><x-fa::badge tone="warn">{{ $k['fehlt'] }}</x-fa::badge></span>
                        @elseif(!empty($k['primary']))
                            <span class="text-[length:var(--fa-text-2xl)] font-semibold tracking-tight leading-tight tabular-nums text-[var(--fa-accent)]">{{ $k['wert'] }}</span>
                            @if(isset($ampelBadge[$kpi['we_ampel']]))
                                <span class="mt-0.5"><x-fa::badge :tone="$ampelBadge[$kpi['we_ampel']][0]">{{ $ampelBadge[$kpi['we_ampel']][1] }}</x-fa::badge></span>
                            @endif
                        @else
                            <span class="text-[length:var(--fa-text-lg)] font-semibold leading-snug tabular-nums {{ ($k['ton'] ?? null) === 'warn' ? 'text-[var(--fa-warn)]' : 'text-[var(--fa-ink)]' }}">{{ $k['wert'] }}</span>
                        @endif
                        <span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] line-clamp-2">{{ $k['satz'] }}</span>
                    </button>
                @endforeach
            </div>

            <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] max-w-[75ch]">
                Ein Klick auf eine Kennzahl öffnet die Werkbank im passenden Bereich. Dort liegen Portfolio, Preise,
                Wareneinsatz, Simulation, Erfolg, Geld-Signale, Kennzahlen und Verlauf nebeneinander, jeweils mit den Hebeln dazu.
            </p>
        @endif

        {{-- ── Werkbank ────────────────────────────────────────────────────────────────
             Voll-Editor im dunklen Grund (Spec 28/29-Muster). Server-Modus-Tabs: die Panels
             sind zu schwer, um alle gleichzeitig zu leben (Journal-Optimierung, Peer-Benchmark).
             Detail-Sprünge gehören in eigene Modals, NICHT in den activity-Slot: dort kollabiert
             die Hauptspalte auf Höhe 0 (Signale-Blank-Bug 2026-08-02). --}}
        <x-foodalchemist::modal name="controlling-editor" fullscreen dark-canvas
                                title="Controlling" :title-name="\Platform\FoodAlchemist\Livewire\Controlling\Cockpit::TABS[$tab] ?? null">

            @unless($kpi['leer'])
                <x-slot:kpiHeader>
                    {{-- Leitwert ist der Ø Wareneinsatz, die eine Zahl, an der in diesem Modul Geld
                         hängt. Ampel nur dort; nie zwei Alarmfarben nebeneinander. --}}
                    <x-foodalchemist::kpi-tiles :cols="6" marker="controlling-kpis" :tiles="[
                        ['kpi' => 'we-pct', 'label' => 'Ø Wareneinsatz', 'tone' => $ampelTone[$kpi['we_ampel']] ?? 'neutral',
                         'value' => $pct($kpi['avg_w_pct']) ?? '–',
                         'title' => 'Einkaufspreis gegen Verkaufspreis über die bepreisten Gerichte'],
                        ['kpi' => 'we-ziel', 'label' => 'Ziel', 'value' => $pct($kpi['ziel_we_pct']) ?? '–'],
                        ['kpi' => 'ek-coverage', 'label' => 'EK-Abdeckung', 'value' => $pct($kpi['ek_coverage_pct']) ?? '–',
                         'title' => $kpi['n_dishes'] . ' Gerichte im Portfolio'],
                        ['kpi' => 'spend', 'label' => 'Einkauf 30 Tage', 'value' => $kpi['spend_30d'] > 0 ? $eur($kpi['spend_30d']) : 'Nichts erfasst'],
                        ['kpi' => 'break-even', 'label' => 'Break-even je Monat', 'value' => $kpi['fixkosten_monat'] > 0 ? $eur($kpi['break_even']) : 'Fixkosten fehlen'],
                        ['kpi' => 'geld-signale', 'label' => 'Geld-Signale', 'value' => number_format($kpi['geld_signale'], 0, ',', '.')],
                    ]" />
                </x-slot:kpiHeader>
            @endunless

            <x-foodalchemist::editor-tabs marker="ctrl" action="setTab" :active="$tab"
                :tabs="\Platform\FoodAlchemist\Livewire\Controlling\Cockpit::TABS" />

            @if($tab === 'lage')
                {{-- Spec 33 P7: der Signal-Verlauf ist raus, er hatte die Lage überladen.
                     Lage ist die Momentaufnahme, Verlauf ist die Bewegung. --}}
                <x-foodalchemist::modal-section title="Vergleich mit der Gruppe" icon="heroicon-o-scale">
                    @include('foodalchemist::livewire.controlling.partials._benchmark', ['benchmark' => $benchmark])
                </x-foodalchemist::modal-section>
            @endif

            @if($tab === 'portfolio')
                <x-foodalchemist::modal-section title="Was läuft wo" icon="heroicon-o-squares-2x2">
                    <livewire:foodalchemist.controlling.panels.portfolio key="controlling-cockpit--controlling.panels.portfolio" />
                </x-foodalchemist::modal-section>
            @endif

            @if($tab === 'verlauf')
                <x-foodalchemist::modal-section title="Signal-Verlauf" icon="heroicon-o-arrow-trending-down">
                    @include('foodalchemist::livewire.controlling.partials._verlauf', ['verlauf' => $verlauf])
                </x-foodalchemist::modal-section>
            @endif

            @if($tab === 'preise')
                <x-foodalchemist::modal-section title="Preisvergleich über Lieferanten" icon="heroicon-o-currency-euro">
                    <livewire:foodalchemist.controlling.panels.preisvergleich key="controlling-cockpit--controlling.panels.preisvergleich" />
                </x-foodalchemist::modal-section>

                <x-foodalchemist::modal-section title="Auffällige Buchungen" icon="heroicon-o-exclamation-triangle">
                    <livewire:foodalchemist.controlling.panels.ausreisser key="controlling-cockpit--controlling.panels.ausreisser" />
                </x-foodalchemist::modal-section>
            @endif

            @if($tab === 'wareneinsatz')
                {{-- Erst die gemessene Quote (C4), dann die Optimierung: die Frage „stimmt der
                     Wareneinsatz überhaupt" kommt vor „wo könnte er günstiger sein". --}}
                <x-foodalchemist::modal-section title="Ist gegen Rezeptur" icon="heroicon-o-scale">
                    <livewire:foodalchemist.controlling.panels.abweichung key="controlling-cockpit--controlling.panels.abweichung" />
                </x-foodalchemist::modal-section>

                <x-foodalchemist::modal-section title="Ist gegen günstigsten Bezug" icon="heroicon-o-arrows-right-left">
                    <livewire:foodalchemist.controlling.panels.wareneinsatz key="controlling-cockpit--controlling.panels.wareneinsatz" />
                </x-foodalchemist::modal-section>
            @endif

            @if($tab === 'simulation')
                <x-foodalchemist::modal-section title="Was wäre wenn" icon="heroicon-o-adjustments-horizontal">
                    @livewire('foodalchemist.kalkulation.simulation')
                </x-foodalchemist::modal-section>
            @endif

            @if($tab === 'erfolg')
                {{-- Spec 33 P6: erst was die laufenden Ausgaben bringen, dann das Gericht-Detail.
                     Die Ausgabe ist die Einheit, in der entschieden wird, das Gericht die, in
                     der nachgesehen wird. --}}
                <x-foodalchemist::modal-section title="Was bringen die laufenden Ausgaben" icon="heroicon-o-banknotes">
                    <livewire:foodalchemist.controlling.panels.promotion key="controlling-cockpit--controlling.panels.promotion" />
                </x-foodalchemist::modal-section>

                {{-- Kein „&amp;" im Titel: der Slot escaped den Wert erneut und im Kopf stand „&AMP;". --}}
                <x-foodalchemist::modal-section title="Verkaufszahlen und Menu-Engineering" icon="heroicon-o-chart-bar">
                    <livewire:foodalchemist.controlling.panels.erfolg key="controlling-cockpit--controlling.panels.erfolg" />
                </x-foodalchemist::modal-section>

                <x-foodalchemist::modal-section title="Verkaufspreise freigeben" icon="heroicon-o-check-badge">
                    <livewire:foodalchemist.controlling.panels.vk-freigabe key="controlling-cockpit--controlling.panels.vk-freigabe" />
                </x-foodalchemist::modal-section>
            @endif

            @if($tab === 'signale')
                <x-foodalchemist::modal-section title="Geld-Signale" icon="heroicon-o-bell-alert">
                    @include('foodalchemist::livewire.controlling.partials._geld-signale', ['kpi' => $kpi])
                </x-foodalchemist::modal-section>
            @endif

            @if($tab === 'kennzahlen')
                <x-foodalchemist::modal-section title="Kalkulations-Kennzahlen" icon="heroicon-o-calculator">
                    <livewire:foodalchemist.controlling.panels.kennzahlen key="controlling-cockpit--controlling.panels.kennzahlen" />
                </x-foodalchemist::modal-section>
            @endif
        </x-foodalchemist::modal>
    </x-ui-page-container>
    {{-- Spec 53/F Stufe 2: Sprachbefehl-Mount auf Seitenebene (Modal + optionales schwebendes Element). --}}
</x-ui-page>
