{{-- R6: Dashboard — Einstieg «Was ist heute zu tun?».
     fa-pass (2026-10-05): auf Bausteine <x-fa::…> umgestellt. Reihenfolge nach Dringlichkeit:
     offene Aufgaben (kritisch zuerst, Erledigtes eingeklappt) · Bestand · Rezepte nach Status ·
     Schnellzugriff, KI-Nutzung und Controlling-Verweis. Alles bleibt klickbar in die Browser
     (mit #[Url]-Filtern). data-dashboard-*-Marker unverändert. --}}
@php
    $zahl = fn ($wert) => $wert === null ? '–' : number_format((int) $wert, 0, ',', '.');
    $mitWort = fn ($wert, string $eins, string $mehr) => $zahl($wert) . ' ' . ((int) $wert === 1 ? $eins : $mehr);
    $offeneAufgaben = array_values(array_filter($aufgaben, fn (array $a) => $a['zahl'] > 0));
    $erledigteAufgaben = array_values(array_filter($aufgaben, fn (array $a) => $a['zahl'] === 0));
    $tonText = ['crit' => 'text-[var(--fa-crit)]', 'warn' => 'text-[var(--fa-warn)]', 'info' => 'text-[var(--fa-info)]', 'ok' => 'text-[var(--fa-ok)]'];
    $tonFlaeche = ['crit' => 'bg-[var(--fa-crit-soft)]', 'warn' => 'bg-[var(--fa-warn-soft)]', 'info' => 'bg-[var(--fa-info-soft)]', 'ok' => 'bg-[var(--fa-ok-soft)]'];
    $kachel = 'group fa-surface flex flex-col gap-1 px-4 py-3 min-w-0 transition-colors duration-150 hover:border-[var(--fa-accent-line)] hover:bg-[var(--fa-hover)]';
    $bestand = [
        ['heroicon-o-cube', 'Grundprodukte', $zahl($kpis['gps'] ?? 0), route('foodalchemist.gps.index'), 'Katalog des Teams und der übergeordneten Teams'],
        ['heroicon-o-building-storefront', 'Lieferantenartikel', $zahl($kpis['las'] ?? 0), route('foodalchemist.gps.index'), 'Aufbereitete Artikel, nicht der ganze Lieferantenkatalog'],
        ['heroicon-o-truck', 'Lieferanten', $zahl($kpis['lieferanten'] ?? 0), route('foodalchemist.suppliers.index'), null],
        ['heroicon-o-book-open', 'Rezepte gesamt', $zahl($kpis['rezepte'] ?? null), route('foodalchemist.recipes.index'), $mitWort($workflow['basis'] ?? 0, 'Basisrezept', 'Basisrezepte') . ' · ' . $mitWort($workflow['vk'] ?? 0, 'Gericht', 'Gerichte')],
    ];
    $rezeptStatus = [
        ['draft', 'Entwurf', $workflow['draft'] ?? 0, route('foodalchemist.recipes.index') . '?status=draft'],
        ['review', 'Prüfen', $workflow['review'] ?? 0, route('foodalchemist.recipes.index') . '?status=review'],
        ['approved', 'Freigegeben', $workflow['approved'] ?? 0, route('foodalchemist.recipes.index') . '?status=approved'],
    ];
    $schnell = [
        ['heroicon-o-book-open', 'Basisrezepte', route('foodalchemist.recipes.index')],
        ['heroicon-o-currency-euro', 'Gerichte', route('foodalchemist.verkauf.index')],
        ['heroicon-o-cube', 'Grundprodukte', route('foodalchemist.gps.index')],
        ['heroicon-o-truck', 'Lieferanten', route('foodalchemist.suppliers.index')],
        ['heroicon-o-chat-bubble-left-right', 'Planung mit Agent', route('foodalchemist.planung.index')],
        ['heroicon-o-adjustments-horizontal', 'Einstellungen', route('foodalchemist.einstellungen')],
    ];
@endphp

<x-ui-page>
    <x-slot name="navbar">
        <x-foodalchemist::shell.page-navbar title="Food Alchemist" icon="heroicon-o-cube" />
    </x-slot>

    <x-ui-page-container padding="px-6 py-6" spacing="space-y-6">

        <x-fa::page-header title="Übersicht" :subtitle="$teamName">
            <x-slot:actions>
                <x-fa::button variant="primary" icon="heroicon-o-clipboard-document-check" :href="route('foodalchemist.review')" data-dashboard-zu-pruefen>
                    Offenes prüfen
                </x-fa::button>
            </x-slot:actions>
        </x-fa::page-header>

        {{-- 1. Heute zu tun: offene Aufgaben nach Dringlichkeit, Erledigtes eingeklappt. --}}
        <x-fa::section title="Heute zu tun" icon="heroicon-o-check-circle"
            :meta="$offeneAufgaben === [] ? null : count($offeneAufgaben) . ' offen'" data-dashboard-workflow>
            @if($offeneAufgaben === [])
                <x-fa::empty icon="heroicon-o-check-badge" title="Nichts offen" compact>
                    Keine Signale, Vorschläge oder Prüfaufgaben. Neue Punkte erscheinen hier, sobald Prüfläufe oder Importe etwas finden.
                </x-fa::empty>
            @else
                <ul class="flex flex-col divide-y divide-[var(--fa-line)] -mx-4 border-t border-[var(--fa-line)]">
                    @foreach($offeneAufgaben as $aufgabe)
                        <li wire:key="aufgabe-{{ $aufgabe['key'] }}">
                            <a href="{{ $aufgabe['url'] }}" class="group flex items-center gap-3 px-4 py-3 transition-colors duration-150 hover:bg-[var(--fa-hover)]" data-dashboard-aufgabe="{{ $aufgabe['key'] }}">
                                <span class="flex items-center justify-center w-9 h-9 shrink-0 rounded-[var(--fa-radius-control)] {{ $tonFlaeche[$aufgabe['ton']] ?? '' }} {{ $tonText[$aufgabe['ton']] ?? '' }}">
                                    @svg($aufgabe['icon'], 'w-5 h-5')
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="block text-[length:var(--fa-text-base)] font-medium text-[var(--fa-ink)] group-hover:text-[var(--fa-accent)]">{{ $aufgabe['titel'] }}</span>
                                    <span class="block text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">{{ $aufgabe['text'] }}</span>
                                </span>
                                <span class="shrink-0 tabular-nums text-[length:var(--fa-text-lg)] font-semibold {{ $tonText[$aufgabe['ton']] ?? 'text-[var(--fa-ink)]' }}">{{ $zahl($aufgabe['zahl']) }}</span>
                                @svg('heroicon-m-chevron-right', 'w-4 h-4 shrink-0 text-[var(--fa-ink-3)] group-hover:text-[var(--fa-accent)]')
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif

            @if($erledigteAufgaben !== [])
                <details class="group">
                    <summary class="inline-flex items-center gap-1 cursor-pointer select-none text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)] hover:text-[var(--fa-ink)]">
                        @svg('heroicon-m-chevron-right', 'w-4 h-4 transition-transform group-open:rotate-90')
                        {{ count($erledigteAufgaben) }} {{ count($erledigteAufgaben) === 1 ? 'Punkt' : 'Punkte' }} ohne offene Aufgabe
                    </summary>
                    <ul class="mt-2 flex flex-wrap gap-x-4 gap-y-1.5">
                        @foreach($erledigteAufgaben as $aufgabe)
                            <li wire:key="erledigt-{{ $aufgabe['key'] }}">
                                <x-fa::signal tone="ok">{{ $aufgabe['titel'] }}</x-fa::signal>
                            </li>
                        @endforeach
                    </ul>
                </details>
            @endif
        </x-fa::section>

        {{-- 2. Bestand: die vier Kataloge, jede Zahl führt in ihre Liste. --}}
        <section class="flex flex-col gap-3" data-dashboard-bestand>
            <h2 class="text-[length:var(--fa-text-lg)] font-semibold text-[var(--fa-ink)]">Bestand</h2>
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
                @foreach($bestand as [$icon, $titel, $wert, $url, $hint])
                    <a href="{{ $url }}" class="{{ $kachel }}" wire:key="kachel-{{ $titel }}">
                        <span class="inline-flex items-center gap-1.5 text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]">@svg($icon, 'w-4 h-4 shrink-0 text-[var(--fa-ink-3)]') {{ $titel }}</span>
                        <span class="text-[length:var(--fa-text-2xl)] font-semibold tracking-tight tabular-nums leading-tight text-[var(--fa-ink)] group-hover:text-[var(--fa-accent)]">{{ $wert }}</span>
                        @if($hint)<span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">{{ $hint }}</span>@endif
                    </a>
                @endforeach
            </div>

            {{-- Rezepte nach Status + Vorlagen: führt direkt in den gefilterten Rezept-Browser. --}}
            <div class="fa-surface flex flex-wrap items-center gap-x-6 gap-y-2 px-4 py-3" data-dashboard-rezeptstatus>
                <span class="text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]">Rezepte nach Status</span>
                @foreach($rezeptStatus as [$wert, $text, $anzahl, $url])
                    <a href="{{ $url }}" class="inline-flex items-center gap-2 group" wire:key="status-{{ $wert }}">
                        <x-fa::status :value="$wert" />
                        <span class="tabular-nums text-[length:var(--fa-text-md)] font-semibold text-[var(--fa-ink)] group-hover:text-[var(--fa-accent)]">{{ $zahl($anzahl) }}</span>
                    </a>
                @endforeach
                <a href="{{ route('foodalchemist.recipes.index') }}?templates=1" class="inline-flex items-center gap-1.5 group sm:ml-auto">
                    @svg('heroicon-o-square-2-stack', 'w-4 h-4 text-[var(--fa-ink-3)]')
                    <span class="text-[length:var(--fa-text-md)] text-[var(--fa-ink-2)] group-hover:text-[var(--fa-accent)]">Vorlagen</span>
                    <span class="tabular-nums text-[length:var(--fa-text-md)] font-semibold text-[var(--fa-ink)] group-hover:text-[var(--fa-accent)]">{{ $zahl($workflow['templates'] ?? 0) }}</span>
                </a>
            </div>
        </section>

        {{-- 3. Nebensächliches: Schnellzugriff, KI-Nutzung, Controlling-Verweis. --}}
        <div class="grid lg:grid-cols-3 gap-3" data-dashboard-unten>
            <x-fa::section title="Schnellzugriff" icon="heroicon-o-squares-2x2" class="lg:col-span-2" data-dashboard-links>
                <div class="flex flex-wrap gap-2">
                    @foreach($schnell as [$icon, $text, $url])
                        <x-fa::button size="sm" :icon="$icon" :href="$url" wire:key="schnell-{{ $text }}">{{ $text }}</x-fa::button>
                    @endforeach
                </div>
                <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">In den Listen öffnet ein Klick auf den Namen den Editor, ein Klick auf die Zeile das Detail rechts.</p>
            </x-fa::section>

            <x-fa::section title="KI-Nutzung" icon="heroicon-o-sparkles" meta="dieses Team" data-dashboard-ki>
                <p class="flex items-baseline gap-1.5">
                    <span class="text-[length:var(--fa-text-2xl)] font-semibold tracking-tight tabular-nums text-[var(--fa-ink)]">{{ $zahl($ki['calls']) }}</span>
                    <span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]">KI-Anfragen</span>
                </p>
                <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">{{ $zahl($ki['accepted']) }} übernommene Vorschläge</p>
                <a href="{{ route('foodalchemist.einstellungen', ['sektion' => 'ki']) }}" class="inline-flex items-center gap-1 text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-accent)] hover:text-[var(--fa-accent-hover)]">
                    KI-Einstellungen öffnen @svg('heroicon-m-arrow-right', 'w-3.5 h-3.5')
                </a>
            </x-fa::section>
        </div>

        {{-- Spec 32: der R2.7-Portfolio-Benchmark ist ins Controlling-Zentrum gewandert (Tab „Lage").
             Er kostet einen Kennzahlen-Lauf je Peer-Team und gehört fachlich zur Wirtschaftlichkeit,
             nicht zur Bestandsübersicht. Hier bleibt nur der Verweis — ein zweiter Anzeige-Ort
             wäre ein zweiter Pflege-Ort. --}}
        <a href="{{ route('foodalchemist.controlling.index', ['tab' => 'lage']) }}" wire:navigate
           class="group fa-surface flex items-center gap-3 px-4 py-3 transition-colors duration-150 hover:border-[var(--fa-accent-line)] hover:bg-[var(--fa-hover)]"
           data-dashboard-controlling>
            @svg('heroicon-o-presentation-chart-line', 'w-5 h-5 shrink-0 text-[var(--fa-ink-3)]')
            <span class="min-w-0 flex-1">
                <span class="block text-[length:var(--fa-text-base)] font-medium text-[var(--fa-ink)] group-hover:text-[var(--fa-accent)]">Controlling</span>
                <span class="block text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Wareneinsatz, Preise, Simulation, Portfolio-Vergleich und Geld-Signale, jeweils mit den Hebeln daneben.</span>
            </span>
            @svg('heroicon-m-chevron-right', 'w-4 h-4 shrink-0 text-[var(--fa-ink-3)] group-hover:text-[var(--fa-accent)]')
        </a>

    </x-ui-page-container>
    {{-- Spec 53/F Stufe 2: Sprachbefehl-Mount auf Seitenebene (Modal + optionales schwebendes Element). --}}
</x-ui-page>
