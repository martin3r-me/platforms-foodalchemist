{{--
    Eine Zeile im <x-foodalchemist::tree>. Rendert Einrückung (depth), Auf-/Zuklapp-Chevron
    (nur bei Kindern), Auswahl-Highlight und einen optionalen Count-Badge. Der Inhalt (Label-
    Button + Aktionen) kommt vom Host per Default-Slot — so bleibt EINE Optik, aber jeder
    Schirm behält seine eigenen Aktionen (umbenennen/löschen/inaktiv/…).

    fa-pass (2026-10-05): Tokens, Heroicon-Chevron statt ▸/▾; Props, x-show und wire:key unverändert.

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
    $aktivCls = 'bg-[var(--fa-accent-soft)] text-[var(--fa-accent)] font-medium';
    $hoverCls = 'text-[var(--fa-ink-2)] hover:bg-[var(--fa-hover)] hover:text-[var(--fa-ink)]';
@endphp

<div wire:key="tree-node-{{ $nodeId }}"
     @if(! empty($ancestors)) x-show="!hiddenBy({{ \Illuminate\Support\Js::from(array_values(array_map('intval', (array) $ancestors))) }})" @endif
     class="group flex items-center gap-1 min-h-8 rounded-[var(--fa-radius-control)] text-[length:var(--fa-text-md)] {{ $active ? $aktivCls : $hoverCls }}"
     style="padding-left: {{ $depth * 12 }}px">
    @if($hasChildren)
        <button type="button" @click="toggle({{ (int) $nodeId }})"
                class="shrink-0 grid place-items-center w-5 h-5 rounded-[var(--fa-radius-control)] text-[var(--fa-ink-3)] hover:text-[var(--fa-accent)] hover:bg-[var(--fa-hover)]"
                x-bind:aria-expanded="! isCollapsed({{ (int) $nodeId }})" aria-label="Auf- oder zuklappen" title="Auf- oder zuklappen">
            <span class="transition-transform" x-bind:class="isCollapsed({{ (int) $nodeId }}) ? '-rotate-90' : ''">@svg('heroicon-m-chevron-down', 'w-3.5 h-3.5')</span>
        </button>
    @else
        <span class="shrink-0 w-5"></span>
    @endif

    <div class="flex-1 min-w-0 flex items-center gap-1">{{ $slot }}</div>

    @if($count !== null)
        <span class="shrink-0 ml-2 mr-1.5 text-[length:var(--fa-text-sm)] tabular-nums {{ $active ? 'text-[var(--fa-accent)]' : 'text-[var(--fa-ink-3)]' }}">{{ $count }}</span>
    @endif
</div>
