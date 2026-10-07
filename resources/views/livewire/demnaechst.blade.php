{{-- R7: «In Planung». Was im Food.Alchemist noch kommt, ehrlich und knapp.
     fa-pass 2026-10-05: Bausteine statt Ui-Maps, Heroicons statt Emoji (die Symbole aus
     Demnaechst::DOMAENEN werden nicht mehr gezeigt), interne Meilenstein- und Ticket-Codes
     ausgeblendet. Bereiche, die schon verfügbar sind, stehen nicht mehr als „geplant" da,
     sondern als kurzer Hinweis darunter. data-demnaechst-liste bleibt. --}}
@php
    $symbol = [
        'Foodbook / Portfolio' => 'heroicon-o-book-open', 'Kalkulation (HK2)' => 'heroicon-o-calculator',
        'Produktionsplanung' => 'heroicon-o-building-office-2', 'Speiseplan' => 'heroicon-o-calendar-days',
        'Speisekarte' => 'heroicon-o-document-text', 'Einkauf' => 'heroicon-o-shopping-cart',
        'Lager' => 'heroicon-o-archive-box', 'Controlling' => 'heroicon-o-chart-bar',
    ];
    // Demnaechst::DOMAENEN ist älter als Controlling-Cockpit (Simulation, Ist gegen Rezeptur) und
    // Produktions-Tagesplan. Damit die Seite nichts als „geplant" ausgibt, was es schon gibt,
    // gelten diese hier als verfügbar bzw. teilweise verfügbar.
    $schonDa = ['Kalkulation (HK2)' => 'Kalkulation', 'Controlling' => 'Controlling'];
    $teilweise = ['Produktionsplanung' => 'Der Tagesplan unter Produktion ist schon nutzbar.'];
    $istFertig = fn (array $d): bool => str_contains((string) $d['status'], 'Fertig') || isset($schonDa[$d['name']]);
    // Interne Kürzel (V-25, M11+ …) gehören nicht in den sichtbaren Text.
    $sauber = fn (string $text): string => trim((string) preg_replace_callback(
        '/\s+[—–]\s+(\p{L})/u',
        fn ($m) => '. ' . mb_strtoupper($m[1]),
        (string) preg_replace(
            ['/\s*\((?:V|M)-?\d+\+?\)/u', '/\s*×\s*Lead-LA/u', '/Yield-Mathematik/u', '/Artikel\/GP/u', '/\s+/u'],
            ['', ' und bevorzugtem Lieferantenartikel', 'Mengenumrechnung', 'Artikel und Grundprodukt', ' '],
            $text
        )
    ));
    $geplant = array_values(array_filter($domaenen, fn ($d) => ! $istFertig($d)));
    $verfuegbar = array_values(array_filter($domaenen, $istFertig));
@endphp

<x-ui-page>
    <x-slot name="navbar">
        <x-foodalchemist::shell.page-navbar title="In Planung" icon="heroicon-o-light-bulb" />
    </x-slot>

    <x-ui-page-container padding="px-6 py-6" spacing="space-y-5">
        <x-fa::page-header title="In Planung" subtitle="Diese Bereiche sind geplant, aber noch nicht terminiert." />

        <div class="grid gap-3 grid-cols-[repeat(auto-fit,minmax(min(100%,22rem),1fr))]" data-demnaechst-liste>
            @foreach($geplant as $d)
                <div class="fa-surface p-4 flex gap-3 min-w-0" wire:key="dom-{{ $loop->index }}">
                    @svg($symbol[$d['name']] ?? 'heroicon-o-light-bulb', 'w-5 h-5 shrink-0 mt-0.5 text-[var(--fa-ink-3)]')
                    <div class="min-w-0 flex flex-col gap-1.5">
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="text-[length:var(--fa-text-base)] font-semibold text-[var(--fa-ink)]">{{ $d['name'] }}</p>
                            @if(isset($teilweise[$d['name']]))
                                <x-fa::badge tone="info">Teilweise verfügbar</x-fa::badge>
                            @else
                                <x-fa::badge>Geplant</x-fa::badge>
                            @endif
                        </div>
                        <p class="text-[length:var(--fa-text-md)] leading-relaxed text-[var(--fa-ink-2)]">{{ $sauber($d['idee']) }}</p>
                        @if(isset($teilweise[$d['name']]))<p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">{{ $teilweise[$d['name']] }}</p>@endif
                    </div>
                </div>
            @endforeach
        </div>

        @if($verfuegbar !== [])
            <x-fa::notice tone="ok" title="Schon verfügbar">
                {{ collect($verfuegbar)->map(fn ($d) => $schonDa[$d['name']] ?? $d['name'])->implode(', ') }}. Diese Bereiche findest du in der Navigation.
            </x-fa::notice>
        @endif
    </x-ui-page-container>
    {{-- Spec 53/F Stufe 2: Sprachbefehl-Mount auf Seitenebene (Modal + optionales schwebendes Element). --}}
</x-ui-page>
