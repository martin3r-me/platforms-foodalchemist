{{-- Wiederverwendbares Pairing-Panel: erwartet $pairing (PairingService::panelRecipe / panelGp). Read-only. --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

@if(! $pairing)
    <p class="text-xs text-gray-500 dark:text-gray-400 py-4">Noch keine Pairing-Daten.</p>
@elseif(($pairing['type'] ?? null) === 'recipe')
    @php($score = (int) ($pairing['score'] ?? 0))
    @php($cov = (int) ($pairing['coverage_pct'] ?? 0))
    @php($scoreFarbe = $score >= 70 ? 'success' : ($score >= 45 ? 'warning' : 'danger'))

    @if($cov === 0 && count($pairing['anker']) === 0)
        <p class="text-xs text-gray-500 dark:text-gray-400 py-4">Noch keine Pairing-Daten (keine Anker auf den Zutaten).</p>
    @else
        <div class="relative overflow-hidden {{ $card }} mb-3">
            <div class="{{ $cardAccent }}"></div>
            <div class="px-5 py-4 space-y-3">
                <div class="flex items-center justify-between">
                    <h3 class="font-medium tracking-tight text-gray-900 dark:text-gray-100">Aroma-Kohäsion</h3>
                    <span class="{{ $pill }} {{ $variantPill[$scoreFarbe] }}">{{ $score }}/100</span>
                </div>
                <div class="flex items-center gap-2">
                    <div class="flex-1 h-2 rounded-full bg-black/[0.06] dark:bg-white/10 overflow-hidden">
                        <div class="h-full rounded-full {{ $score >= 70 ? 'bg-emerald-500' : ($score >= 45 ? 'bg-amber-400' : 'bg-rose-400') }}" style="width: {{ $score }}%"></div>
                    </div>
                </div>
                <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ $pairing['rated_pairs'] }} von {{ $pairing['total_pairs'] }} Komponenten-Paaren bewertet ({{ $cov }} % Abdeckung). Fehlende Kante = unbekannt, kein Clash.</p>
                @if($pairing['weakest_pair'])
                    <p class="text-xs text-gray-700 dark:text-gray-200"><span class="text-gray-500 dark:text-gray-400">Schwächstes Paar:</span> {{ $pairing['weakest_pair']['a'] }} ↔ {{ $pairing['weakest_pair']['b'] }} <span class="text-gray-500 dark:text-gray-400">({{ $pairing['weakest_pair']['score'] }}/100)</span></p>
                @endif
                @if(count($pairing['orphans']))
                    <p class="text-[11px] text-amber-600 dark:text-amber-400">⚠ Ohne bewertete Verbindung: {{ implode(' · ', $pairing['orphans']) }}</p>
                @endif
            </div>
        </div>

        @if(count($pairing['anker']))
            <div class="relative overflow-hidden {{ $card }} mb-3">
                <div class="{{ $cardAccent }}"></div>
                <div class="px-5 py-4 space-y-2">
                    <h3 class="font-medium tracking-tight text-gray-900 dark:text-gray-100">Kern-Anker</h3>
                    <div class="flex flex-wrap gap-1">
                        @foreach($pairing['anker'] as $a)<span class="{{ $pill }} {{ $variantPill['secondary'] }}">{{ $a['display_de'] ?: $a['slug'] }}</span>@endforeach
                    </div>
                </div>
            </div>
        @endif

        @if(count($pairing['vorschlaege']))
            <div class="relative overflow-hidden {{ $card }}">
                <div class="{{ $cardAccent }}"></div>
                <div class="px-5 py-4 space-y-2">
                    <h3 class="font-medium tracking-tight text-gray-900 dark:text-gray-100">Komplettiert den Teller</h3>
                    <p class="text-[11px] text-gray-500 dark:text-gray-400">Aromen, die zu mehreren Komponenten erprobt passen — Vorschlag, keine Pflicht.</p>
                    <div class="flex flex-wrap gap-1">
                        @foreach($pairing['vorschlaege'] as $v)
                            <span class="{{ $pill }} {{ $v['allrounder'] ? $variantPill['secondary'] : $variantPill['info'] }}" title="passt zu {{ $v['cover'] }}/{{ $v['dish_n'] }} Komponenten{{ $v['allrounder'] ? ' · Allrounder (passt zu fast allem)' : '' }}">{{ $v['slug'] }} <span class="opacity-60">{{ $v['cover'] }}/{{ $v['dish_n'] }}</span></span>
                        @endforeach
                    </div>
                </div>
            </div>
        @endif

        @if(count($pairing['signature'] ?? []))
            <div class="relative overflow-hidden {{ $card }} mt-3">
                <div class="{{ $cardAccent }}"></div>
                <div class="px-5 py-4 space-y-2">
                    <h3 class="font-medium tracking-tight text-gray-900 dark:text-gray-100">Macht den Teller eigen</h3>
                    <p class="text-[11px] text-gray-500 dark:text-gray-400">Aromen, die spezifisch zu DIESEM Teller passen — Allrounder (passt-zu-allem) sind rausgerechnet. Schärft das Profil statt es zu glätten.</p>
                    <div class="flex flex-wrap gap-1">
                        @foreach($pairing['signature'] as $v)
                            <span class="{{ $pill }} {{ $variantPill['info'] }}" title="passt zu {{ $v['cover'] }}/{{ $v['dish_n'] }} Komponenten, aber kein Allrounder">{{ $v['slug'] }} <span class="opacity-60">{{ $v['cover'] }}/{{ $v['dish_n'] }}</span></span>
                        @endforeach
                    </div>
                </div>
            </div>
        @endif

        {{-- Basisrezept (Komponente): Graph-Sicht statt Teller-Logik. --}}
        @if(count($pairing['nachbarn'] ?? []))
            <div class="relative overflow-hidden {{ $card }} mt-3">
                <div class="{{ $cardAccent }}"></div>
                <div class="px-5 py-4 space-y-2">
                    <h3 class="font-medium tracking-tight text-gray-900 dark:text-gray-100">Passt erprobt zu</h3>
                    <p class="text-[11px] text-gray-500 dark:text-gray-400">Aroma-Nachbarn dieser Komponente im Pairing-Graphen — womit sie erprobt harmoniert.</p>
                    <div class="flex flex-wrap gap-1">
                        @foreach($pairing['nachbarn'] as $n)<span class="{{ $pill }} {{ $variantPill['info'] }}">{{ $n }}</span>@endforeach
                    </div>
                </div>
            </div>
        @endif

        @if(count($pairing['verwandte'] ?? []))
            <div class="relative overflow-hidden {{ $card }} mt-3">
                <div class="{{ $cardAccent }}"></div>
                <div class="px-5 py-4 space-y-2">
                    <h3 class="font-medium tracking-tight text-gray-900 dark:text-gray-100">Verwandte Basisrezepte</h3>
                    <p class="text-[11px] text-gray-500 dark:text-gray-400">Andere Basisrezepte, die Pairing-Anker mit diesem teilen — mehr geteilte Anker = näher verwandt.</p>
                    <div class="flex flex-wrap gap-1">
                        @foreach($pairing['verwandte'] as $r)
                            <span class="{{ $pill }} {{ $variantPill['secondary'] }}" title="{{ $r['shared'] }} geteilte Anker{{ count($r['shared_slugs'] ?? []) ? ': ' . implode(', ', $r['shared_slugs']) : '' }}">{{ $r['name'] }} <span class="opacity-60">{{ $r['shared'] }}</span></span>
                        @endforeach
                    </div>
                </div>
            </div>
        @endif

        @if(count($pairing['aroma'] ?? []))
            <div class="relative overflow-hidden {{ $card }} mt-3">
                <div class="{{ $cardAccent }}"></div>
                <div class="px-5 py-4 space-y-2">
                    <h3 class="font-medium tracking-tight text-gray-900 dark:text-gray-100">Molekular verwandt (Aroma-Layer)</h3>
                    <p class="text-[11px] text-gray-500 dark:text-gray-400">Aromen mit gemeinsamem Aromamolekül (Foodpairing) — harmonieren über die Molekülebene, knapp unter der kulinarischen Klassik.</p>
                    <div class="flex flex-wrap gap-1">
                        @foreach($pairing['aroma'] as $n)<span class="{{ $pill }} {{ $variantPill['primary'] }}">≈ {{ $n }}</span>@endforeach
                    </div>
                </div>
            </div>
        @endif

        @if(count($pairing['kontrast'] ?? []))
            <div class="relative overflow-hidden {{ $card }} mt-3">
                <div class="{{ $cardAccent }}"></div>
                <div class="px-5 py-4 space-y-2">
                    <h3 class="font-medium tracking-tight text-gray-900 dark:text-gray-100">Kontrast (Aroma-Gegenpol)</h3>
                    <p class="text-[11px] text-gray-500 dark:text-gray-400">Kuratierte Kontrast-Kanten aus dem Aroma-Netz (↔) — der Gegenpol, der den Teller spannend macht.</p>
                    <div class="flex flex-wrap gap-1">
                        @foreach($pairing['kontrast'] as $n)<span class="{{ $pill }}" style="background-color: rgba(6,182,212,0.14); color: #0891b2;">↔ {{ $n }}</span>@endforeach
                    </div>
                </div>
            </div>
        @endif

        @include('foodalchemist::livewire.concepter.partials.geschmack', ['geschmack' => $pairing['geschmack'] ?? []])
    @endif
@elseif(($pairing['type'] ?? null) === 'gp')
    @if(count($pairing['anker']) === 0)
        <p class="text-xs text-gray-500 dark:text-gray-400 py-4">Noch keine Pairing-Daten (kein Aroma-Anker auf diesem GP).</p>
    @else
        <div class="relative overflow-hidden {{ $card }} mb-3">
            <div class="{{ $cardAccent }}"></div>
            <div class="px-5 py-4 space-y-2">
                <h3 class="font-medium tracking-tight text-gray-900 dark:text-gray-100">Aroma-Anker</h3>
                <div class="flex flex-wrap gap-1">
                    @foreach($pairing['anker'] as $a)<span class="{{ $pill }} {{ $variantPill['secondary'] }}">{{ $a['display_de'] ?: $a['slug'] }}</span>@endforeach
                </div>
            </div>
        </div>

        @if(count($pairing['nachbarn']))
            <div class="relative overflow-hidden {{ $card }}">
                <div class="{{ $cardAccent }}"></div>
                <div class="px-5 py-4 space-y-2">
                    <h3 class="font-medium tracking-tight text-gray-900 dark:text-gray-100">Passt erprobt zu</h3>
                    <div class="flex flex-wrap gap-1">
                        @foreach($pairing['nachbarn'] as $n)<span class="{{ $pill }} {{ $variantPill['info'] }}">{{ $n }}</span>@endforeach
                    </div>
                </div>
            </div>
        @endif

        @if(count($pairing['aroma'] ?? []))
            <div class="relative overflow-hidden {{ $card }} mt-3">
                <div class="{{ $cardAccent }}"></div>
                <div class="px-5 py-4 space-y-2">
                    <h3 class="font-medium tracking-tight text-gray-900 dark:text-gray-100">Molekular verwandt (Aroma-Layer)</h3>
                    <p class="text-[11px] text-gray-500 dark:text-gray-400">Aromen mit gemeinsamem Aromamolekül (Foodpairing) — harmonieren über die Molekülebene.</p>
                    <div class="flex flex-wrap gap-1">
                        @foreach($pairing['aroma'] as $n)<span class="{{ $pill }} {{ $variantPill['primary'] }}">≈ {{ $n }}</span>@endforeach
                    </div>
                </div>
            </div>
        @endif

        @if(count($pairing['kontrast'] ?? []))
            <div class="relative overflow-hidden {{ $card }} mt-3">
                <div class="{{ $cardAccent }}"></div>
                <div class="px-5 py-4 space-y-2">
                    <h3 class="font-medium tracking-tight text-gray-900 dark:text-gray-100">Kontrast (Aroma-Gegenpol)</h3>
                    <div class="flex flex-wrap gap-1">
                        @foreach($pairing['kontrast'] as $n)<span class="{{ $pill }}" style="background-color: rgba(6,182,212,0.14); color: #0891b2;">↔ {{ $n }}</span>@endforeach
                    </div>
                </div>
            </div>
        @endif

        @include('foodalchemist::livewire.concepter.partials.geschmack', ['geschmack' => $pairing['geschmack'] ?? []])
    @endif
@endif
