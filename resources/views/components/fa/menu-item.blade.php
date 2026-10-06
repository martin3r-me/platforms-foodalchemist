{{-- x-fa::menu-item — Eintrag in einem faMenu-Menü („Weitere Aktionen", Status …).
     href → Link (target etc. über Attribute), sonst Knopf (wire:click, wire:confirm über Attribute). danger → rot, mit Trennlinie davor. --}}
@props(['icon' => null, 'href' => null, 'danger' => false])
@php
    $klasse = 'flex w-full items-center gap-2 px-3 py-1.5 text-left text-[length:var(--fa-text-md)] hover:bg-[var(--fa-hover)] '
        . ($danger ? 'text-[var(--fa-crit)] border-t border-[var(--fa-line)] mt-1 pt-2' : 'text-[var(--fa-ink)]');
    $iconKlasse = 'w-4 h-4 shrink-0 ' . ($danger ? 'text-[var(--fa-crit)]' : 'text-[var(--fa-ink-3)]');
@endphp
@if($href)
    <a href="{{ $href }}" role="menuitem" {{ $attributes->merge(['class' => $klasse]) }}>@if($icon)@svg($icon, $iconKlasse)@endif{{ $slot }}</a>
@else
    <button type="button" role="menuitem" {{ $attributes->merge(['class' => $klasse]) }}>@if($icon)@svg($icon, $iconKlasse)@endif{{ $slot }}</button>
@endif
