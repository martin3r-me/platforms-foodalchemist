{{-- R6: Dashboard — Bestand · Workflow · KI, alles klickbar in die Browser (mit #[Url]-Filtern) --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="Food Alchemist" icon="heroicon-o-cube" />
    </x-slot>

    <x-ui-page-container padding="px-6 pb-6" spacing="space-y-5">

        {{-- Bestand --}}
        <div data-dashboard-bestand>
            <p class="{{ $dt }} mb-2">Bestand</p>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                @foreach([
                    ['heroicon-o-cube', 'Grundprodukte', number_format($kpis['gps'], 0, ',', '.'), route('foodalchemist.gps.index'), 'Katalog der Team-Kette (D1)'],
                    ['heroicon-o-building-storefront', 'Lieferantenartikel', number_format($kpis['las'], 0, ',', '.'), route('foodalchemist.gps.index'), 'kuratierte LA-Strukturen'],
                    ['heroicon-o-truck', 'Lieferanten', number_format($kpis['lieferanten'], 0, ',', '.'), route('foodalchemist.suppliers.index'), null],
                    ['heroicon-o-book-open', 'Rezepte gesamt', number_format((int) $kpis['rezepte'], 0, ',', '.'), route('foodalchemist.recipes.index'), ($workflow['basis'] ?? 0) . ' Basis · ' . ($workflow['vk'] ?? 0) . ' VK'],
                ] as [$icon, $titel, $wert, $url, $hint])
                    <a href="{{ $url }}" class="relative overflow-hidden {{ $card }} px-4 py-3 hover:border-violet-500/40 transition-colors group" wire:key="kachel-{{ $titel }}">
                        <div class="{{ $cardAccent }}"></div>
                        <span class="{{ $dt }} inline-flex items-center gap-1.5">@svg($icon, 'w-3.5 h-3.5') {{ $titel }}</span>
                        <p class="text-2xl font-semibold tracking-tight text-gray-900 group-hover:text-violet-700">{{ $wert }}</p>
                        @if($hint)<p class="text-[10px] text-gray-500 mt-0.5">{{ $hint }}</p>@endif
                    </a>
                @endforeach
            </div>
        </div>

        {{-- Workflow: Review-Pipeline + Daten-Lücken (klickbar mit Filter) --}}
        <div data-dashboard-workflow>
            <p class="{{ $dt }} mb-2">Workflow</p>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                <a href="{{ route('foodalchemist.recipes.index') }}?status=review" class="relative overflow-hidden {{ $card }} px-4 py-3 hover:border-amber-500/40 transition-colors">
                    <span class="{{ $dt }}">⏳ Im Review</span>
                    <p class="text-2xl font-semibold text-amber-600">{{ number_format($workflow['review'] ?? 0, 0, ',', '.') }}</p>
                    <p class="text-[10px] text-gray-500 mt-0.5">{{ $workflow['draft'] ?? 0 }} Entwürfe · {{ $workflow['approved'] ?? 0 }} freigegeben</p>
                </a>
                <a href="{{ route('foodalchemist.recipes.index') }}?templates=1" class="relative overflow-hidden {{ $card }} px-4 py-3 hover:border-orange-500/40 transition-colors">
                    <span class="{{ $dt }}">@svg('heroicon-o-square-2-stack', 'w-3.5 h-3.5 inline-block align-middle') Templates</span>
                    <p class="text-2xl font-semibold text-gray-900">{{ $workflow['templates'] ?? 0 }}</p>
                    <p class="text-[10px] text-gray-500 mt-0.5">Vorlagen für «Aus Template»</p>
                </a>
                <a href="{{ route('foodalchemist.recipes.index') }}" class="relative overflow-hidden {{ $card }} px-4 py-3 hover:border-rose-500/40 transition-colors">
                    <span class="{{ $dt }}">@svg('heroicon-o-exclamation-triangle', 'w-3.5 h-3.5 inline-block align-middle') Allergen-Lücken</span>
                    <p class="text-2xl font-semibold {{ ($workflow['allergen_low'] ?? 0) > 0 ? 'text-rose-600' : 'text-gray-900' }}">{{ number_format($workflow['allergen_low'] ?? 0, 0, ',', '.') }}</p>
                    <p class="text-[10px] text-gray-500 mt-0.5">Konfidenz low/unknown · {{ $workflow['ungemappt'] ?? 0 }} mit ungemappten Zutaten</p>
                </a>
                <a href="{{ route('foodalchemist.verkauf.index') }}" class="relative overflow-hidden {{ $card }} px-4 py-3 hover:border-sky-500/40 transition-colors">
                    <span class="{{ $dt }}">@svg('heroicon-o-tag', 'w-3.5 h-3.5 inline-block align-middle') VK ohne Klasse</span>
                    <p class="text-2xl font-semibold {{ ($workflow['vk_ohne_klasse'] ?? 0) > 0 ? 'text-amber-600' : 'text-gray-900' }}">{{ $workflow['vk_ohne_klasse'] ?? 0 }}</p>
                    <p class="text-[10px] text-gray-500 mt-0.5">V-22-Gate: Klassifikation fehlt → @svg('heroicon-o-sparkles', 'w-3.5 h-3.5 inline-block align-middle') Klassifizieren</p>
                </a>
            </div>
        </div>

        {{-- KI + Schnellzugriff --}}
        <div class="grid md:grid-cols-2 gap-3" data-dashboard-unten>
            <div class="relative overflow-hidden {{ $card }} px-4 py-3" data-dashboard-ki>
                <div class="{{ $cardAccent }}"></div>
                <span class="{{ $dt }}">@svg('heroicon-o-sparkles', 'w-3.5 h-3.5 inline-block align-middle') KI-Nutzung (dieses Team)</span>
                <p class="text-2xl font-semibold text-gray-900">{{ number_format($ki['calls'], 0, ',', '.') }} <span class="text-xs font-normal text-gray-500">Calls</span></p>
                <p class="text-[10px] text-gray-500 mt-0.5">{{ number_format($ki['accepted'], 0, ',', '.') }} übernommene Vorschläge · Details + Kill-Switch in den <a href="{{ route('foodalchemist.einstellungen', ['sektion' => 'ki']) }}" class="text-violet-600 hover:underline">KI-Einstellungen</a></p>
            </div>
            <div class="relative overflow-hidden {{ $card }} px-4 py-3" data-dashboard-links>
                <span class="{{ $dt }}">Schnellzugriff</span>
                <div class="flex flex-wrap gap-1.5 mt-1.5">
                    <a href="{{ route('foodalchemist.recipes.index') }}" class="{{ $btnGhostXs }}">@svg('heroicon-o-book-open', 'w-3.5 h-3.5 inline-block align-middle') Basisrezepte</a>
                    <a href="{{ route('foodalchemist.verkauf.index') }}" class="{{ $btnGhostXs }}">@svg('heroicon-o-currency-euro', 'w-3.5 h-3.5 inline-block align-middle') Gerichte</a>
                    <a href="{{ route('foodalchemist.gps.index') }}" class="{{ $btnGhostXs }}">@svg('heroicon-o-cube', 'w-3.5 h-3.5 inline-block align-middle') Grundprodukte</a>
                    <a href="{{ route('foodalchemist.suppliers.index') }}" class="{{ $btnGhostXs }}">@svg('heroicon-o-truck', 'w-3.5 h-3.5 inline-block align-middle') Lieferanten</a>
                    <a href="{{ route('foodalchemist.einstellungen') }}" class="{{ $btnGhostXs }}">@svg('heroicon-o-adjustments-horizontal', 'w-3.5 h-3.5 inline-block align-middle')️ Einstellungen</a>
                </div>
                <p class="text-[10px] text-gray-500 mt-2">Tipp: In den Listen öffnet der Klick auf den NAMEN direkt den Editor — der Klick auf die Zeile das Detail-Panel rechts.</p>
            </div>
        </div>

        {{-- Spec 32: der R2.7-Portfolio-Benchmark ist ins Controlling-Zentrum gewandert (Tab „Lage").
             Er kostet einen Kennzahlen-Lauf je Peer-Team und gehört fachlich zur Wirtschaftlichkeit,
             nicht zur Bestandsübersicht. Hier bleibt nur der Verweis — ein zweiter Anzeige-Ort
             wäre ein zweiter Pflege-Ort. --}}
        <a href="{{ route('foodalchemist.controlling.index', ['tab' => 'lage']) }}" wire:navigate
           class="relative overflow-hidden block {{ $card }} px-4 py-3 hover:-translate-y-0.5 hover:shadow-md transition-all duration-150"
           data-dashboard-controlling>
            <div class="{{ $cardAccent }}"></div>
            <span class="{{ $dt }}">@svg('heroicon-o-presentation-chart-line', 'w-3.5 h-3.5 inline-block align-middle') Controlling</span>
            <p class="text-xs text-gray-600 mt-1">
                Wareneinsatz, Preise, Simulation, Portfolio-Benchmark und die Geld-Signale liegen
                im Controlling-Zentrum — jeweils mit den Hebeln daneben.
            </p>
        </a>

    </x-ui-page-container>
</x-ui-page>
