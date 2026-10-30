{{-- #1003 PR6 · Foodbooks, Speisekarten, Speisepläne am Handy (Concerns\HandyAusgabenLesen): Karten mit Suche; Antippen
     öffnet die Live-Vorschau (Kundensicht, derselbe Renderer wie der öffentliche Link) im selben Tab — zurück über den
     Zurück-Knopf. „Intern“ (Foodbook, Speisekarte) öffnet den vorhandenen Report im Profil Kalkulation.
     Nur lesen: kein Anlegen, kein Editor, kein Veröffentlichen. --}}
@php
    $leise = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]';
@endphp
<x-ui-page>
    <x-slot:navbar>
        <x-foodalchemist::shell.page-navbar :title="$titel" icon="heroicon-o-book-open" />
    </x-slot:navbar>

    <div class="flex-1 min-w-0 h-full overflow-y-auto px-4 py-4 space-y-3" data-fa-handy-ausgaben>
        <div class="flex items-baseline justify-between gap-3">
            <h1 class="text-[length:var(--fa-text-2xl)] font-semibold text-[var(--fa-ink)]">{{ $titel }}</h1>
            <span class="{{ $leise }} tabular-nums">{{ number_format($eintraege->total(), 0, ',', '.') }} Treffer</span>
        </div>
        <input type="search" wire:model.live.debounce.300ms="search" placeholder="{{ $suchHinweis }}" aria-label="{{ $titel }} durchsuchen"
               class="fa-control h-12 text-[length:var(--fa-text-base)]" data-fa-handy-suche>

        <ul class="flex flex-col rounded-[var(--fa-radius-surface)] border border-[var(--fa-line)] bg-[var(--fa-surface)] divide-y divide-[var(--fa-line)]">
            @forelse($karten as $k)
                <li wire:key="ha-{{ $k['id'] }}" class="flex items-stretch">
                    <a href="{{ $k['href'] }}" class="flex min-w-0 flex-1 items-center gap-3 px-4 py-3 min-h-[60px] text-left" data-fa-handy-karte="{{ $k['id'] }}">
                        <span class="min-w-0 flex-1">
                            <span class="block font-medium leading-snug text-[var(--fa-ink)] wrap-anywhere">{{ $k['name'] }}</span>
                            @if($k['zeile'] !== '')<span class="block truncate {{ $leise }}">{{ $k['zeile'] }}</span>@endif
                            <x-fa::badge :tone="$k['ton']" class="mt-1">{{ $k['status'] }}</x-fa::badge>
                        </span>
                        @if(! $k['intern'])@svg('heroicon-m-chevron-right', 'w-5 h-5 shrink-0 text-[var(--fa-ink-3)]')@endif
                    </a>
                    @if($k['intern'])
                        <a href="{{ $k['intern'] }}" class="flex shrink-0 flex-col items-center justify-center gap-0.5 min-w-16 px-3 border-l border-[var(--fa-line)] text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-accent)]"
                           aria-label="{{ $k['name'] }}: interne Sicht mit EK und Wareneinsatz" data-fa-handy-intern="{{ $k['id'] }}">
                            @svg('heroicon-m-calculator', 'w-5 h-5')
                            Intern
                        </a>
                    @endif
                </li>
            @empty
                <li class="p-4"><x-fa::empty compact icon="heroicon-o-magnifying-glass" title="Nichts gefunden">Andere Suche versuchen.</x-fa::empty></li>
            @endforelse
        </ul>

        @if($eintraege->hasPages())
            <div class="flex items-center justify-between gap-3" data-fa-handy-blaettern>
                <button type="button" wire:click="previousPage" @disabled($eintraege->onFirstPage()) class="min-h-11 px-3 {{ $leise }} underline disabled:opacity-40 disabled:no-underline">Zurück</button>
                <span class="{{ $leise }} tabular-nums">Seite {{ $eintraege->currentPage() }} von {{ $eintraege->lastPage() }}</span>
                <button type="button" wire:click="nextPage" @disabled(! $eintraege->hasMorePages()) class="min-h-11 px-3 {{ $leise }} underline disabled:opacity-40 disabled:no-underline">Weiter</button>
            </div>
        @endif
    </div>
</x-ui-page>
