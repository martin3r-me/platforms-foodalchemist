{{-- x-fa::input — Textfeld. numeric: rechtsbündig + gleiche Ziffernbreite. Alle Attribute (wire:model …) gehen durch. --}}
@props(['numeric' => false, 'size' => 'md'])
<input {{ $attributes->merge(['type' => 'text', 'class' => 'fa-control ' . ($size === 'sm' ? 'h-7 text-[length:var(--fa-text-sm)]' : 'h-9 text-[length:var(--fa-text-md)]') . ($numeric ? ' text-right tabular-nums' : '')]) }}>
