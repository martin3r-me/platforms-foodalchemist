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
                    ['🧺', 'Grundprodukte', number_format($kpis['gps'], 0, ',', '.'), route('foodalchemist.gps.index'), 'Katalog der Team-Kette (D1)'],
                    ['🔗', 'Lieferantenartikel', number_format($kpis['las'], 0, ',', '.'), route('foodalchemist.gps.index'), 'kuratierte LA-Strukturen'],
                    ['🚚', 'Lieferanten', number_format($kpis['lieferanten'], 0, ',', '.'), route('foodalchemist.suppliers.index'), null],
                    ['📖', 'Rezepte gesamt', number_format((int) $kpis['rezepte'], 0, ',', '.'), route('foodalchemist.recipes.index'), ($workflow['basis'] ?? 0) . ' Basis · ' . ($workflow['vk'] ?? 0) . ' VK'],
                ] as [$icon, $titel, $wert, $url, $hint])
                    <a href="{{ $url }}" class="relative overflow-hidden {{ $card }} px-4 py-3 hover:border-violet-500/40 transition-colors group" wire:key="kachel-{{ $titel }}">
                        <div class="{{ $cardAccent }}"></div>
                        <span class="{{ $dt }}">{{ $icon }} {{ $titel }}</span>
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
                    <span class="{{ $dt }}">📐 Templates</span>
                    <p class="text-2xl font-semibold text-gray-900">{{ $workflow['templates'] ?? 0 }}</p>
                    <p class="text-[10px] text-gray-500 mt-0.5">Vorlagen für «Aus Template»</p>
                </a>
                <a href="{{ route('foodalchemist.recipes.index') }}" class="relative overflow-hidden {{ $card }} px-4 py-3 hover:border-rose-500/40 transition-colors">
                    <span class="{{ $dt }}">⚠ Allergen-Lücken</span>
                    <p class="text-2xl font-semibold {{ ($workflow['allergen_low'] ?? 0) > 0 ? 'text-rose-600' : 'text-gray-900' }}">{{ number_format($workflow['allergen_low'] ?? 0, 0, ',', '.') }}</p>
                    <p class="text-[10px] text-gray-500 mt-0.5">Konfidenz low/unknown · {{ $workflow['ungemappt'] ?? 0 }} mit ungemappten Zutaten</p>
                </a>
                <a href="{{ route('foodalchemist.verkauf.index') }}" class="relative overflow-hidden {{ $card }} px-4 py-3 hover:border-sky-500/40 transition-colors">
                    <span class="{{ $dt }}">🏷 VK ohne Klasse</span>
                    <p class="text-2xl font-semibold {{ ($workflow['vk_ohne_klasse'] ?? 0) > 0 ? 'text-amber-600' : 'text-gray-900' }}">{{ $workflow['vk_ohne_klasse'] ?? 0 }}</p>
                    <p class="text-[10px] text-gray-500 mt-0.5">V-22-Gate: Klassifikation fehlt → ✨ Klassifizieren</p>
                </a>
            </div>
        </div>

        {{-- KI + Schnellzugriff --}}
        <div class="grid md:grid-cols-2 gap-3" data-dashboard-unten>
            <div class="relative overflow-hidden {{ $card }} px-4 py-3" data-dashboard-ki>
                <div class="{{ $cardAccent }}"></div>
                <span class="{{ $dt }}">✨ KI-Nutzung (dieses Team)</span>
                <p class="text-2xl font-semibold text-gray-900">{{ number_format($ki['calls'], 0, ',', '.') }} <span class="text-xs font-normal text-gray-500">Calls</span></p>
                <p class="text-[10px] text-gray-500 mt-0.5">{{ number_format($ki['accepted'], 0, ',', '.') }} übernommene Vorschläge · Details + Kill-Switch in den <a href="{{ route('foodalchemist.einstellungen', ['sektion' => 'ki']) }}" class="text-violet-600 hover:underline">KI-Einstellungen</a></p>
            </div>
            <div class="relative overflow-hidden {{ $card }} px-4 py-3" data-dashboard-links>
                <span class="{{ $dt }}">Schnellzugriff</span>
                <div class="flex flex-wrap gap-1.5 mt-1.5">
                    <a href="{{ route('foodalchemist.recipes.index') }}" class="{{ $btnGhostXs }}">📖 Basisrezepte</a>
                    <a href="{{ route('foodalchemist.verkauf.index') }}" class="{{ $btnGhostXs }}">💶 Gerichte</a>
                    <a href="{{ route('foodalchemist.gps.index') }}" class="{{ $btnGhostXs }}">🧺 Grundprodukte</a>
                    <a href="{{ route('foodalchemist.suppliers.index') }}" class="{{ $btnGhostXs }}">🚚 Lieferanten</a>
                    <a href="{{ route('foodalchemist.einstellungen') }}" class="{{ $btnGhostXs }}">⚙️ Einstellungen</a>
                </div>
                <p class="text-[10px] text-gray-500 mt-2">Tipp: In den Listen öffnet der Klick auf den NAMEN direkt den Editor — der Klick auf die Zeile das Detail-Panel rechts.</p>
            </div>
        </div>

        {{-- R2.7: Portfolio-Benchmark (BHG-intern) — eigen vs. anonymer Peer-Median der Team-Kette --}}
        @if($benchmark !== null)
            <div class="relative overflow-hidden {{ $card }} px-4 py-3" data-dashboard-benchmark>
                <div class="{{ $cardAccent }}"></div>
                <div class="flex items-baseline justify-between gap-2 flex-wrap">
                    <span class="{{ $dt }}">📊 Portfolio-Benchmark (intern)</span>
                    <span class="text-[10px] text-gray-500">
                        {{ $benchmark['n_peers'] > 0 ? 'vs. Median von ' . $benchmark['n_peers'] . ' anonymen Peer-Team(s)' : 'kein Peer-Team mit Portfolio — Vergleich erst ab 2 Teams' }}
                    </span>
                </div>
                <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-2 mt-3">
                    @foreach($benchmark['kennzahlen'] as $key => $meta)
                        @php($eigen = $benchmark['team_kpis'][$key])
                        @php($peer = $benchmark['peer_median'][$key])
                        @php($besserHoch = $meta['besser'] === 'hoch')
                        @php($vgl = ($eigen !== null && $peer !== null) ? ($besserHoch ? $eigen <=> $peer : $peer <=> $eigen) : 0)
                        <div class="rounded-lg bg-black/[0.03] px-3 py-2">
                            <div class="{{ $label }}">{{ $meta['label'] }}</div>
                            <div class="text-lg font-semibold tabular-nums {{ $vgl > 0 ? 'text-emerald-600' : ($vgl < 0 ? 'text-amber-600' : 'text-gray-900') }}">
                                {{ $eigen !== null ? number_format((float) $eigen, $meta['unit'] === '' ? 0 : 1, ',', '.') . $meta['unit'] : '—' }}
                            </div>
                            <div class="text-[10px] text-gray-500">Peer-Median: {{ $peer !== null ? number_format((float) $peer, $meta['unit'] === '' ? 0 : 1, ',', '.') . $meta['unit'] : '—' }}</div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

    </x-ui-page-container>
</x-ui-page>
