{{-- Gericht-Sensorik als KOMPOSITION (B): Rollen-Check + Teller-Balance (MAX über Komponenten) + Komponenten-Aufschlüsselung.
     Erwartet $komposition (SensorikService::gerichtKomposition) + $sensorik (für Textur). --}}
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
    <div class="px-5 py-4 space-y-2">
        <div class="flex items-center justify-between">
            <h3 class="font-medium tracking-tight text-gray-900">Teller-Profil</h3>
            <span class="text-[11px] text-gray-500">MAX über die Komponenten — „ist der Geschmack auf dem Teller da?"</span>
        </div>
        @foreach($dimLabel as $d => $l)
            @php($v = (float) ($komposition['teller'][$d] ?? 0))
            @php($istDom = in_array($d, $komposition['dominant'], true))
            @php($istLueck = in_array($d, $komposition['luecken'], true))
            <div class="flex items-center gap-2">
                <span class="text-[11px] w-14 shrink-0 {{ $istLueck ? 'text-gray-500' : 'text-gray-700' }}">{{ $l }}</span>
                <div class="flex-1 h-2 rounded-full bg-black/[0.06] overflow-hidden">
                    <div class="h-full rounded-full {{ $istDom ? 'bg-violet-500' : ($istLueck ? 'bg-gray-300' : 'bg-violet-400/60') }}" style="width: {{ (int) round($v * 100) }}%"></div>
                </div>
                <span class="text-[11px] w-8 text-right tabular-nums text-gray-600">{{ number_format($v, 2, ',', '.') }}</span>
            </div>
        @endforeach
        @if(count($komposition['dominant']) || count($komposition['luecken']))
            <div class="flex flex-wrap gap-1 pt-1">
                @foreach($komposition['dominant'] as $d)<span class="{{ $pill }} {{ $variantPill['success'] }}">dominant: {{ $dimLabel[$d] ?? $d }}</span>@endforeach
                @foreach($komposition['luecken'] as $d)<span class="{{ $pill }} {{ $variantPill['warning'] }}">Lücke: {{ $dimLabel[$d] ?? $d }}</span>@endforeach
            </div>
        @endif
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

@if(! ($sensorik['leer'] ?? true) && count($sensorik['textur'] ?? []))
    <div class="relative overflow-hidden {{ $card }} mb-3">
        <div class="{{ $cardAccent }}"></div>
        <div class="px-5 py-4 space-y-2">
            <h3 class="font-medium tracking-tight text-gray-900">Textur-Profil</h3>
            <div class="flex flex-wrap gap-1">
                @foreach($sensorik['textur'] as $t)<span class="{{ $pill }} {{ $variantPill['secondary'] }}">{{ $t['label'] }}</span>@endforeach
            </div>
            @if($sensorik['monotonie'] ?? null)<p class="text-[11px] text-amber-600">⚠ {{ $sensorik['monotonie'] }}</p>@endif
        </div>
    </div>
@endif

{{-- Pairing-Empfehlungen (Kern-Anker / Passt dazu / Kontrast). Im Gericht-View gibt es kein Radar-Partial
     (dort sitzen sie rechts vom Radar) — daher hier als eigene Karte, damit sie nicht verschwinden. --}}
@php($prE = ($pairing['type'] ?? null) === 'recipe' ? ($pairing ?? []) : [])
@if(count($prE['anker'] ?? []) || count($prE['vorschlaege'] ?? []) || count($prE['signature'] ?? []) || count($prE['nachbarn'] ?? []) || count($prE['kontrast'] ?? []))
    <div class="relative overflow-hidden {{ $card }} mb-3">
        <div class="{{ $cardAccent }}"></div>
        <div class="px-5 py-4 space-y-2">
            <h3 class="font-medium tracking-tight text-gray-900">Passt dazu <span class="text-[11px] font-normal text-gray-400">· Pairing</span></h3>
            @include('foodalchemist::livewire.concepter.partials.pairing-empfehlungen', ['pairing' => $pairing ?? null])
        </div>
    </div>
@endif
