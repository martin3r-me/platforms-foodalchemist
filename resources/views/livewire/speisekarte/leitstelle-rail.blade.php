{{-- Speisekarte-Leitstelle (fa-pass 2026-10-05): abgeleitete Fertigstellungs-Liste (nur lesend)
     + Werkstrang M Phase E: Soll/Ist-Abgleich gegen das Planungs-Gerüst (nur wenn eines existiert).
     Je Punkt eine klar abgegrenzte Zeile mit Zustand als Wort und Symbol, nicht nur als Farbpunkt. --}}
@php
    $zustand = [
        'erledigt' => ['Erledigt', 'ok', 'heroicon-m-check-circle'],
        'teil' => ['Teilweise', 'warn', 'heroicon-m-exclamation-triangle'],
        'offen' => ['Offen', 'neutral', 'heroicon-m-minus-circle'],
    ];
    $symbolFarbe = ['ok' => 'text-[var(--fa-ok)]', 'warn' => 'text-[var(--fa-warn)]', 'neutral' => 'text-[var(--fa-ink-3)]'];
    $erledigt = collect($stand['punkte'])->where('status', 'erledigt')->count();
@endphp

<div class="flex flex-col gap-4">
    <x-fa::section title="Was fehlt der Karte noch?" icon="heroicon-o-clipboard-document-check"
        :meta="$erledigt . ' von ' . count($stand['punkte']) . ' erledigt'">
        <x-slot:actions>
            @if($stand['bereit'])
                <x-fa::badge tone="ok" icon="heroicon-m-check">Bereit zur Ausgabe</x-fa::badge>
            @else
                <x-fa::badge tone="warn">In Arbeit</x-fa::badge>
            @endif
        </x-slot:actions>

        <ul class="flex flex-col divide-y divide-[var(--fa-line)] border border-[var(--fa-line)] rounded-[var(--fa-radius-control)]">
            @foreach($stand['punkte'] as $punkt)
                @php
                    [$text, $ton, $symbol] = $zustand[$punkt['status']] ?? $zustand['offen'];
                @endphp
                <li wire:key="sk-ls-{{ $punkt['key'] }}" class="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2.5 min-w-0">
                    <span class="inline-flex shrink-0 {{ $symbolFarbe[$ton] }}">@svg($symbol, 'w-[18px] h-[18px]')</span>
                    <span class="min-w-0 flex-1 text-[length:var(--fa-text-md)] font-medium text-[var(--fa-ink)]">{{ $punkt['label'] }}</span>
                    @if(($punkt['hinweis'] ?? '') !== '')
                        <span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]">{{ $punkt['hinweis'] }}</span>
                    @endif
                    <x-fa::badge :tone="$ton">{{ $text }}</x-fa::badge>
                </li>
            @endforeach
        </ul>
    </x-fa::section>

    {{-- Werkstrang M Phase E: Soll/Ist-Abgleich NUR bei vorhandenem Planungs-Gerüst (kein Gerüst-Zwang). --}}
    @if($coverage['hat_geruest'] ?? false)
        @include('foodalchemist::livewire.planning.partials.coverage-panel', ['coverage' => $coverage])
    @endif
</div>
