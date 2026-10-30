{{-- #1003 Teil 8 · Wissen am Handy (Concerns\HandyWissenLesen): Dossiers als Karten → Dossier lesbar über die ganze Breite.
     Nur aktive Dossiers in der Sicht des Teams; keine Pflege (Rohtext, Aliase, Kanon, Aktivieren, Löschen). Tabellen im
     eigenen Scroll-Rahmen (data-fa-tabelle-rahmen), langer Text bricht um — die Seite scrollt nie seitlich. --}}
@php
    $leise = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]';
    // Markdown-Typografie wie in der Lese-Ansicht am Computer (knowledge/browser), dazu Umbruch und Bilder in Seitenbreite
    $prose = 'min-w-0 wrap-anywhere text-[length:var(--fa-text-base)] leading-relaxed text-[var(--fa-ink)]'
        . ' [&_h1]:text-[length:var(--fa-text-2xl)] [&_h1]:font-semibold [&_h1]:mt-5 [&_h1]:mb-2'
        . ' [&_h2]:text-[length:var(--fa-text-lg)] [&_h2]:font-semibold [&_h2]:mt-5 [&_h2]:mb-1.5'
        . ' [&_h3]:text-[length:var(--fa-text-base)] [&_h3]:font-semibold [&_h3]:mt-4 [&_h3]:mb-1 [&_h3]:text-[var(--fa-ink-2)]'
        . ' [&_p]:my-2 [&_ul]:list-disc [&_ul]:pl-5 [&_ul]:my-2 [&_ol]:list-decimal [&_ol]:pl-6 [&_ol]:my-2 [&_li]:my-0.5'
        . ' [&_code]:font-mono [&_code]:text-[length:var(--fa-text-sm)] [&_code]:bg-[var(--fa-neutral-soft)] [&_code]:px-1 [&_code]:rounded'
        . ' [&_pre]:bg-[var(--fa-neutral-soft)] [&_pre]:p-3 [&_pre]:rounded-[var(--fa-radius-control)] [&_pre]:overflow-x-auto [&_pre]:my-3'
        . ' [&_blockquote]:border-l-[3px] [&_blockquote]:border-[var(--fa-accent-line)] [&_blockquote]:pl-3 [&_blockquote]:text-[var(--fa-ink-2)] [&_blockquote]:my-3'
        . ' [&_table]:min-w-full [&_table]:my-3 [&_table]:text-[length:var(--fa-text-md)] [&_th]:text-left [&_th]:font-semibold [&_th]:px-2 [&_th]:py-1 [&_th]:border-b [&_th]:border-[var(--fa-line-strong)]'
        . ' [&_td]:px-2 [&_td]:py-1 [&_td]:align-top [&_td]:border-t [&_td]:border-[var(--fa-line)] [&_td]:wrap-normal [&_th]:wrap-normal'
        . ' [&_a]:text-[var(--fa-accent)] [&_a]:underline [&_hr]:my-5 [&_hr]:border-[var(--fa-line)] [&_img]:max-w-full [&_img]:h-auto';
    $liste = 'flex flex-col rounded-[var(--fa-radius-surface)] border border-[var(--fa-line)] bg-[var(--fa-surface)] divide-y divide-[var(--fa-line)]';
@endphp
<x-ui-page>
    <x-slot:navbar>
        <x-foodalchemist::shell.page-navbar title="Wissen" icon="heroicon-o-academic-cap" />
    </x-slot:navbar>

    <div class="flex-1 min-w-0 h-full overflow-y-auto overflow-x-hidden px-4 py-4 space-y-3" data-fa-handy-wissen>
        @if($dossier !== null || $dossierFehlt)
            <button type="button" wire:click="handyZurueck" class="-ml-1 inline-flex items-center gap-1 min-h-11 pr-3 text-[length:var(--fa-text-md)] font-medium text-[var(--fa-accent)]" data-fa-handy-zurueck>
                @svg('heroicon-m-chevron-left', 'w-5 h-5') Zurück
            </button>
        @endif

        @if($dossier !== null)
            <article class="space-y-3 min-w-0" data-fa-handy-dossier="{{ $dossier->id }}">
                <div>
                    <h1 class="text-[length:var(--fa-text-2xl)] font-semibold leading-tight text-[var(--fa-ink)] wrap-anywhere">{{ $dossier->title }}</h1>
                    <p class="{{ $leise }}">{{ implode(' · ', array_filter([$kategorien[$dossier->category] ?? $dossier->category, $dossier->art ?? null])) }}</p>
                </div>
                @if(trim($html) === '')
                    <p class="{{ $leise }}">Dieses Dossier hat noch keinen Text.</p>
                @else
                    <div class="{{ $prose }}" data-fa-handy-dossier-text>{!! $html !!}</div>
                @endif
            </article>
        @elseif($dossierFehlt)
            <x-fa::empty compact icon="heroicon-o-academic-cap" title="Dossier nicht verfügbar">Es ist nicht aktiv oder für diesen Mandanten nicht sichtbar.</x-fa::empty>
        @else
            <div class="flex items-baseline justify-between gap-3">
                <h1 class="text-[length:var(--fa-text-2xl)] font-semibold text-[var(--fa-ink)]">Wissen</h1>
                @if(method_exists($treffer, 'total'))
                    <span class="{{ $leise }} tabular-nums">{{ number_format($treffer->total(), 0, ',', '.') }} Dossiers</span>
                @endif
            </div>
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Dossier suchen" aria-label="Wissen durchsuchen"
                   class="fa-control h-12 text-[length:var(--fa-text-base)]" data-fa-handy-suche>
            @if($kategorien !== [])
                <select wire:model.live="filterCategory" aria-label="Kategorie" class="fa-select h-12 text-[length:var(--fa-text-base)]" data-fa-handy-kategorie>
                    <option value="">Alle Kategorien</option>
                    @foreach($kategorien as $slug => $label)<option value="{{ $slug }}">{{ $label }}</option>@endforeach
                </select>
            @endif
            <ul class="{{ $liste }}">
                @forelse($treffer as $d)
                    <li wire:key="hw-{{ $d->id }}">
                        <button type="button" wire:click="select({{ $d->id }})" class="flex w-full items-center gap-3 px-4 py-3 min-h-[60px] text-left" data-fa-handy-karte="{{ $d->id }}">
                            <span class="min-w-0 flex-1">
                                <span class="block font-medium leading-snug text-[var(--fa-ink)] wrap-anywhere">{{ $d->title }}</span>
                                <span class="block truncate {{ $leise }}">{{ $kategorien[$d->category] ?? $d->category }}</span>
                            </span>
                            @svg('heroicon-m-chevron-right', 'w-5 h-5 shrink-0 text-[var(--fa-ink-3)]')
                        </button>
                    </li>
                @empty
                    <li class="p-4"><x-fa::empty compact icon="heroicon-o-magnifying-glass" title="Nichts gefunden">Andere Suche oder Kategorie versuchen.</x-fa::empty></li>
                @endforelse
            </ul>
            @if(method_exists($treffer, 'links'))
                <div>{{ $treffer->links('foodalchemist::components.fa.pagination') }}</div>
            @endif
        @endif
    </div>
</x-ui-page>
