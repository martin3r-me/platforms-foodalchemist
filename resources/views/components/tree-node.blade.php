{{--
    Eine Zeile im <x-foodalchemist::tree>. Rendert Einrückung (depth), Auf-/Zuklapp-Chevron
    (nur bei Kindern), Auswahl-Highlight und einen optionalen Count-Badge. Der Inhalt (Label-
    Button + Aktionen) kommt vom Host per Default-Slot — so bleibt EINE Optik, aber jeder
    Schirm behält seine eigenen Aktionen (umbenennen/löschen/inaktiv/…).

    Sichtbarkeit: Der Knoten ist sichtbar, solange KEIN Vorfahr eingeklappt ist (`ancestors`
    = Liste der Eltern-IDs aufwärts). Auf-/Zuklapp-State lebt im umschließenden <x-…::tree>.
--}}
@props([
    'nodeId',
    'depth' => 0,
    'ancestors' => [],
    'hasChildren' => false,
    'count' => null,
    'active' => false,
])

@php
    $aktivCls = 'bg-gradient-to-r from-violet-500/10 to-indigo-500/10 text-violet-700 dark:text-violet-300';
    $hoverCls = 'text-gray-600 dark:text-gray-300 hover:bg-black/[0.03] dark:hover:bg-white/5';
@endphp

<div wire:key="tree-node-{{ $nodeId }}"
     @if(! empty($ancestors)) x-show="!hiddenBy({{ \Illuminate\Support\Js::from(array_values(array_map('intval', (array) $ancestors))) }})" @endif
     class="group flex items-center gap-1 rounded-lg text-xs {{ $active ? $aktivCls : $hoverCls }}"
     style="padding-left: {{ $depth * 12 }}px">
    @if($hasChildren)
        <button type="button" @click="toggle({{ (int) $nodeId }})"
                class="shrink-0 w-4 text-gray-400 hover:text-violet-500 text-[10px] leading-none"
                title="auf-/zuklappen">
            <span x-text="isCollapsed({{ (int) $nodeId }}) ? '▸' : '▾'">▾</span>
        </button>
    @else
        <span class="shrink-0 w-4"></span>
    @endif

    <div class="flex-1 min-w-0 flex items-center gap-1">{{ $slot }}</div>

    @if($count !== null)
        <span class="shrink-0 text-[11px] text-gray-400 ml-2 mr-1 tabular-nums">{{ $count }}</span>
    @endif
</div>
