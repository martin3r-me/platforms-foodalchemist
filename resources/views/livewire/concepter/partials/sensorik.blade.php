{{-- Wiederverwendbares Sensorik-Panel: erwartet $sensorik (SensorikService::fuer*). Genutzt in Gericht-, Basisrezept- und GP-Editor.
     fa-pass: nur Tokens + x-fa-Bausteine. Ui::maps() bleibt, weil pairing-empfehlungen ($pill/$variantPill) aus diesem Scope liest. --}}
@php
    extract(\Platform\FoodAlchemist\Support\Ui::maps());
    $dimLabel = ['suess' => 'süß', 'salzig' => 'salzig', 'sauer' => 'sauer', 'bitter' => 'bitter', 'umami' => 'umami', 'fettig' => 'fettig', 'scharf' => 'scharf'];
@endphp

@if(! $sensorik || ($sensorik['leer'] ?? true))
    <x-fa::empty compact icon="heroicon-o-beaker" title="Noch keine Sensorik">Keines der Grundprodukte hat ein Geschmacksprofil.</x-fa::empty>
@else
    @php $sQuelle = $sensorik['source'] ?? 'roh'; @endphp
    <div class="flex flex-col gap-3" data-sensorik-panel>
        <div class="flex flex-wrap items-center gap-2">
            @if($sQuelle === 'ki')
                <x-fa::badge tone="ok" icon="heroicon-m-sparkles">Von der KI bewertet, gegart</x-fa::badge>
                @if(($sensorik['confidence'] ?? null) !== null)<span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] tabular-nums">Sicherheit {{ number_format((float) $sensorik['confidence'] * 100, 0, ',', '.') }} %</span>@endif
            @elseif($sQuelle === 'manual')
                <x-fa::badge tone="info" icon="heroicon-m-pencil">Von Hand gesetzt, gegart</x-fa::badge>
            @elseif($sQuelle === 'gp')
                <x-fa::badge>Grundprodukt, Rohprofil</x-fa::badge>
            @else
                <x-fa::badge tone="warn">Aus den Rohzutaten geschätzt</x-fa::badge>
                @if(isset($sensorik['abdeckung']))<span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] tabular-nums">{{ $sensorik['abdeckung']['mit'] }} von {{ $sensorik['abdeckung']['gesamt'] }} Grundprodukten mit Daten, noch nicht von der KI bewertet</span>@endif
            @endif
        </div>
        @if(($sensorik['reasoning'] ?? null) !== null && $sQuelle === 'ki')
            <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)] italic">{{ $sensorik['reasoning'] }}</p>
        @endif

        <x-fa::section title="Geschmacksprofil" icon="heroicon-o-beaker" meta="sensorisch">
            {{-- #503: Fläche = gegarte Sensorik, Aroma-Anker-Wert je Achse im Tooltip.
                 Rechts: Geschmack + Pairing-Empfehlungen (aus $pairing, recipe-Typ). Laptop: Radar
                 über dem Text bis xl, erst ab xl nebeneinander. --}}
            <div class="flex flex-col xl:flex-row gap-6">
                <div class="shrink-0 mx-auto xl:mx-0 w-full max-w-[360px]">
                    <div class="rounded-[var(--fa-radius-surface)] border border-[var(--fa-line)] bg-[var(--fa-ground)] p-3">
                        @include('foodalchemist::livewire.concepter.partials.geschmack-radar', [
                            'sensGeschmack' => $sensorik['geschmack'] ?? [],
                            'ankerGeschmack' => $pairing['geschmack'] ?? [],
                            'dominant' => $sensorik['dominant'] ?? [],
                            'luecken' => $sensorik['luecken'] ?? [],
                        ])
                    </div>
                </div>
                <div class="flex-1 min-w-0 grid grid-cols-1 lg:grid-cols-2 gap-x-6 gap-y-4 content-start">
                    <div class="flex flex-col gap-3">
                        <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Die Fläche zeigt den Geschmack nach dem Garen je Achse von 0 bis 1. Zeigen auf eine Achse nennt zusätzlich den Wert der Aroma-Anker.</p>
                        {{-- Erdung: Achsen, die auf dem Etikett stehen, sind gerechnet (Nährwerte der Lieferantenartikel), nicht geschätzt. --}}
                        @if(count($sensorik['erdung'] ?? []))
                            <div class="flex flex-col gap-1.5 rounded-[var(--fa-radius-control)] bg-[var(--fa-ok-soft)] px-3 py-2">
                                <p class="flex items-center gap-1.5 text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ok)]">@svg('heroicon-m-chart-bar', 'w-4 h-4') Aus den Nährwerten der Lieferantenartikel gemessen</p>
                                <ul class="flex flex-col gap-1">
                                    @foreach($sensorik['erdung'] as $dim => $e)
                                        <li class="text-[length:var(--fa-text-sm)] {{ $e['angewendet'] ? 'text-[var(--fa-ink)]' : 'text-[var(--fa-ink-3)]' }}">
                                            <span class="font-medium">{{ ucfirst($dimLabel[$dim] ?? $dim) }} {{ number_format((float) ($sensorik['geschmack'][$dim] ?? 0), 2, ',', '.') }}</span>
                                            <span class="text-[var(--fa-ink-2)]">, {{ $e['basis'] }}</span>
                                            @unless($e['angewendet'])<span>, geschätzt</span>@endunless
                                            @if($e['konflikt'])
                                                <span class="block"><x-fa::signal tone="warn">{{ $e['konflikt'] }}</x-fa::signal></span>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                                <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Die übrigen Achsen schätzt die KI, sie stehen auf keinem Etikett.</p>
                            </div>
                        @endif
                        @if(count($sensorik['dominant']) || count($sensorik['luecken']))
                            <div class="flex flex-wrap gap-1.5">
                                @foreach($sensorik['dominant'] as $d)<x-fa::badge tone="ok">dominant: {{ $dimLabel[$d] ?? $d }}</x-fa::badge>@endforeach
                                @foreach($sensorik['luecken'] as $d)<x-fa::badge tone="warn">Lücke: {{ $dimLabel[$d] ?? $d }}</x-fa::badge>@endforeach
                            </div>
                        @endif
                        <div class="flex flex-col gap-1.5 pt-3 border-t border-[var(--fa-line)]">
                            <h4 class="text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]">Textur</h4>
                            @if(count($sensorik['textur']))
                                <div class="flex flex-wrap gap-1.5">
                                    @foreach($sensorik['textur'] as $t)<x-fa::badge>{{ $t['label'] }}</x-fa::badge>@endforeach
                                </div>
                            @else
                                <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Keine Textur-Daten.</p>
                            @endif
                            @if($sensorik['monotonie'])
                                <x-fa::signal tone="warn">{{ $sensorik['monotonie'] }}</x-fa::signal>
                            @endif
                        </div>
                    </div>
                    @include('foodalchemist::livewire.concepter.partials.pairing-empfehlungen', ['pairing' => $pairing ?? null])
                </div>
            </div>
        </x-fa::section>
        {{-- Kein „Ausgleich/Kontrast"-Vorschlag hier: Grundgeschmack = reine Diagnose.
             Kontrast/Komplettierung liefert der Anker-Graph (Pairing-Block: klassisch + kontrast). --}}
    </div>
@endif
