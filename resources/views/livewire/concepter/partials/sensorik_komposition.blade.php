{{-- Gericht-Sensorik als KOMPOSITION (B): Rollen-Check + Teller-Profil-Radar (MAX über Komponenten) + Komponenten-Aufschlüsselung.
     Gleiches Radar+Layout wie Basisrezept (sensorik.blade.php). Erwartet $komposition (SensorikService::gerichtKomposition)
     + $sensorik (für Textur) + $pairing (für Anker-Tooltip + Pairing-Empfehlungen). --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))
@php($dimLabel = ['suess' => 'Süß', 'salzig' => 'Salzig', 'sauer' => 'Sauer', 'bitter' => 'Bitter', 'umami' => 'Umami', 'fettig' => 'Fettig', 'scharf' => 'Scharf'])

@php($rc = $komposition['rollencheck'] ?? null)
@if($rc)
    <div class="flex items-center gap-2 mb-2">
        @php($rcVar = ['ok' => 'success', 'warn' => 'warning', 'info' => 'secondary'][$rc['status']] ?? 'secondary')
        <span class="{{ $pill }} {{ $variantPill[$rcVar] }}">Rolle: {{ $rc['role'] }}</span>
        <span class="text-[11px] {{ $rc['status'] === 'warn' ? 'text-amber-600' : 'text-gray-500' }}">{{ $rc['detail'] }}</span>
    </div>
@endif

<div class="relative overflow-hidden {{ $card }} mb-3">
    <div class="{{ $cardAccent }}"></div>
    <div class="px-5 py-4">
        <h3 class="font-medium tracking-tight text-gray-900">Geschmacks-Profil <span class="text-[11px] font-normal text-gray-400">· Teller</span></h3>
        {{-- Fläche = MAX-Aggregation über die Komponenten. Aroma-Anker-Wert je Achse im Tooltip. Gleiches Layout wie Basisrezept (sensorik.blade.php). --}}
        <div class="flex flex-col lg:flex-row gap-6 mt-3">
            <div class="shrink-0 mx-auto lg:mx-0 w-full max-w-[400px]">
                <div class="rounded-xl border border-black/[0.06] bg-black/[0.015] p-3">
                    @include('foodalchemist::livewire.concepter.partials.geschmack-radar', [
                        'sensGeschmack' => $komposition['teller'] ?? [],
                        'ankerGeschmack' => $pairing['geschmack'] ?? [],
                        'dominant' => $komposition['dominant'] ?? [],
                        'luecken' => $komposition['luecken'] ?? [],
                    ])
                </div>
            </div>
            <div class="flex-1 min-w-0 grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-4 content-start">
                <div class="space-y-3">
                    <p class="text-[11px] text-gray-500">Netz = Teller-Profil, MAX über die Komponenten (0–1) — „ist der Geschmack auf dem Teller da?". Aroma-Anker-Wert je Achse im Tooltip (Hover).</p>
                    @if(count($komposition['dominant']) || count($komposition['luecken']))
                        <div class="flex flex-wrap gap-1">
                            @foreach($komposition['dominant'] as $d)<span class="{{ $pill }} {{ $variantPill['success'] }}">dominant: {{ $dimLabel[$d] ?? $d }}</span>@endforeach
                            @foreach($komposition['luecken'] as $d)<span class="{{ $pill }} {{ $variantPill['warning'] }}">Lücke: {{ $dimLabel[$d] ?? $d }}</span>@endforeach
                        </div>
                    @endif
                    @if(! ($sensorik['leer'] ?? true) && count($sensorik['textur'] ?? []))
                        <div class="pt-3 border-t border-black/[0.06]">
                            <h4 class="text-[11px] font-medium text-gray-600 mb-1.5">Textur-Profil</h4>
                            <div class="flex flex-wrap gap-1">
                                @foreach($sensorik['textur'] as $t)<span class="{{ $pill }} {{ $variantPill['secondary'] }}">{{ $t['label'] }}</span>@endforeach
                            </div>
                            @if($sensorik['monotonie'] ?? null)<p class="text-[11px] text-amber-600 mt-1.5">⚠ {{ $sensorik['monotonie'] }}</p>@endif
                        </div>
                    @endif
                </div>
                @include('foodalchemist::livewire.concepter.partials.pairing-empfehlungen', ['pairing' => $pairing ?? null])
            </div>
        </div>
    </div>
</div>

<div class="relative overflow-hidden {{ $card }} mb-3">
    <div class="{{ $cardAccent }}"></div>
    <div class="px-5 py-4 space-y-1.5">
        <h3 class="font-medium tracking-tight text-gray-900">Komponenten ({{ count($komposition['komponenten']) }})</h3>
        <p class="text-[11px] text-gray-500">Jede Komponente trägt ihr eigenes Profil — die Spannung des Tellers, nicht ein Mittelwert.</p>
        @foreach($komposition['komponenten'] as $c)
            <div class="flex items-center gap-2 text-xs">
                <span class="flex-1 min-w-0 truncate text-gray-700">{{ $c['name'] }}</span>
                @if($c['source'] === 'ki')<span class="{{ $pill }} {{ $variantPill['success'] }}">gegart</span>@elseif($c['source'] === 'gp')<span class="{{ $pill }} {{ $variantPill['secondary'] }}">roh</span>@endif
                <span class="flex flex-wrap gap-1 shrink-0">
                    @forelse($c['dominant'] as $d)<span class="{{ $pill }} {{ $variantPill['secondary'] }}">{{ $dimLabel[$d] ?? $d }}</span>@empty<span class="text-[11px] text-gray-500">mild</span>@endforelse
                </span>
            </div>
        @endforeach
    </div>
</div>
