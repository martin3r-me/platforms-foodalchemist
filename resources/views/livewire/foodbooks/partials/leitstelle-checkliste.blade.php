{{-- Spec 19 E5.2 — Leitstellen-Checkliste: abgeleitete Arbeits-Schritte (Bedarf bis Preise)
     als klickbare Chips (offen/teil/erledigt). Klick springt via Alpine-Event-Bus (`fb-goto`)
     auf Tab + Anker; die Root des Cockpits (`x-data`) hört darauf. Die Foodbook-Freigabe/
     „Versand" ist NIE Teil dieser Liste (UX 1) — das ist der Phasen-Stepper daneben.
     fa-pass: Zustandsfarben über Tokens, Zustand zusätzlich als Symbol (nicht nur Farbe).
     Erwartet: $checkliste (list<array{key,nr,label,status,tab,anker,hinweis?}>). --}}
@php
    $statusStil = [
        'erledigt' => 'bg-[var(--fa-ok-soft)] border-transparent text-[var(--fa-ok)]',
        'teil' => 'bg-[var(--fa-warn-soft)] border-transparent text-[var(--fa-warn)]',
        'offen' => 'bg-[var(--fa-surface)] border-[var(--fa-line-strong)] text-[var(--fa-ink-2)] hover:bg-[var(--fa-hover)]',
    ];
    $statusSymbol = ['erledigt' => 'heroicon-m-check-circle', 'teil' => 'heroicon-m-ellipsis-horizontal-circle', 'offen' => 'heroicon-o-stop-circle'];
    $statusText = ['erledigt' => 'erledigt', 'teil' => 'teilweise erledigt', 'offen' => 'offen'];
@endphp

@if(! empty($checkliste))
    <nav class="flex flex-wrap items-center gap-x-1.5 gap-y-2" aria-label="Arbeitsschritte" data-leitstelle-checkliste>
        <span class="mr-1 text-[length:var(--fa-text-sm)] font-semibold text-[var(--fa-ink-2)]">Schritte</span>
        @foreach($checkliste as $s)
            <button type="button"
                    @click="$dispatch('fb-goto', { tab: @js($s['tab']), anker: @js($s['anker']) })"
                    class="inline-flex items-center gap-1.5 h-7 px-2.5 rounded-full border text-[length:var(--fa-text-sm)] transition-colors duration-150 {{ $statusStil[$s['status']] ?? $statusStil['offen'] }}"
                    title="{{ ($s['hinweis'] ?? $s['label']) . ' (' . ($statusText[$s['status']] ?? 'offen') . ')' }}"
                    data-checkliste-schritt="{{ $s['key'] }}" data-status="{{ $s['status'] }}">
                @svg($statusSymbol[$s['status']] ?? $statusSymbol['offen'], 'w-4 h-4 shrink-0')
                <span class="tabular-nums text-[var(--fa-ink-3)]">{{ $s['nr'] }}</span>
                <span class="font-medium">{{ $s['label'] }}</span>
            </button>
            @if(! $loop->last)@svg('heroicon-m-chevron-right', 'w-3.5 h-3.5 text-[var(--fa-ink-3)] shrink-0')@endif
        @endforeach
    </nav>
@endif
