{{-- Preisverlauf als Linie (LA: eine Linie, GP: eine Linie je LA). Reines SVG, Geometrie in Preise\Verlauf::diagramm. --}}
@php
    $zeitraeume = ['3m' => '3 M', '6m' => '6 M', '12m' => '12 M', 'alles' => 'Alles', 'frei' => 'von–bis'];
@endphp
<div class="flex flex-col gap-3" data-preisverlauf="{{ $modus }}">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <div class="inline-flex rounded-[var(--fa-radius-control)] border border-[var(--fa-line)] p-0.5" role="group" aria-label="Zeitraum" data-preisverlauf-zeitraum>
            @foreach($zeitraeume as $key => $label)
                <button type="button" wire:click="zeitraumSetzen('{{ $key }}')" aria-pressed="{{ $zeitraum === $key ? 'true' : 'false' }}"
                        class="h-7 px-2.5 rounded-[var(--fa-radius-control)] text-[length:var(--fa-text-sm)] {{ $zeitraum === $key ? 'bg-[var(--fa-accent)] text-[var(--fa-on-accent)] font-medium' : 'text-[var(--fa-ink-2)] hover:text-[var(--fa-ink)]' }}"
                        data-zeitraum="{{ $key }}">{{ $label }}</button>
            @endforeach
        </div>
        @if($zeitraum === 'frei')
            <div class="flex items-center gap-2">
                <input type="date" wire:model.live="von" class="fa-input h-7 text-[length:var(--fa-text-sm)]" aria-label="von" data-preisverlauf-von>
                <span class="text-[var(--fa-ink-3)]">–</span>
                <input type="date" wire:model.live="bis" class="fa-input h-7 text-[length:var(--fa-text-sm)]" aria-label="bis" data-preisverlauf-bis>
            </div>
        @endif
    </div>

    @if($fehler)
        <x-fa::notice tone="warn" data-preisverlauf-fehler>{{ $fehler }}</x-fa::notice>
    @elseif($daten === null)
        <x-fa::empty compact icon="heroicon-o-presentation-chart-line" title="Kein Verlauf">Der Artikel bzw. das Grundprodukt ist nicht sichtbar.</x-fa::empty>
    @elseif($diagramm === null)
        <x-fa::empty compact icon="heroicon-o-presentation-chart-line" title="Kein Preisverlauf im Zeitraum" data-preisverlauf-leer>
            Für diesen Zeitraum gibt es keinen Preisstand.
            @if($daten['weggelassen'] > 0)
                {{ $daten['weggelassen'] }} Artikel ohne passende Basiseinheit.
            @endif
        </x-fa::empty>
    @else
        <svg viewBox="0 0 {{ $diagramm['w'] }} {{ $diagramm['h'] }}" class="w-full h-auto" role="img" aria-label="Preisverlauf in {{ $diagramm['einheit'] }}" data-preisverlauf-svg>
            @foreach($diagramm['achse_y'] as $a)
                <line x1="{{ $diagramm['links'] }}" x2="{{ $diagramm['rechts'] }}" y1="{{ $a['y'] }}" y2="{{ $a['y'] }}" stroke="var(--fa-line)" stroke-width="1" />
                <text x="{{ $diagramm['links'] - 6 }}" y="{{ $a['y'] + 4 }}" text-anchor="end" font-size="11" fill="var(--fa-ink-3)">{{ $a['label'] }}</text>
            @endforeach
            @foreach($diagramm['achse_x'] as $a)
                <text x="{{ $a['x'] }}" y="{{ $diagramm['h'] - 6 }}" text-anchor="{{ $a['anker'] }}" font-size="11" fill="var(--fa-ink-3)">{{ $a['label'] }}</text>
            @endforeach
            <text x="{{ $diagramm['links'] }}" y="9" font-size="11" fill="var(--fa-ink-3)">{{ $diagramm['einheit'] }}</text>
            @foreach($diagramm['linien'] as $l)
                <g data-preisverlauf-linie="{{ $l['la_id'] }}" data-lead="{{ $l['lead'] ? '1' : '0' }}">
                    <path d="{{ $l['pfad'] }}" fill="none" stroke="{{ $l['farbe'] }}" stroke-width="{{ $l['breite'] }}" stroke-linejoin="round" vector-effect="non-scaling-stroke" />
                    @if($l['trend'])
                        <line x1="{{ $l['trend']['x1'] }}" y1="{{ $l['trend']['y1'] }}" x2="{{ $l['trend']['x2'] }}" y2="{{ $l['trend']['y2'] }}" stroke="{{ $l['farbe'] }}"
                              stroke-width="1.5" stroke-dasharray="6 4" data-preisverlauf-trend />
                    @endif
                    @foreach($l['punkte'] as $p)
                        <circle cx="{{ $p['x'] }}" cy="{{ $p['y'] }}" r="4" fill="{{ $l['farbe'] }}" fill-opacity="0.001" stroke="none"><title>{{ $p['tip'] }}</title></circle>
                    @endforeach
                </g>
            @endforeach
        </svg>

        <ul class="flex flex-col gap-1.5" data-preisverlauf-legende>
            @foreach($diagramm['linien'] as $l)
                <li class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5 text-[length:var(--fa-text-sm)]" data-legende-la="{{ $l['la_id'] }}">
                    <span class="inline-block h-0.5 w-5 shrink-0 self-center rounded" style="background: {{ $l['farbe'] }}; height: {{ $l['lead'] ? 3 : 2 }}px"></span>
                    <span class="font-medium text-[var(--fa-ink)]">{{ $l['lieferant'] ?? '–' }}</span>
                    <span class="text-[var(--fa-ink-2)]">{{ $l['artikel'] }}</span>
                    @if($l['lead'])<x-fa::badge tone="accent">Lead</x-fa::badge>@endif
                    <span class="tabular-nums text-[var(--fa-ink)]">{{ number_format($l['aktuell'], 2, ',', '.') }} {{ $diagramm['einheit'] }}</span>
                    @if($l['trend_text'])
                        <span class="tabular-nums text-[var(--fa-ink-3)]" data-preisverlauf-steigung>Trend {{ $l['trend_text'] }}</span>
                    @else
                        <span class="text-[var(--fa-ink-3)]">kein Trend (weniger als 2 Stände)</span>
                    @endif
                </li>
            @endforeach
        </ul>
        @if($daten['weggelassen'] > 0)
            <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]" data-preisverlauf-weggelassen>
                {{ $daten['weggelassen'] }} Artikel nicht gezeigt: andere Basiseinheit als {{ $diagramm['einheit'] }} (nicht mischbar) oder ohne Mengenangabe.
            </p>
        @endif
    @endif
</div>
