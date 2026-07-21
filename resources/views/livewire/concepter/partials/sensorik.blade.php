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

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-3 mb-3 items-start">
        <div class="relative overflow-hidden {{ $card }} lg:col-span-2">
            <div class="{{ $cardAccent }}"></div>
            <div class="px-5 py-4">
                <h3 class="font-medium tracking-tight text-gray-900">Geschmacks-Profil <span class="text-[11px] font-normal text-gray-400">· sensorisch</span></h3>
                {{-- #503: Fläche = gegarte Sensorik. Der Aroma-Anker-Wert (andere Quelle + Skala) liegt je Achse im Tooltip. --}}
                <div class="flex flex-col sm:flex-row items-center sm:items-start gap-4 mt-2">
                    <div class="shrink-0 w-full max-w-[260px]">
                        @include('foodalchemist::livewire.concepter.partials.geschmack-radar', [
                            'sensGeschmack' => $sensorik['geschmack'] ?? [],
                            'ankerGeschmack' => $pairing['geschmack'] ?? [],
                            'dominant' => $sensorik['dominant'] ?? [],
                            'luecken' => $sensorik['luecken'] ?? [],
                        ])
                    </div>
                    <div class="flex-1 min-w-0 space-y-2">
                        <p class="text-[11px] text-gray-500">Netz = gegarte Sensorik je Achse (0–1), Quelle s. Badge oben. Aroma-Anker-Wert je Achse im Tooltip (Hover).</p>
                        @if(count($sensorik['dominant']) || count($sensorik['luecken']))
                            <div class="flex flex-wrap gap-1">
                                @foreach($sensorik['dominant'] as $d)<span class="{{ $pill }} {{ $variantPill['success'] }}">dominant: {{ $dimLabel[$d] ?? $d }}</span>@endforeach
                                @foreach($sensorik['luecken'] as $d)<span class="{{ $pill }} {{ $variantPill['warning'] }}">Lücke: {{ $dimLabel[$d] ?? $d }}</span>@endforeach
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        <div class="relative overflow-hidden {{ $card }}">
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
    </div>
    {{-- Kein „Ausgleich/Kontrast"-Vorschlag hier: Grundgeschmack = reine Diagnose.
         Kontrast/Komplettierung liefert der Anker-Graph (Pairing-Block: klassisch + kontrast). --}}
@endif
