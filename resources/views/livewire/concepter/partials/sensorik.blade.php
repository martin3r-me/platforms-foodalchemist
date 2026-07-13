{{-- Wiederverwendbares Sensorik-Panel: erwartet $sensorik (SensorikService::fuer*). Genutzt in Concept-/Gericht-/Basisrezept-/GP-Editor. --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))
@php($dimLabel = ['suess' => 'Süß', 'salzig' => 'Salzig', 'sauer' => 'Sauer', 'bitter' => 'Bitter', 'umami' => 'Umami', 'fettig' => 'Fettig', 'scharf' => 'Scharf'])

@if(! $sensorik || ($sensorik['leer'] ?? true))
    <p class="text-xs text-gray-500 py-4">Noch keine Sensorik-Daten (keine Grundprodukte mit Vektor).</p>
@else
    @php($sQuelle = $sensorik['source'] ?? 'roh')
    <div class="flex items-center gap-2 mb-2 flex-wrap">
        @if($sQuelle === 'ki')
            <span class="{{ $pill }} {{ $variantPill['success'] }}">✨ KI-bewertet · gegart</span>
            @if(($sensorik['confidence'] ?? null) !== null)<span class="text-[11px] text-gray-500">Konfidenz {{ number_format((float) $sensorik['confidence'], 2, ',', '.') }}</span>@endif
        @elseif($sQuelle === 'manual')
            <span class="{{ $pill }} {{ $variantPill['info'] }}">✎ manuell gesetzt · gegart</span>
        @elseif($sQuelle === 'gp')
            <span class="{{ $pill }} {{ $variantPill['secondary'] }}">Grundprodukt · Roh-Profil</span>
        @else
            <span class="{{ $pill }} {{ $variantPill['warning'] }}">aus Rohzutaten geschätzt</span>
            @if(isset($sensorik['abdeckung']))<span class="text-[11px] text-gray-500">{{ $sensorik['abdeckung']['mit'] }}/{{ $sensorik['abdeckung']['gesamt'] }} GPs mit Daten · noch nicht KI-bewertet</span>@endif
        @endif
    </div>
    @if(($sensorik['reasoning'] ?? null) !== null && $sQuelle === 'ki')
        <p class="text-[11px] text-gray-500 mb-2 italic">{{ $sensorik['reasoning'] }}</p>
    @endif

    <div class="relative overflow-hidden {{ $card }} mb-3">
        <div class="{{ $cardAccent }}"></div>
        <div class="px-5 py-4 space-y-2">
            <h3 class="font-medium tracking-tight text-gray-900">Geschmacks-Balance</h3>
            @foreach($dimLabel as $d => $l)
                @php($v = (float) ($sensorik['geschmack'][$d] ?? 0))
                @php($istDom = in_array($d, $sensorik['dominant'], true))
                @php($istLueck = in_array($d, $sensorik['luecken'], true))
                {{-- Balkenfarbe als Inline-Style (NICHT als JIT-Klasse): bg-violet-400/60 wird
                     im statischen CSS-Build nicht emittiert → Balken sonst unsichtbar (Build-Falle). --}}
                @php($barColor = $istDom ? 'rgb(139 92 246)' : ($istLueck ? 'rgb(156 163 175)' : 'rgb(167 139 250 / 0.7)'))
                <div class="flex items-center gap-2">
                    <span class="text-[11px] w-14 shrink-0 {{ $istLueck ? 'text-gray-500' : 'text-gray-700' }}">{{ $l }}</span>
                    <div class="flex-1 h-2 rounded-full bg-black/[0.06] overflow-hidden">
                        <div class="h-full rounded-full" style="width: {{ (int) round($v * 100) }}%; background-color: {{ $barColor }};"></div>
                    </div>
                    <span class="text-[11px] w-8 text-right tabular-nums text-gray-600">{{ number_format($v, 2, ',', '.') }}</span>
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
            <h3 class="font-medium tracking-tight text-gray-900">Textur-Profil</h3>
            @if(count($sensorik['textur']))
                <div class="flex flex-wrap gap-1">
                    @foreach($sensorik['textur'] as $t)<span class="{{ $pill }} {{ $variantPill['secondary'] }}">{{ $t['label'] }}</span>@endforeach
                </div>
            @else
                <p class="text-[11px] text-gray-500">Keine Textur-Daten.</p>
            @endif
            @if($sensorik['monotonie'])
                <p class="text-[11px] text-amber-600">⚠ {{ $sensorik['monotonie'] }}</p>
            @endif
        </div>
    </div>
    {{-- Kein „Ausgleich/Kontrast"-Vorschlag hier: Grundgeschmack = reine Diagnose.
         Kontrast/Komplettierung liefert der Anker-Graph (Pairing-Block: klassisch + kontrast). --}}
@endif
