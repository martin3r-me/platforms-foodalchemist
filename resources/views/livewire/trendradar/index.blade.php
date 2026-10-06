{{-- Trendradar (#FA-Trendradar): gebündelte Trend-Wissens-Dokumente, kuratiert.
     LINKS Suche + Filter + Kategorie→Klasse-Baum · MITTE stärkste Trends + Liste ·
     RECHTS Detail des gewählten Trends. Nur lesend; erfasst wird im Office-Projekt.
     fa-pass 2026-10-05: auf Bausteine <x-fa::…> umgestellt. Grundanordnung unverändert.
     Neu: Signalstärke als lesbare Leiste (Zahl verwandter Signale), Reifegrad/Relevanz als Chips,
     Liste als Tabelle, keine Emoji. Funktion und wire:-Bindungen unverändert. --}}
@php
    $maturityLabels = ['niche' => 'Nische', 'emerging' => 'Im Kommen', 'mainstream' => 'Mainstream', 'declining' => 'Abklingend'];
    $relevanceLabels = ['high' => 'Hoch', 'medium' => 'Mittel', 'low' => 'Niedrig'];
    $relevanzTon = ['high' => 'ok', 'medium' => 'info', 'low' => 'neutral'];
    $reifeTon = ['niche' => 'neutral', 'emerging' => 'accent', 'mainstream' => 'info', 'declining' => 'warn'];

    // Kategorie-Code → Name aus dem Taxonomie-Baum (Kategorie-Knoten tragen die Beschreibung).
    $katName = [];
    foreach ($tree as $node) {
        if ($node->trend_class === null) {
            $katName[$node->category] = $node->description ?: $node->category;
        }
    }

    // Signalstärke = Zahl verwandter Signale im selben Bündel, relativ zum stärksten sichtbaren Trend.
    $maxSignale = max(1, (int) collect($topTrends)->max('cluster_size'), (int) $docs->max('cluster_size'));
    $staerke = fn ($n) => (int) round(min(1, max(1, (int) $n) / $maxSignale) * 100);

    $filterAktiv = $category !== '' || $trendClass !== '' || $maturity !== '' || $relevance !== '' || $onlyHype || $search !== '';
    $trefferZahl = $docs->count();
    $untertitel = number_format($trefferZahl, 0, ',', '.') . ' ' . ($trefferZahl === 1 ? 'Trend' : 'Trends')
        . ($semanticAktiv ? ' · nach Bedeutung gesucht' : '');
    $leise = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]';
@endphp

<x-ui-page>
    <x-slot:navbar>
        <x-foodalchemist::shell.page-navbar title="Trendradar" icon="heroicon-o-sparkles" />
    </x-slot:navbar>

    {{-- LINKS: Suche, Filter, Taxonomie-Baum --}}
    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Trends" width="w-80">
            <div class="p-3 flex flex-col gap-3" data-trend-filter>
                <div class="relative">
                    <label for="trend-suche" class="sr-only">Trends durchsuchen</label>
                    @svg('heroicon-m-magnifying-glass', 'w-4 h-4 absolute left-2.5 top-1/2 -translate-y-1/2 text-[var(--fa-ink-3)] pointer-events-none')
                    <x-fa::input id="trend-suche" type="search" wire:model.live.debounce.300ms="search" class="pl-8"
                        placeholder="{{ $semantic ? 'Nach Bedeutung suchen' : 'Titel oder Inhalt' }}" />
                </div>

                <label class="flex items-center gap-2 text-[length:var(--fa-text-md)] text-[var(--fa-ink-2)] cursor-pointer"
                       title="Findet auch bedeutungsähnliche Trends ohne wörtliche Übereinstimmung">
                    <input type="checkbox" wire:model.live="semantic" class="accent-[var(--fa-accent)]" /> Bedeutung einbeziehen
                </label>
                @if($semanticNote !== null)
                    <x-fa::signal tone="warn">{{ $semanticNote }}</x-fa::signal>
                @endif

                <x-fa::choice name="maturity" label="Reifegrad" idPrefix="trend" :options="['' => 'Alle'] + $maturityLabels" />
                <x-fa::choice name="relevance" label="Relevanz" idPrefix="trend" :options="['' => 'Alle'] + $relevanceLabels" />

                <label class="flex items-center gap-2 text-[length:var(--fa-text-md)] text-[var(--fa-ink-2)] cursor-pointer">
                    <input type="checkbox" wire:model.live="onlyHype" class="accent-[var(--fa-accent)]" /> Nur Hype-Themen
                </label>

                @if($filterAktiv)
                    <div>
                        <x-fa::button size="sm" variant="ghost" icon="heroicon-m-x-mark" wire:click="resetFilter">Filter zurücksetzen</x-fa::button>
                    </div>
                @endif

                {{-- Kategorie → Klasse-Baum --}}
                <div class="flex flex-col gap-0.5 pt-3 border-t border-[var(--fa-line)]">
                    <p class="px-2.5 pb-1 text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]">Kategorien</p>
                    <div class="flex flex-col gap-0.5 max-h-[46vh] overflow-y-auto -mx-1 px-1">
                        @foreach($tree as $node)
                            @if($node->trend_class === null)
                                <x-foodalchemist::filter-row wire:key="tk-{{ md5($node->category) }}" wire:click="filterAuf({{ \Illuminate\Support\Js::from($node->category) }})"
                                    :active="$category === $node->category" :child-active="$category === $node->category && $trendClass !== ''"
                                    :count="$node->doc_count">{{ $node->description ?: $node->category }}</x-foodalchemist::filter-row>
                            @else
                                <div class="ml-3 pl-2 border-l border-[var(--fa-line-strong)]" wire:key="tc-{{ md5($node->category . '|' . $node->trend_class) }}">
                                    <x-foodalchemist::filter-row level="child" wire:click="filterAuf({{ \Illuminate\Support\Js::from($node->category) }}, {{ \Illuminate\Support\Js::from($node->trend_class) }})"
                                        :active="$trendClass === $node->trend_class" :count="$node->doc_count">
                                        {{ $node->trend_class }}@if($node->status === 'tentative')<span class="ml-1.5 text-[var(--fa-warn)]" title="Von der KI vorgeschlagen, noch nicht freigegeben">vorläufig</span>@endif
                                    </x-foodalchemist::filter-row>
                                </div>
                            @endif
                        @endforeach
                    </div>
                </div>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    {{-- MITTE: stärkste Trends + Liste --}}
    <x-ui-page-container padding="px-6 py-6" spacing="space-y-5">
        <x-fa::page-header title="Trendradar" :subtitle="$untertitel" />

        <x-fa::section variant="plain" title="Stärkste Trends" icon="heroicon-o-fire" description="Nach Relevanz und Zahl verwandter Signale.">
            @if(count($topTrends) === 0)
                <div class="fa-surface">
                    <x-fa::empty icon="heroicon-o-sparkles" title="Noch keine Trends gebündelt">Sobald Trend-Dokumente zu Bündeln zusammengefasst sind, stehen hier die stärksten.</x-fa::empty>
                </div>
            @else
                <div class="grid gap-3 grid-cols-[repeat(auto-fit,minmax(min(100%,18rem),1fr))]" data-trend-top>
                    @foreach($topTrends as $t)
                        <button type="button" wire:key="top-{{ $t->slug }}" wire:click="select(@js($t->slug))"
                                class="fa-surface p-4 text-left flex flex-col gap-3 min-w-0 transition-colors hover:border-[var(--fa-accent-line)] {{ $selected && $selected->slug === $t->slug ? 'border-[var(--fa-accent)] bg-[var(--fa-accent-soft)]' : '' }}">
                            <span class="text-[length:var(--fa-text-base)] font-semibold leading-snug text-[var(--fa-ink)] break-words">{{ $t->title }}</span>
                            <span class="flex flex-col gap-1" title="{{ $t->cluster_size }} verwandte Signale">
                                <span class="flex items-center justify-between {{ $leise }}">
                                    <span>Signalstärke</span>
                                    <span class="tabular-nums text-[var(--fa-ink-2)]">{{ $t->cluster_size }} {{ $t->cluster_size === 1 ? 'Signal' : 'Signale' }}</span>
                                </span>
                                <span class="block h-1.5 rounded-full bg-[var(--fa-neutral-soft)] overflow-hidden">
                                    <span class="block h-full rounded-full bg-[var(--fa-accent)]" style="width: {{ $staerke($t->cluster_size) }}%"></span>
                                </span>
                            </span>
                            <span class="flex flex-wrap gap-1.5">
                                @if($t->category)<x-fa::badge tone="accent">{{ $katName[$t->category] ?? $t->category }}</x-fa::badge>@endif
                                @if($t->trend_class)<x-fa::badge>{{ $t->trend_class }}</x-fa::badge>@endif
                                @if($t->maturity)<x-fa::badge :tone="$reifeTon[$t->maturity] ?? 'neutral'">{{ $maturityLabels[$t->maturity] ?? $t->maturity }}</x-fa::badge>@endif
                                @if($t->is_hype)<x-fa::badge tone="warn" icon="heroicon-m-fire">Hype</x-fa::badge>@endif
                            </span>
                        </button>
                    @endforeach
                </div>
            @endif
        </x-fa::section>

        {{-- Gefilterte Liste --}}
        <x-fa::section variant="plain" title="Alle Trends" icon="heroicon-o-list-bullet">
            <div class="fa-surface overflow-hidden">
                <div class="max-h-[70vh] overflow-auto">
                    <table class="fa-table">
                        <thead class="sticky top-0 z-10 bg-[var(--fa-surface)]">
                            <tr>
                                <th class="w-full">Trend</th>
                                <th>Reifegrad</th>
                                <th>Relevanz</th>
                                <th class="num">Signale</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($docs as $doc)
                                <x-foodalchemist::table-row :active="$selected && $selected->slug === $doc->slug" wire:key="row-{{ $doc->slug }}" wire:click="select({{ \Illuminate\Support\Js::from($doc->slug) }})"
                                    x-data x-on:click="$store.ui?.mSet('activity_trendradar', 'open', true)">
                                    <td class="min-w-[14rem]">
                                        <span class="block font-medium text-[var(--fa-ink)] break-words">{{ $doc->title }}</span>
                                        <span class="mt-1 flex flex-wrap items-center gap-1.5">
                                            @if($doc->category)<span class="{{ $leise }}">{{ $katName[$doc->category] ?? $doc->category }}@if($doc->trend_class) · {{ $doc->trend_class }}@endif</span>@endif
                                            @if($doc->is_hype)<x-fa::badge tone="warn" icon="heroicon-m-fire">Hype</x-fa::badge>@endif
                                            @if($doc->status === 'tentative')<x-fa::status value="tentative" />@endif
                                            @if(! $doc->category)<x-fa::badge>Noch nicht gebündelt</x-fa::badge>@endif
                                        </span>
                                    </td>
                                    <td class="whitespace-nowrap">
                                        @if($doc->maturity)<x-fa::badge :tone="$reifeTon[$doc->maturity] ?? 'neutral'">{{ $maturityLabels[$doc->maturity] ?? $doc->maturity }}</x-fa::badge>@else<span class="text-[var(--fa-ink-3)]">–</span>@endif
                                    </td>
                                    <td class="whitespace-nowrap">
                                        @if($doc->relevance)<x-fa::badge :tone="$relevanzTon[$doc->relevance] ?? 'neutral'">{{ $relevanceLabels[$doc->relevance] ?? $doc->relevance }}</x-fa::badge>@else<span class="text-[var(--fa-ink-3)]">–</span>@endif
                                    </td>
                                    <td class="num">
                                        <span class="inline-flex items-center gap-2" title="{{ $doc->cluster_size }} verwandte Signale">
                                            <span class="hidden xl:block w-16 h-1.5 rounded-full bg-[var(--fa-neutral-soft)] overflow-hidden">
                                                <span class="block h-full rounded-full bg-[var(--fa-accent)]" style="width: {{ $staerke($doc->cluster_size) }}%"></span>
                                            </span>
                                            {{ $doc->cluster_size }}
                                        </span>
                                    </td>
                                </x-foodalchemist::table-row>
                            @empty
                                <tr>
                                    <td colspan="4">
                                        <x-fa::empty icon="heroicon-o-funnel" title="Keine Trends für diese Filter">
                                            Filter lockern oder zurücksetzen.
                                            @if($filterAktiv)
                                                <x-slot:action><x-fa::button size="sm" wire:click="resetFilter">Filter zurücksetzen</x-fa::button></x-slot:action>
                                            @endif
                                        </x-fa::empty>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </x-fa::section>
    </x-ui-page-container>

    {{-- RECHTS: Detail --}}
    <x-slot name="activity">
        <x-foodalchemist::detail-sidebar title="Trend" width="w-96" :maxWidth="760" scope="activity_trendradar" side="right">
            {{-- Anatomie Detail-Panels (DESIGN.md): Kopf, Kennzahlen, offene Punkte, Inhalt, Quellen. --}}
            @if($selected)
                @php
                    // Signale = verwandte Trends im selben Bündel (wie die Leiste in der Liste); nur Anzeige.
                    $trendZeile = $docs->firstWhere('slug', $selected->slug) ?? collect($topTrends)->firstWhere('slug', $selected->slug);
                    $trendSignale = $trendZeile->cluster_size ?? null;
                    $trendKpis = [];
                    if ($trendSignale !== null) {
                        $trendKpis[] = ['label' => 'Signale', 'value' => (string) $trendSignale, 'primary' => true, 'title' => 'Zahl verwandter Signale im selben Bündel', 'kpi' => 'signale'];
                    }
                    if ($selected->maturity) {
                        $trendKpis[] = ['label' => 'Reifegrad', 'value' => $maturityLabels[$selected->maturity] ?? $selected->maturity, 'primary' => $trendKpis === [], 'kpi' => 'reifegrad'];
                    }
                    if ($selected->relevance) {
                        $trendKpis[] = ['label' => 'Relevanz', 'value' => $relevanceLabels[$selected->relevance] ?? $selected->relevance, 'kpi' => 'relevanz'];
                    }
                @endphp
                <div class="p-4 flex flex-col gap-5 min-h-full bg-[var(--fa-ground)]" data-trend-detail>
                    <x-fa::detail-kopf :title="$selected->title" :subtitle="$selected->category ? ($katName[$selected->category] ?? $selected->category) : null">
                        <x-slot:badges>
                            @if($selected->status === 'tentative')<x-fa::status value="tentative" />@endif
                            @if($selected->trend_class)<x-fa::badge>{{ $selected->trend_class }}</x-fa::badge>@endif
                            @if($selected->is_hype)<x-fa::badge tone="warn" icon="heroicon-m-fire">Hype</x-fa::badge>@endif
                        </x-slot:badges>
                        <x-slot:aktion>
                            <x-fa::button variant="primary" size="sm" icon="heroicon-o-light-bulb" wire:click="inPlanungOeffnen">In Planung öffnen</x-fa::button>
                        </x-slot:aktion>
                        <x-slot:menue>
                            <x-fa::menu-item icon="heroicon-m-x-mark" wire:click="deselect">Auswahl schließen</x-fa::menu-item>
                        </x-slot:menue>
                    </x-fa::detail-kopf>

                    @if($trendKpis !== [])
                        <x-fa::kpis :items="$trendKpis" />
                    @endif

                    @if($selected->status === 'tentative')
                        <x-fa::signal tone="warn">Noch nicht kuratiert: vor der Verwendung prüfen.</x-fa::signal>
                    @endif

                    <div class="flex flex-col">
                        <x-fa::section variant="plain" title="Inhalt" icon="heroicon-o-document-text" meta="Version {{ $selected->version }}">
                            <div class="text-[length:var(--fa-text-md)] leading-relaxed text-[var(--fa-ink)] [&_h1]:text-[length:var(--fa-text-lg)] [&_h1]:font-semibold [&_h1]:mt-4 [&_h1]:mb-1.5 [&_h2]:text-[length:var(--fa-text-base)] [&_h2]:font-semibold [&_h2]:mt-4 [&_h2]:mb-1 [&_h3]:font-semibold [&_h3]:mt-3 [&_p]:my-2 [&_ul]:list-disc [&_ul]:pl-5 [&_ul]:my-2 [&_ol]:list-decimal [&_ol]:pl-5 [&_ol]:my-2 [&_li]:my-0.5 [&_a]:text-[var(--fa-accent)] [&_a]:underline [&_blockquote]:border-l-[3px] [&_blockquote]:border-[var(--fa-accent-line)] [&_blockquote]:pl-3 [&_blockquote]:text-[var(--fa-ink-2)] [&>*:first-child]:mt-0">
                                {!! $selectedHtml !!}
                            </div>
                            <p class="{{ $leise }} break-all">Kennung <span class="font-mono text-[var(--fa-ink-2)]">{{ $selected->slug }}</span></p>
                        </x-fa::section>

                        @if(count($selectedQuellen) > 0)
                            <x-fa::section variant="plain" title="Quellen" icon="heroicon-o-link" :meta="count($selectedQuellen)">
                                <ul class="flex flex-col gap-1 text-[length:var(--fa-text-md)] text-[var(--fa-ink-2)]">
                                    @foreach($selectedQuellen as $q)
                                        <li class="break-words">{{ $q }}</li>
                                    @endforeach
                                </ul>
                            </x-fa::section>
                        @endif
                    </div>
                </div>
            @else
                <div class="p-4 flex flex-col gap-5 min-h-full bg-[var(--fa-ground)]">
                    <x-fa::empty icon="heroicon-o-cursor-arrow-rays" title="Kein Trend gewählt">Einen Trend anklicken, um Inhalt, Quellen und Einordnung zu sehen.</x-fa::empty>
                </div>
            @endif
        </x-foodalchemist::detail-sidebar>
    </x-slot>
    {{-- Spec 53/F Stufe 2: Sprachbefehl-Mount auf Seitenebene (Modal + optionales schwebendes Element). --}}
</x-ui-page>
