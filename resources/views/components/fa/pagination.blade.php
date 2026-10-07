{{-- Seitenblätterung für Livewire-Paginator: $rezepte->links('foodalchemist::components.fa.pagination')
     Ruhige Zeile: „1 bis 100 von 2.533" links, Blättern rechts. Keine dunkle Knopfleiste mehr. --}}
@php($pageName = $paginator->getPageName())
@if($paginator->total() > 0)
    <nav role="navigation" aria-label="Seiten" class="flex flex-wrap items-center justify-between gap-3">
        <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)] tabular-nums">
            {{ number_format($paginator->firstItem() ?? 0, 0, ',', '.') }} bis {{ number_format($paginator->lastItem() ?? 0, 0, ',', '.') }} von {{ number_format($paginator->total(), 0, ',', '.') }}
        </p>
        @if($paginator->hasPages())
            <div class="flex items-center gap-1">
                <x-fa::icon-button icon="heroicon-m-chevron-left" label="Vorherige Seite" size="sm"
                    wire:click="previousPage('{{ $pageName }}')" wire:loading.attr="disabled" :disabled="$paginator->onFirstPage()" />
                @foreach($elements as $element)
                    @if(is_string($element))
                        <span class="px-1 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">…</span>
                    @endif
                    @if(is_array($element))
                        @foreach($element as $page => $url)
                            @if($page == $paginator->currentPage())
                                <span aria-current="page" class="inline-flex items-center justify-center min-w-7 h-7 px-2 rounded-[var(--fa-radius-control)] bg-[var(--fa-accent-soft)] text-[var(--fa-accent)] text-[length:var(--fa-text-sm)] font-semibold tabular-nums">{{ $page }}</span>
                            @else
                                <button type="button" wire:click="gotoPage({{ $page }}, '{{ $pageName }}')" wire:key="pg-{{ $pageName }}-{{ $page }}"
                                        class="inline-flex items-center justify-center min-w-7 h-7 px-2 rounded-[var(--fa-radius-control)] text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)] hover:bg-[var(--fa-hover)] tabular-nums">{{ $page }}</button>
                            @endif
                        @endforeach
                    @endif
                @endforeach
                <x-fa::icon-button icon="heroicon-m-chevron-right" label="Nächste Seite" size="sm"
                    wire:click="nextPage('{{ $pageName }}')" wire:loading.attr="disabled" :disabled="! $paginator->hasMorePages()" />
            </div>
        @endif
    </nav>
@endif
