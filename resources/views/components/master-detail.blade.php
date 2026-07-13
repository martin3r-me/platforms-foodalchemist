{{--
    M0-07 / P-1: Master-Detail-Dreiklang — wiederverwendbarer 3-Zonen-Baustein.

    ┌──────────┬──────────────────────────────┬───────────────────┐
    │ tree     │ Default-Slot (dichte Tabelle)│ panel (kollabier- │
    │ (Filter- │ Zeilen-Klick = Panel-Update  │  bar, Sektionen)  │
    │  Baum)   │ KEIN Seitenwechsel           │                   │
    └──────────┴──────────────────────────────┴───────────────────┘

    Nutzung:
        <x-foodalchemist::master-detail height="h-[calc(100vh-14rem)]">
            <x-slot:tree>…Baum/Filter…</x-slot:tree>
            …Tabelle…
            <x-slot:panel>…Detail-Sektionen (eigene Livewire-Komponente, P-1)…</x-slot:panel>
        </x-foodalchemist::master-detail>

    - tree-Slot optional (M3-01 darf den Baum stattdessen in x-ui-page-sidebar legen).
    - panel-Slot optional; kollabierbar (Alpine), eingeklappt bleibt eine schmale
      Rail mit Aufklapp-Pfeil sichtbar — Tabelle gewinnt die Breite.
    - Optik: frosted Cards nach DESIGN.md (Linear/Raycast), Dichte wie GP-Slice.
--}}
@props([
    'treeWidth' => 'w-64',
    'panelWidth' => 'w-96',
    'panelOpen' => true,
    'height' => 'h-full min-h-96',
])

@php($card = \Platform\FoodAlchemist\Support\Ui::maps()['card']) {{-- M0-12: zentrale Map --}}

<div {{ $attributes->merge(['class' => "flex gap-4 {$height}"]) }}
     x-data="{ panelOpen: {{ $panelOpen ? 'true' : 'false' }} }">

    {{-- Zone 1: Filter-Baum (Counts je Knoten, Suche/Status darüber) --}}
    @isset($tree)
        <aside class="{{ $treeWidth }} shrink-0 overflow-y-auto {{ $card }}" data-zone="tree">
            {{ $tree }}
        </aside>
    @endisset

    {{-- Zone 2: dichte Tabelle — flex-1, eigener Scroll, min-w-0 gegen Overflow-Sprengung --}}
    <section class="flex-1 min-w-0 overflow-auto relative {{ $card }}" data-zone="table">
        <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-violet-500/50 to-transparent"></div>
        {{ $slot }}
    </section>

    {{-- Zone 3: Detail-Panel, kollabierbar --}}
    @isset($panel)
        <aside x-show="panelOpen" x-cloak
               class="{{ $panelWidth }} shrink-0 flex flex-col {{ $card }}" data-zone="panel">
            <div class="shrink-0 flex items-center justify-end px-2 pt-2">
                <button type="button" @click="panelOpen = false"
                        class="p-1 rounded-md text-gray-500 dark:text-gray-400 hover:text-violet-600 dark:hover:text-violet-400 hover:bg-black/5 dark:hover:bg-white/10 transition-colors duration-150"
                        title="Detail-Panel einklappen">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                    </svg>
                </button>
            </div>
            <div class="flex-1 overflow-y-auto">
                {{ $panel }}
            </div>
        </aside>

        {{-- eingeklappt: schmale Rail zum Wieder-Aufklappen --}}
        <button type="button" x-show="!panelOpen" x-cloak @click="panelOpen = true"
                class="w-8 shrink-0 flex items-start justify-center pt-2 {{ $card }} text-gray-500 dark:text-gray-400 hover:text-violet-600 dark:hover:text-violet-400 transition-colors duration-150"
                title="Detail-Panel ausklappen" data-zone="panel-rail">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
            </svg>
        </button>
    @endisset
</div>
