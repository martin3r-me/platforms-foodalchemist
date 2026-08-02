{{-- Trendradar (#FA-Trendradar): geclusterte Trend-Wissens-Docs, kuratiert.
     LINKS Suche + Facetten + Kategorie→Klasse-Baum · MITTE Top-Trends + Liste ·
     RECHTS Detail des gewählten Trends. Read-only — Erfassung ist das Office-Projekt. --}}
@php
    extract(\Platform\FoodAlchemist\Support\Ui::maps());
    $maturityLabels = ['niche' => 'Nische', 'emerging' => 'Im Kommen', 'mainstream' => 'Mainstream', 'declining' => 'Abklingend'];
    $relevanceLabels = ['high' => 'Hoch', 'medium' => 'Mittel', 'low' => 'Niedrig'];
    $chip = fn ($text, $cls = 'bg-black/[0.04] text-gray-600') => '<span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium ' . $cls . '">' . e($text) . '</span>';
@endphp

<x-ui-page>
    <x-slot:navbar>
        <x-ui-page-navbar title="Trendradar" icon="heroicon-o-sparkles" />
    </x-slot:navbar>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Food Alchemist', 'href' => route('foodalchemist.dashboard'), 'icon' => 'cube'],
            ['label' => 'Trendradar'],
        ]" />
    </x-slot>

    {{-- LINKS: Suche, Facetten, Taxonomie-Baum --}}
    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Trends" width="w-80">
            <div class="p-3 space-y-3">
                <input type="text" wire:model.live.debounce.300ms="search"
                       placeholder="{{ $semantic ? 'Semantisch suchen (Bedeutung) …' : 'Suche (Titel · Slug · Inhalt) …' }}"
                       class="{{ $input }}" />

                <label class="flex items-center gap-1.5 text-[11px] text-gray-600 cursor-pointer">
                    <input type="checkbox" wire:model.live="semantic" /> semantisch suchen
                </label>
                @if($semanticNote !== null)
                    <p class="text-[11px] text-amber-600">{{ $semanticNote }}</p>
                @endif

                <div class="grid grid-cols-2 gap-2">
                    <select wire:model.live="maturity" class="{{ $input }} !py-1 text-xs">
                        <option value="">Reifegrad</option>
                        @foreach($maturityLabels as $val => $lbl)
                            <option value="{{ $val }}">{{ $lbl }}</option>
                        @endforeach
                    </select>
                    <select wire:model.live="relevance" class="{{ $input }} !py-1 text-xs">
                        <option value="">Relevanz</option>
                        @foreach($relevanceLabels as $val => $lbl)
                            <option value="{{ $val }}">{{ $lbl }}</option>
                        @endforeach
                    </select>
                </div>
                <label class="flex items-center gap-1.5 text-[11px] text-gray-600 cursor-pointer">
                    <input type="checkbox" wire:model.live="onlyHype" /> nur Hype
                </label>

                @if($category !== '' || $trendClass !== '' || $maturity !== '' || $relevance !== '' || $onlyHype || $search !== '')
                    <button wire:click="resetFilter" class="{{ $btnGhostXs }}">Filter zurücksetzen</button>
                @endif

                {{-- Kategorie → Klasse-Baum --}}
                <div class="pt-2 border-t border-black/5">
                    <p class="{{ $dt ?? 'text-[11px] font-semibold text-gray-500 uppercase tracking-wide' }} mb-1">Taxonomie</p>
                    <div class="space-y-0.5 max-h-[46vh] overflow-y-auto -mx-1 px-1">
                        @foreach($tree as $node)
                            @if($node->trend_class === null)
                                <button wire:click="filterAuf(@js($node->category))"
                                        class="w-full flex items-center justify-between text-left px-1.5 py-1 rounded-md text-xs font-semibold hover:bg-black/[0.04] {{ $category === $node->category && $trendClass === '' ? 'bg-violet-500/10 text-violet-700' : 'text-gray-700' }}">
                                    <span>{{ $node->description ?: $node->category }}</span>
                                    <span class="text-[10px] text-gray-400">{{ $node->doc_count }}</span>
                                </button>
                            @else
                                <button wire:click="filterAuf(@js($node->category), @js($node->trend_class))"
                                        class="w-full flex items-center justify-between text-left pl-4 pr-1.5 py-0.5 rounded-md text-[11px] hover:bg-black/[0.04] {{ $trendClass === $node->trend_class ? 'bg-violet-500/10 text-violet-700' : 'text-gray-600' }}">
                                    <span class="truncate">
                                        {{ $node->trend_class }}
                                        @if($node->status === 'tentative')
                                            <span class="ml-1 text-[9px] text-amber-600" title="Von der KI vorgeschlagen — noch nicht freigegeben">tentativ</span>
                                        @endif
                                    </span>
                                    <span class="text-[10px] text-gray-400">{{ $node->doc_count }}</span>
                                </button>
                            @endif
                        @endforeach
                    </div>
                </div>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    {{-- MITTE: Top-Trends + Liste --}}
    <x-ui-page-container padding="px-6 pb-6" spacing="space-y-5">
        {{-- Top-Trends-Dashboard --}}
        <div>
            <h2 class="text-sm font-semibold text-gray-700 mb-2">Top-Trends</h2>
            @if(count($topTrends) === 0)
                <div class="{{ $card }} p-4 text-xs text-gray-500">
                    Noch keine geclusterten Trends. Lauf <code class="font-mono">php artisan foodalchemist:trend-cluster</code> starten.
                </div>
            @else
                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
                    @foreach($topTrends as $t)
                        <button wire:key="top-{{ $t->slug }}" wire:click="select(@js($t->slug))"
                                class="{{ $card }} p-3 text-left hover:shadow-md transition-all">
                            <div class="flex items-start justify-between gap-2">
                                <span class="text-xs font-semibold text-gray-800 leading-snug">{{ $t->title }}</span>
                                @if($t->cluster_size > 1)
                                    <span class="shrink-0 text-[10px] font-semibold text-orange-600" title="{{ $t->cluster_size }} verwandte Signale">🔥 {{ $t->cluster_size }}</span>
                                @endif
                            </div>
                            <div class="mt-2 flex flex-wrap gap-1">
                                @if($t->category)  {!! $chip($t->category, 'bg-violet-500/10 text-violet-700') !!} @endif
                                @if($t->trend_class)  {!! $chip($t->trend_class) !!} @endif
                                @if($t->maturity)  {!! $chip($maturityLabels[$t->maturity] ?? $t->maturity, 'bg-sky-500/10 text-sky-700') !!} @endif
                                @if($t->is_hype)  {!! $chip('Hype', 'bg-amber-500/15 text-amber-700') !!} @endif
                            </div>
                        </button>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Gefilterte Liste --}}
        <div>
            <div class="flex items-center justify-between mb-2">
                <h2 class="text-sm font-semibold text-gray-700">Alle Trends</h2>
                <span class="text-[11px] text-gray-500">{{ $docs->count() }} Treffer{{ $semanticAktiv ? ' · semantisch' : '' }}</span>
            </div>
            <div class="space-y-1">
                @forelse($docs as $doc)
                    <button wire:key="row-{{ $doc->slug }}" wire:click="select(@js($doc->slug))"
                            class="w-full text-left {{ $card }} px-3 py-2 hover:shadow-md transition-all {{ $selected && $selected->slug === $doc->slug ? 'ring-2 ring-violet-500/30' : '' }}">
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-xs font-medium text-gray-800 truncate">{{ $doc->title }}</span>
                            <div class="shrink-0 flex items-center gap-1">
                                @if($doc->cluster_size > 1)
                                    <span class="text-[10px] text-orange-600">🔥 {{ $doc->cluster_size }}</span>
                                @endif
                                @if($doc->relevance)  {!! $chip($relevanceLabels[$doc->relevance] ?? $doc->relevance, 'bg-emerald-500/10 text-emerald-700') !!} @endif
                            </div>
                        </div>
                        <div class="mt-1 flex flex-wrap gap-1">
                            @if($doc->category)  {!! $chip($doc->category, 'bg-violet-500/10 text-violet-700') !!} @endif
                            @if($doc->trend_class)  {!! $chip($doc->trend_class) !!} @endif
                            @if($doc->maturity)  {!! $chip($maturityLabels[$doc->maturity] ?? $doc->maturity, 'bg-sky-500/10 text-sky-700') !!} @endif
                            @if($doc->is_hype)  {!! $chip('Hype', 'bg-amber-500/15 text-amber-700') !!} @endif
                            @if($doc->status === 'tentative')  {!! $chip('tentativ', 'bg-amber-500/10 text-amber-600') !!} @endif
                            @if(! $doc->category)  {!! $chip('ungeclustert', 'bg-gray-500/10 text-gray-500') !!} @endif
                        </div>
                    </button>
                @empty
                    <div class="{{ $card }} p-4 text-xs text-gray-500">Keine Trends für diese Filter.</div>
                @endforelse
            </div>
        </div>
    </x-ui-page-container>

    {{-- RECHTS: Detail --}}
    <x-slot name="activity">
        <x-foodalchemist::detail-sidebar title="Trend" width="w-96" :maxWidth="760" scope="activity_trendradar" side="right">
            @if($selected)
                <div class="p-4 space-y-4">
                    <div class="flex items-start justify-between gap-2">
                        <h3 class="text-sm font-semibold text-gray-800 leading-snug">{{ $selected->title }}</h3>
                        <button wire:click="deselect" class="{{ $btnGhostXs }}">schließen</button>
                    </div>

                    <button wire:click="inPlanungOeffnen" class="{{ $btnPrimary }} w-full justify-center">
                        @svg('heroicon-o-light-bulb', 'w-4 h-4')
                        In Planung öffnen
                    </button>
                    <div class="flex flex-wrap gap-1">
                        @if($selected->category)  {!! $chip($selected->category, 'bg-violet-500/10 text-violet-700') !!} @endif
                        @if($selected->trend_class)  {!! $chip($selected->trend_class) !!} @endif
                        @if($selected->maturity)  {!! $chip($maturityLabels[$selected->maturity] ?? $selected->maturity, 'bg-sky-500/10 text-sky-700') !!} @endif
                        @if($selected->relevance)  {!! $chip($relevanceLabels[$selected->relevance] ?? $selected->relevance, 'bg-emerald-500/10 text-emerald-700') !!} @endif
                        @if($selected->is_hype)  {!! $chip('Hype', 'bg-amber-500/15 text-amber-700') !!} @endif
                        @if($selected->status === 'tentative')  {!! $chip('tentativ', 'bg-amber-500/10 text-amber-600') !!} @endif
                    </div>

                    @if(count($selectedQuellen) > 0)
                        <div class="text-[11px] text-gray-500">
                            <p class="font-semibold mb-0.5">Quellen</p>
                            <ul class="space-y-0.5">
                                @foreach($selectedQuellen as $q)
                                    <li class="truncate">{{ $q }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <div class="prose prose-sm max-w-none text-gray-700 text-[13px] leading-relaxed">
                        {!! $selectedHtml !!}
                    </div>
                    <p class="text-[10px] font-mono text-gray-400">{{ $selected->slug }} · v{{ $selected->version }}</p>
                </div>
            @else
                <div class="p-4 text-xs text-gray-500">Einen Trend wählen, um Details, Quellen und Einordnung zu sehen.</div>
            @endif
        </x-foodalchemist::detail-sidebar>
    </x-slot>
</x-ui-page>
