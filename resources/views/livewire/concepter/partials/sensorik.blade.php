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
        <div class="px-5 py-4">
            <h3 class="font-medium tracking-tight text-gray-900">Geschmacks-Profil <span class="text-[11px] font-normal text-gray-400">· sensorisch</span></h3>
            {{-- #503: Fläche = gegarte Sensorik, Aroma-Anker-Wert je Achse im Tooltip.
                 Rechts: Geschmack-Kontext + logische Pairing-Empfehlungen (aus $pairing, recipe-Typ — aus dem Pairing-Block hochgezogen). --}}
            <div class="flex flex-col lg:flex-row gap-6 mt-3">
                {{-- Radar in eigenem Container --}}
                <div class="shrink-0 mx-auto lg:mx-0 w-full max-w-[400px]">
                    <div class="rounded-xl border border-black/[0.06] bg-black/[0.015] p-3">
                        @include('foodalchemist::livewire.concepter.partials.geschmack-radar', [
                            'sensGeschmack' => $sensorik['geschmack'] ?? [],
                            'ankerGeschmack' => $pairing['geschmack'] ?? [],
                            'dominant' => $sensorik['dominant'] ?? [],
                            'luecken' => $sensorik['luecken'] ?? [],
                        ])
                    </div>
                </div>
                {{-- Rechts: Geschmack-Kontext (Chips + Textur) | Pairing-Empfehlungen --}}
                <div class="flex-1 min-w-0 grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-4 content-start">
                    <div class="space-y-3">
                        <p class="text-[11px] text-gray-500">Netz = gegarte Sensorik je Achse (0–1), Quelle s. Badge oben. Aroma-Anker-Wert je Achse im Tooltip (Hover).</p>
                        @if(count($sensorik['dominant']) || count($sensorik['luecken']))
                            <div class="flex flex-wrap gap-1">
                                @foreach($sensorik['dominant'] as $d)<span class="{{ $pill }} {{ $variantPill['success'] }}">dominant: {{ $dimLabel[$d] ?? $d }}</span>@endforeach
                                @foreach($sensorik['luecken'] as $d)<span class="{{ $pill }} {{ $variantPill['warning'] }}">Lücke: {{ $dimLabel[$d] ?? $d }}</span>@endforeach
                            </div>
                        @endif
                        <div class="pt-3 border-t border-black/[0.06]">
                            <h4 class="text-[11px] font-medium text-gray-600 mb-1.5">Textur-Profil</h4>
                            @if(count($sensorik['textur']))
                                <div class="flex flex-wrap gap-1">
                                    @foreach($sensorik['textur'] as $t)<span class="{{ $pill }} {{ $variantPill['secondary'] }}">{{ $t['label'] }}</span>@endforeach
                                </div>
                            @else
                                <p class="text-[11px] text-gray-500">Keine Textur-Daten.</p>
                            @endif
                            @if($sensorik['monotonie'])
                                <p class="text-[11px] text-amber-600 mt-1.5">⚠ {{ $sensorik['monotonie'] }}</p>
                            @endif
                        </div>
                    </div>
                    @include('foodalchemist::livewire.concepter.partials.pairing-empfehlungen', ['pairing' => $pairing ?? null])
                </div>
            </div>
        </div>
    </div>
    {{-- Kein „Ausgleich/Kontrast"-Vorschlag hier: Grundgeschmack = reine Diagnose.
         Kontrast/Komplettierung liefert der Anker-Graph (Pairing-Block: klassisch + kontrast). --}}
@endif
