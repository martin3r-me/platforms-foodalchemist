{{-- Angebote am Handy (Concerns\HandyAngeboteLesen, Dominique 08.11.2026): Karten mit Suche und Status-Chips wie Foodbooks;
     Antippen öffnet die Live-Vorschau (Kundensicht) im selben Tab, „Dokument“ das Angebots-Dokument mit Preisen.
     Nur lesen: kein Anlegen, kein Editor, kein Status-Wechsel. --}}
@php
    $leise = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]';
    $chip = 'inline-flex items-center h-9 px-3 rounded-full text-[length:var(--fa-text-sm)] font-medium border whitespace-nowrap';
    $chipAn = 'border-[var(--fa-accent)] bg-[var(--fa-accent)] text-[var(--fa-on-accent)]';
    $chipAus = 'border-[var(--fa-line)] bg-[var(--fa-surface)] text-[var(--fa-ink-2)]';
@endphp
<x-ui-page>
    <x-slot:navbar>
        <x-foodalchemist::shell.page-navbar title="Angebote" icon="heroicon-o-document-text" />
    </x-slot:navbar>

    <div class="flex-1 min-w-0 h-full overflow-y-auto px-4 py-4 space-y-3" data-fa-handy-angebote>
        <div class="flex items-baseline justify-between gap-3">
            <h1 class="text-[length:var(--fa-text-2xl)] font-semibold text-[var(--fa-ink)]">Angebote</h1>
            <span class="{{ $leise }} tabular-nums">{{ number_format($eintraege->total(), 0, ',', '.') }} Treffer</span>
        </div>
        <input type="search" wire:model.live.debounce.300ms="search" placeholder="Angebot oder Anlass suchen" aria-label="Angebote durchsuchen"
               class="fa-control h-12 text-[length:var(--fa-text-base)]" data-fa-handy-suche>

        <div role="group" aria-label="Status" class="-mx-4 px-4 flex gap-1.5 overflow-x-auto" data-fa-handy-angebote-status>
            <button type="button" wire:click="waehleStatus('')" aria-pressed="{{ $statusFilter === '' ? 'true' : 'false' }}" class="{{ $chip }} {{ $statusFilter === '' ? $chipAn : $chipAus }}">Alle</button>
            @foreach($statusWerte as $sw)
                <button type="button" wire:click="waehleStatus('{{ $sw['value'] }}')" aria-pressed="{{ $statusFilter === $sw['value'] ? 'true' : 'false' }}"
                        class="{{ $chip }} {{ $statusFilter === $sw['value'] ? $chipAn : $chipAus }}">{{ $sw['label'] }}</button>
            @endforeach
        </div>

        <ul class="flex flex-col rounded-[var(--fa-radius-surface)] border border-[var(--fa-line)] bg-[var(--fa-surface)] divide-y divide-[var(--fa-line)]">
            @forelse($karten as $k)
                <li wire:key="hang-{{ $k['id'] }}" class="flex items-stretch">
                    <a href="{{ $k['href'] }}" class="flex min-w-0 flex-1 items-center gap-3 px-4 py-3 min-h-[60px] text-left" data-fa-handy-angebot="{{ $k['id'] }}">
                        <span class="min-w-0 flex-1">
                            <span class="block font-medium leading-snug text-[var(--fa-ink)] wrap-anywhere">{{ $k['name'] }}</span>
                            @if($k['zeile'] !== '')<span class="block truncate {{ $leise }}">{{ $k['zeile'] }}</span>@endif
                            <span class="mt-1 flex items-center gap-2">
                                <x-fa::badge :tone="$k['ton']">{{ $k['status'] }}</x-fa::badge>
                                <span class="text-[length:var(--fa-text-sm)] tabular-nums text-[var(--fa-ink-2)]"><x-fa::money :value="$k['preis']" missing="Noch kein Preis" /></span>
                            </span>
                        </span>
                    </a>
                    <a href="{{ $k['dokument'] }}" class="flex shrink-0 flex-col items-center justify-center gap-0.5 min-w-16 px-3 border-l border-[var(--fa-line)] text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-accent)]"
                       aria-label="{{ $k['name'] }}: Angebots-Dokument mit Preisen" data-fa-handy-angebot-dokument="{{ $k['id'] }}">
                        @svg('heroicon-m-document-text', 'w-5 h-5')
                        Dokument
                    </a>
                    <a href="{{ $k['intern'] }}" class="flex shrink-0 flex-col items-center justify-center gap-0.5 min-w-16 px-3 border-l border-[var(--fa-line)] text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-accent)]"
                       aria-label="{{ $k['name'] }}: interne Sicht mit EK und Wareneinsatz" data-fa-handy-intern="{{ $k['id'] }}">
                        @svg('heroicon-m-calculator', 'w-5 h-5')
                        Intern
                    </a>
                </li>
            @empty
                <li class="p-4"><x-fa::empty compact icon="heroicon-o-magnifying-glass" title="Nichts gefunden">Suche oder Status zurücksetzen.</x-fa::empty></li>
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
