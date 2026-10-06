{{-- Gericht-Sensorik als KOMPOSITION (B): Rollen-Check + Teller-Profil-Radar (MAX über Komponenten) + Komponenten-Aufschlüsselung.
     Gleiches Radar + Layout wie Basisrezept (sensorik.blade.php). Erwartet $komposition (SensorikService::gerichtKomposition)
     + $sensorik (für Textur) + $pairing (für Anker-Tooltip + Pairing-Empfehlungen).
     fa-pass: nur Tokens + x-fa-Bausteine. Ui::maps() bleibt, weil pairing-empfehlungen ($pill/$variantPill) aus diesem Scope liest. --}}
@php
    extract(\Platform\FoodAlchemist\Support\Ui::maps());
    $dimLabel = ['suess' => 'süß', 'salzig' => 'salzig', 'sauer' => 'sauer', 'bitter' => 'bitter', 'umami' => 'umami', 'fettig' => 'fettig', 'scharf' => 'scharf'];
    $rc = $komposition['rollencheck'] ?? null;
@endphp

<div class="flex flex-col gap-3" data-sensorik-komposition>
    @if($rc)
        @php $rcTon = ['ok' => 'ok', 'warn' => 'warn', 'info' => 'neutral'][$rc['status']] ?? 'neutral'; @endphp
        <div class="flex flex-wrap items-center gap-2">
            <x-fa::badge :tone="$rcTon">Rolle: {{ $rc['role'] }}</x-fa::badge>
            <span class="text-[length:var(--fa-text-sm)] {{ $rc['status'] === 'warn' ? 'text-[var(--fa-warn)]' : 'text-[var(--fa-ink-3)]' }}">{{ $rc['detail'] }}</span>
        </div>
    @endif

    <x-fa::section title="Geschmacksprofil" icon="heroicon-o-beaker" meta="Teller">
        {{-- Fläche = MAX-Aggregation über die Komponenten. Aroma-Anker-Wert je Achse im Tooltip. --}}
        <div class="flex flex-col xl:flex-row gap-6">
            <div class="shrink-0 mx-auto xl:mx-0 w-full max-w-[360px]">
                <div class="rounded-[var(--fa-radius-surface)] border border-[var(--fa-line)] bg-[var(--fa-ground)] p-3">
                    @include('foodalchemist::livewire.concepter.partials.geschmack-radar', [
                        'sensGeschmack' => $komposition['teller'] ?? [],
                        'ankerGeschmack' => $pairing['geschmack'] ?? [],
                        'dominant' => $komposition['dominant'] ?? [],
                        'luecken' => $komposition['luecken'] ?? [],
                    ])
                </div>
            </div>
            <div class="flex-1 min-w-0 grid grid-cols-1 lg:grid-cols-2 gap-x-6 gap-y-4 content-start">
                <div class="flex flex-col gap-3">
                    <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Die Fläche zeigt das Profil des ganzen Tellers, je Achse der stärkste Wert einer Komponente (0 bis 1). Sie beantwortet: ist der Geschmack auf dem Teller da? Zeigen auf eine Achse nennt den Wert der Aroma-Anker.</p>
                    @if(count($komposition['dominant']) || count($komposition['luecken']))
                        <div class="flex flex-wrap gap-1.5">
                            @foreach($komposition['dominant'] as $d)<x-fa::badge tone="ok">dominant: {{ $dimLabel[$d] ?? $d }}</x-fa::badge>@endforeach
                            @foreach($komposition['luecken'] as $d)<x-fa::badge tone="warn">Lücke: {{ $dimLabel[$d] ?? $d }}</x-fa::badge>@endforeach
                        </div>
                    @endif
                    @if(! ($sensorik['leer'] ?? true) && count($sensorik['textur'] ?? []))
                        <div class="flex flex-col gap-1.5 pt-3 border-t border-[var(--fa-line)]">
                            <h4 class="text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]">Textur</h4>
                            <div class="flex flex-wrap gap-1.5">
                                @foreach($sensorik['textur'] as $t)<x-fa::badge>{{ $t['label'] }}</x-fa::badge>@endforeach
                            </div>
                            @if($sensorik['monotonie'] ?? null)<x-fa::signal tone="warn">{{ $sensorik['monotonie'] }}</x-fa::signal>@endif
                        </div>
                    @endif
                </div>
                @include('foodalchemist::livewire.concepter.partials.pairing-empfehlungen', ['pairing' => $pairing ?? null])
            </div>
        </div>
    </x-fa::section>

    <x-fa::section title="Komponenten" icon="heroicon-o-square-3-stack-3d" description="Jede Komponente trägt ihr eigenes Profil. Das ist die Spannung des Tellers, kein Mittelwert.">
        <div class="flex flex-col divide-y divide-[var(--fa-line)]">
            @forelse($komposition['komponenten'] as $c)
                <div class="flex flex-wrap items-center gap-2 py-1.5 text-[length:var(--fa-text-md)]">
                    <span class="flex-1 min-w-[10rem] break-words text-[var(--fa-ink)]">{{ $c['name'] }}</span>
                    @if($c['source'] === 'ki')<x-fa::badge tone="ok">gegart</x-fa::badge>@elseif($c['source'] === 'gp')<x-fa::badge>roh</x-fa::badge>@endif
                    <span class="flex flex-wrap gap-1 shrink-0">
                        @forelse($c['dominant'] as $d)<x-fa::badge>{{ $dimLabel[$d] ?? $d }}</x-fa::badge>@empty<span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">mild</span>@endforelse
                    </span>
                </div>
            @empty
                <p class="py-2 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Keine Komponenten mit Profil.</p>
            @endforelse
        </div>
    </x-fa::section>
</div>
