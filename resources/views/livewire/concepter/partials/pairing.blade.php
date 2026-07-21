{{-- Wiederverwendbares Pairing-Panel: erwartet $pairing (PairingService::panelRecipe / panelGp). Read-only. --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

@if(! $pairing)
    <p class="text-xs text-gray-500 py-4">Noch keine Pairing-Daten.</p>
@elseif(($pairing['type'] ?? null) === 'recipe')
    @php($score = (int) ($pairing['score'] ?? 0))
    @php($cov = (int) ($pairing['coverage_pct'] ?? 0))
    @php($scoreFarbe = $score >= 70 ? 'success' : ($score >= 45 ? 'warning' : 'danger'))

    @if($cov === 0 && count($pairing['anker']) === 0)
        <p class="text-xs text-gray-500 py-4">Noch keine Pairing-Daten (keine Anker auf den Zutaten).</p>
    @else
        <div class="relative overflow-hidden {{ $card }}">
            <div class="{{ $cardAccent }}"></div>
            <div class="px-5 py-4 space-y-3">
                <div class="flex items-center justify-between">
                    <h3 class="font-medium tracking-tight text-gray-900">Aroma-Kohäsion</h3>
                    <span class="{{ $pill }} {{ $variantPill[$scoreFarbe] }}">{{ $score }}/100</span>
                </div>
                <div class="flex items-center gap-2">
                    <div class="flex-1 h-2 rounded-full bg-black/[0.06] overflow-hidden">
                        <div class="h-full rounded-full {{ $score >= 70 ? 'bg-emerald-500' : ($score >= 45 ? 'bg-amber-400' : 'bg-rose-400') }}" style="width: {{ $score }}%"></div>
                    </div>
                </div>
                <p class="text-[11px] text-gray-500">{{ $pairing['rated_pairs'] }} von {{ $pairing['total_pairs'] }} Komponenten-Paaren bewertet ({{ $cov }} % Abdeckung). Fehlende Kante = unbekannt, kein Clash.</p>
                @if($pairing['weakest_pair'])
                    <p class="text-xs text-gray-700"><span class="text-gray-500">Schwächstes Paar:</span> {{ $pairing['weakest_pair']['a'] }} ↔ {{ $pairing['weakest_pair']['b'] }} <span class="text-gray-500">({{ $pairing['weakest_pair']['score'] }}/100)</span></p>
                @endif
                @if(count($pairing['orphans']))
                    <p class="text-[11px] text-amber-600">⚠ Ohne bewertete Verbindung: {{ implode(' · ', $pairing['orphans']) }}</p>
                @endif
            </div>
        </div>

        {{-- Kern-Anker / „Passt dazu" / „Macht den Teller eigen" / Kontrast / Molekular verwandt / Verwandte Basisrezepte
             sind komplett ins Geschmacks-Profil (sensorik-Partial, rechts vom Radar) hochgezogen — s. partials/pairing-empfehlungen. --}}
    @endif
@elseif(($pairing['type'] ?? null) === 'gp')
    @if(count($pairing['anker']) === 0)
        <p class="text-xs text-gray-500 py-4">Noch keine Pairing-Daten (kein Aroma-Anker auf diesem GP).</p>
    @else
        <div class="relative overflow-hidden {{ $card }}">
            <div class="{{ $cardAccent }}"></div>
            <div class="px-5 py-4 space-y-2">
                <h3 class="font-medium tracking-tight text-gray-900">Aroma-Anker</h3>
                <div class="flex flex-wrap gap-1">
                    @foreach($pairing['anker'] as $a)<span class="{{ $pill }} {{ $variantPill['secondary'] }}">{{ $a['display_de'] ?: $a['slug'] }}</span>@endforeach
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 gap-3 mt-3 items-start">
        @if(count($pairing['nachbarn']))
            <div class="relative overflow-hidden {{ $card }}">
                <div class="{{ $cardAccent }}"></div>
                <div class="px-5 py-4 space-y-2">
                    <h3 class="font-medium tracking-tight text-gray-900">Passt erprobt zu</h3>
                    <div class="flex flex-wrap gap-1">
                        @foreach($pairing['nachbarn'] as $n)<span class="{{ $pill }} {{ $variantPill['info'] }}">{{ $n }}</span>@endforeach
                    </div>
                </div>
            </div>
        @endif

        @if(count($pairing['aroma'] ?? []))
            <div class="relative overflow-hidden {{ $card }}">
                <div class="{{ $cardAccent }}"></div>
                <div class="px-5 py-4 space-y-2">
                    <h3 class="font-medium tracking-tight text-gray-900">Molekular verwandt (Aroma-Layer)</h3>
                    <p class="text-[11px] text-gray-500">Aromen mit gemeinsamem Aromamolekül (Foodpairing) — harmonieren über die Molekülebene.</p>
                    <div class="flex flex-wrap gap-1">
                        @foreach($pairing['aroma'] as $n)<span class="{{ $pill }} {{ $variantPill['primary'] }}">≈ {{ $n }}</span>@endforeach
                    </div>
                </div>
            </div>
        @endif

        @if(count($pairing['kontrast'] ?? []))
            <div class="relative overflow-hidden {{ $card }}">
                <div class="{{ $cardAccent }}"></div>
                <div class="px-5 py-4 space-y-2">
                    <h3 class="font-medium tracking-tight text-gray-900">Kontrast (Aroma-Gegenpol)</h3>
                    <div class="flex flex-wrap gap-1">
                        @foreach($pairing['kontrast'] as $n)<span class="{{ $pill }}" style="background-color: rgba(6,182,212,0.14); color: #0891b2;">↔ {{ $n }}</span>@endforeach
                    </div>
                </div>
            </div>
        @endif
        </div>
    @endif
@endif
