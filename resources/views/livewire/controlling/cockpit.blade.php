{{-- Spec 32 — Controlling-Zentrum: Lagebild (Seite) + Werkbank (Voll-Editor).

     Die Seite darunter ist bewusst schmal. Sie existiert, damit das Schließen des Editors nicht
     auf einer leeren Fläche landet und damit Deep-Links (`?editor=0`) ein Ziel haben — gearbeitet
     wird im Editor. --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))
@php($eur = fn ($v) => number_format((float) $v, 2, ',', '.') . ' €')
@php($pct = fn ($v) => $v === null ? '—' : number_format((float) $v, 1, ',', '.') . ' %')
{{-- Ampel → kpi-tiles-Tone. „unbekannt" bleibt neutral: ohne bepreiste Gerichte gibt es nichts
     zu bewerten, und eine graue Kachel lügt weniger als eine grüne. --}}
@php($ampelTone = ['gruen' => 'good', 'gelb' => 'warn', 'rot' => 'bad', 'unbekannt' => 'neutral'])

<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="Controlling" icon="heroicon-o-presentation-chart-line" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Food Alchemist', 'href' => route('foodalchemist.dashboard'), 'icon' => 'cube'],
            ['label' => 'Controlling'],
        ]">
            <button type="button" wire:click="oeffnen" class="{{ $btnPrimary }}" data-ctrl-oeffnen>
                @svg('heroicon-o-arrows-pointing-out', 'w-4 h-4')
                Werkbank öffnen
            </button>
        </x-ui-page-actionbar>
    </x-slot>

    <x-ui-page-container>
        @if($kpi['leer'])
            <div class="relative {{ $card }} px-5 py-8 text-center">
                <div class="{{ $cardAccent }}"></div>
                <p class="text-sm text-gray-500">Kein Team zugeordnet — ohne Team gibt es keine Zahlen.</p>
            </div>
        @else
            {{-- Lagebild: dieselben sechs Werte wie im Editor-Kopf, hier als Sprungbrett.
                 Ein Klick öffnet die Werkbank direkt im zuständigen Tab. --}}
            <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-3" data-ctrl-lagebild>
                @php($kacheln = [
                    ['Ø Wareneinsatz', $pct($kpi['avg_w_pct']), 'wareneinsatz', 'Durchschnitt über die bepreisten Gerichte (EK gegen VK)'],
                    ['Ziel-Wareneinsatz', $pct($kpi['ziel_we_pct']), 'kennzahlen', 'Zielquote aus den Kalkulations-Einstellungen'],
                    ['EK-Abdeckung', $pct($kpi['ek_coverage_pct']), 'lage', $kpi['n_dishes'] . ' Gerichte im Portfolio — wie viele davon einen EK tragen'],
                    ['Einkauf 30 Tage', $eur($kpi['spend_30d']), 'wareneinsatz', 'Ist-Ausgaben aus dem Einkaufsjournal'],
                    ['Break-even / Monat', $eur($kpi['break_even']), 'kennzahlen', 'Σ Fixkosten ÷ Deckungsbeitragsquote — Planungs-Näherung'],
                    ['Geld-Signale offen', number_format($kpi['geld_signale'], 0, ',', '.'), 'signale', 'Preis-, Marge- und Wareneinsatz-Befunde'],
                ])
                @foreach($kacheln as [$titel, $wert, $zielTab, $hinweis])
                    <button type="button" wire:click="oeffnen('{{ $zielTab }}')" title="{{ $hinweis }}"
                            class="relative {{ $card }} px-4 py-3 text-left hover:-translate-y-0.5 hover:shadow-md transition-all duration-150">
                        <div class="{{ $cardAccent }}"></div>
                        <div class="{{ $label }}">{{ $titel }}</div>
                        <div class="mt-1 text-xl font-semibold tracking-tight text-gray-900">{{ $wert }}</div>
                    </button>
                @endforeach
            </div>

            <p class="mt-4 text-xs text-gray-500">
                Die Auswertung passiert in der Werkbank — Portfolio, Preise, Wareneinsatz,
                Simulation, Erfolg, Geld-Signale, Kennzahlen und Verlauf liegen dort als Tabs
                nebeneinander, jeweils mit den Hebeln daneben.
            </p>
        @endif

        {{-- ── Werkbank ────────────────────────────────────────────────────────────────
             Voll-Editor im dunklen Grund (Spec 28/29-Muster). Server-Modus-Tabs: die Panels
             sind zu schwer, um alle gleichzeitig zu leben (Journal-Optimierung, Peer-Benchmark).
             Detail-Sprünge gehören in eigene Modals, NICHT in den activity-Slot — dort kollabiert
             die Hauptspalte auf Höhe 0 (Signale-Blank-Bug 2026-08-02). --}}
        <x-foodalchemist::modal name="controlling-editor" fullscreen dark-canvas
                                title="Controlling" :title-name="\Platform\FoodAlchemist\Livewire\Controlling\Cockpit::TABS[$tab] ?? null">

            @unless($kpi['leer'])
                <x-slot:kpiHeader>
                    {{-- Leitwert (accent) ist der Ø Wareneinsatz — die eine Zahl, an der in diesem
                         Modul Geld hängt. Ampel nur dort; nie zwei Alarmfarben nebeneinander. --}}
                    <x-foodalchemist::kpi-tiles :cols="6" marker="controlling-kpis" :tiles="[
                        ['kpi' => 'we-pct', 'label' => 'Ø Wareneinsatz', 'tone' => $ampelTone[$kpi['we_ampel']] ?? 'neutral',
                         'value' => $pct($kpi['avg_w_pct']),
                         'title' => 'Ø EK gegen VK über die bepreisten Gerichte'],
                        ['kpi' => 'we-ziel', 'label' => 'Ziel', 'value' => $pct($kpi['ziel_we_pct'])],
                        ['kpi' => 'ek-coverage', 'label' => 'EK-Abdeckung', 'value' => $pct($kpi['ek_coverage_pct']),
                         'title' => $kpi['n_dishes'] . ' Gerichte im Portfolio'],
                        ['kpi' => 'spend', 'label' => 'Einkauf 30 T.', 'value' => $eur($kpi['spend_30d'])],
                        ['kpi' => 'break-even', 'label' => 'Break-even/Mon.', 'value' => $eur($kpi['break_even'])],
                        ['kpi' => 'geld-signale', 'label' => 'Geld-Signale', 'value' => number_format($kpi['geld_signale'], 0, ',', '.')],
                    ]" />
                </x-slot:kpiHeader>
            @endunless

            <x-foodalchemist::editor-tabs marker="ctrl" action="setTab" :active="$tab"
                :tabs="\Platform\FoodAlchemist\Livewire\Controlling\Cockpit::TABS" />

            @if($tab === 'lage')
                {{-- Spec 33 P7: der Signal-Verlauf ist raus — er hatte die Lage überladen.
                     Lage ist die Momentaufnahme, Verlauf ist die Bewegung. --}}
                <x-foodalchemist::modal-section title="Portfolio-Benchmark">
                    @include('foodalchemist::livewire.controlling.partials._benchmark', ['benchmark' => $benchmark])
                </x-foodalchemist::modal-section>
            @endif

            @if($tab === 'portfolio')
                <x-foodalchemist::modal-section title="Wer fährt gerade was">
                    <livewire:foodalchemist.controlling.panels.portfolio />
                </x-foodalchemist::modal-section>
            @endif

            @if($tab === 'verlauf')
                <x-foodalchemist::modal-section title="Signal-Verlauf">
                    @include('foodalchemist::livewire.controlling.partials._verlauf', ['verlauf' => $verlauf])
                </x-foodalchemist::modal-section>
            @endif

            @if($tab === 'preise')
                <x-foodalchemist::modal-section title="Preisvergleich über Lieferanten">
                    <livewire:foodalchemist.controlling.panels.preisvergleich />
                </x-foodalchemist::modal-section>

                <x-foodalchemist::modal-section title="Auffällige Buchungen">
                    <livewire:foodalchemist.controlling.panels.ausreisser />
                </x-foodalchemist::modal-section>
            @endif

            @if($tab === 'wareneinsatz')
                {{-- Erst die gemessene Quote (C4), dann die Optimierung: die Frage „stimmt der
                     Wareneinsatz überhaupt" kommt vor „wo könnte er günstiger sein". --}}
                <x-foodalchemist::modal-section title="Ist gegen Rezeptur">
                    <livewire:foodalchemist.controlling.panels.abweichung />
                </x-foodalchemist::modal-section>

                <x-foodalchemist::modal-section title="Ist gegen optimalen Bezug">
                    <livewire:foodalchemist.controlling.panels.wareneinsatz />
                </x-foodalchemist::modal-section>
            @endif

            @if($tab === 'simulation')
                <x-foodalchemist::modal-section title="Was wäre wenn">
                    @livewire('foodalchemist.kalkulation.simulation')
                </x-foodalchemist::modal-section>
            @endif

            @if($tab === 'erfolg')
                {{-- Spec 33 P6: erst was die laufenden Ausgaben bringen, dann das Gericht-Detail.
                     Die Ausgabe ist die Einheit, in der entschieden wird — das Gericht die, in
                     der nachgesehen wird. --}}
                <x-foodalchemist::modal-section title="Was bringen die laufenden Ausgaben">
                    <livewire:foodalchemist.controlling.panels.promotion />
                </x-foodalchemist::modal-section>

                {{-- Kein „&amp;" im Titel: der Slot escaped den Wert erneut und im Kopf stand „&AMP;". --}}
                <x-foodalchemist::modal-section title="Verkaufs-Ist und Menu-Engineering">
                    <livewire:foodalchemist.controlling.panels.erfolg />
                </x-foodalchemist::modal-section>

                <x-foodalchemist::modal-section title="Verkaufspreise freigeben">
                    <livewire:foodalchemist.controlling.panels.vk-freigabe />
                </x-foodalchemist::modal-section>
            @endif

            @if($tab === 'signale')
                <x-foodalchemist::modal-section title="Geld-Signale">
                    @include('foodalchemist::livewire.controlling.partials._geld-signale', ['kpi' => $kpi])
                </x-foodalchemist::modal-section>
            @endif

            @if($tab === 'kennzahlen')
                <x-foodalchemist::modal-section title="Kalkulations-Kennzahlen">
                    <livewire:foodalchemist.controlling.panels.kennzahlen />
                </x-foodalchemist::modal-section>
            @endif
        </x-foodalchemist::modal>
    </x-ui-page-container>
</x-ui-page>
