{{-- x-fa::icon-button — Knopf nur mit Symbol. label ist Pflicht (Screenreader + Tooltip). --}}
@props(['icon', 'label', 'size' => 'md', 'tone' => 'neutral', 'href' => null])
@php
    $groesse = $size === 'sm' ? 'w-7 h-7' : 'w-9 h-9';
    $farbe = $tone === 'danger'
        ? 'text-[var(--fa-crit)] hover:bg-[var(--fa-crit-soft)]'
        : 'text-[var(--fa-ink-3)] hover:text-[var(--fa-ink)] hover:bg-[var(--fa-hover)]';
    $klasse = "inline-flex items-center justify-center shrink-0 rounded-[var(--fa-radius-control)] transition-colors duration-150 $groesse $farbe";
@endphp
@if($href)
    <a href="{{ $href }}" aria-label="{{ $label }}" title="{{ $label }}" {{ $attributes->merge(['class' => $klasse]) }}>@svg($icon, $size === 'sm' ? 'w-4 h-4' : 'w-[18px] h-[18px]')</a>
@else
    <button type="button" aria-label="{{ $label }}" title="{{ $label }}" {{ $attributes->merge(['class' => $klasse]) }}>@svg($icon, $size === 'sm' ? 'w-4 h-4' : 'w-[18px] h-[18px]')</button>
@endif
