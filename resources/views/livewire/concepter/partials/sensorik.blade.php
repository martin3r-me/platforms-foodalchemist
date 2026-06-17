{{-- Wiederverwendbares Sensorik-Panel: erwartet $sensorik (SensorikService::fuer*). Genutzt in Concept-/Gericht-/Basisrezept-/GP-Editor. --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))
@php($dimLabel = ['suess' => 'Süß', 'salzig' => 'Salzig', 'sauer' => 'Sauer', 'bitter' => 'Bitter', 'umami' => 'Umami', 'fettig' => 'Fettig', 'scharf' => 'Scharf'])

@if(! $sensorik || ($sensorik['leer'] ?? true))
    <p class="text-xs text-gray-400 py-4">Noch keine Sensorik-Daten (keine Grundprodukte mit Vektor).</p>
@else
    <p class="text-[11px] text-gray-400 mb-2">Aggregiert über {{ $sensorik['abdeckung']['gesamt'] }} Grundprodukte ({{ $sensorik['abdeckung']['mit'] }} mit Sensorik-Daten) — MAX je Dimension. Quelle: Sensorik-Graph.</p>

    <div class="relative overflow-hidden {{ $card }} mb-3">
        <div class="{{ $cardAccent }}"></div>
        <div class="px-5 py-4 space-y-2">
            <h3 class="font-medium tracking-tight text-gray-900 dark:text-gray-100">Geschmacks-Balance</h3>
            @foreach($dimLabel as $d => $l)
                @php($v = (float) ($sensorik['geschmack'][$d] ?? 0))
                @php($istDom = in_array($d, $sensorik['dominant'], true))
                @php($istLueck = in_array($d, $sensorik['luecken'], true))
                <div class="flex items-center gap-2">
                    <span class="text-[11px] w-14 shrink-0 {{ $istLueck ? 'text-gray-400' : 'text-gray-700 dark:text-gray-200' }}">{{ $l }}</span>
                    <div class="flex-1 h-2 rounded-full bg-black/[0.06] dark:bg-white/10 overflow-hidden">
                        <div class="h-full rounded-full {{ $istDom ? 'bg-violet-500' : ($istLueck ? 'bg-gray-300 dark:bg-gray-600' : 'bg-violet-400/60') }}" style="width: {{ (int) round($v * 100) }}%"></div>
                    </div>
                    <span class="text-[11px] w-8 text-right tabular-nums text-gray-500">{{ number_format($v, 2, ',', '.') }}</span>
                </div>
            @endforeach
            @if(count($sensorik['dominant']) || count($sensorik['luecken']))
                <div class="flex flex-wrap gap-1 pt-1">
                    @foreach($sensorik['dominant'] as $d)<span class="{{ $pill }} {{ $variantPill['success'] }}">dominant: {{ $dimLabel[$d] ?? $d }}</span>@endforeach
                    @foreach($sensorik['luecken'] as $d)<span class="{{ $pill }} {{ $variantPill['warning'] }}">Lücke: {{ $dimLabel[$d] ?? $d }}</span>@endforeach
                </div>
            @endif
        </div>
    </div>

    <div class="relative overflow-hidden {{ $card }} mb-3">
        <div class="{{ $cardAccent }}"></div>
        <div class="px-5 py-4 space-y-2">
            <h3 class="font-medium tracking-tight text-gray-900 dark:text-gray-100">Textur-Profil</h3>
            @if(count($sensorik['textur']))
                <div class="flex flex-wrap gap-1">
                    @foreach($sensorik['textur'] as $t)<span class="{{ $pill }} {{ $variantPill['secondary'] }}">{{ $t['label'] }}</span>@endforeach
                </div>
            @else
                <p class="text-[11px] text-gray-400">Keine Textur-Daten.</p>
            @endif
            @if($sensorik['monotonie'])
                <p class="text-[11px] text-amber-600 dark:text-amber-400">⚠ {{ $sensorik['monotonie'] }}</p>
            @endif
        </div>
    </div>

    @if(count($sensorik['vorschlaege']))
        <div class="relative overflow-hidden {{ $card }}">
            <div class="{{ $cardAccent }}"></div>
            <div class="px-5 py-4 space-y-1">
                <h3 class="font-medium tracking-tight text-gray-900 dark:text-gray-100">Ausgleich-Vorschläge (Kontrast)</h3>
                @foreach($sensorik['vorschlaege'] as $vs)
                    <p class="text-xs text-gray-700 dark:text-gray-200"><span class="text-gray-400">{{ $dimLabel[$vs['dim']] ?? $vs['dim'] }} stärken:</span> {{ implode(' · ', $vs['gps']) }}</p>
                @endforeach
            </div>
        </div>
    @endif
@endif
