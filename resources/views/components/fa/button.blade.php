{{-- x-fa::button — EIN Knopf für die ganze App.
     variant: primary (eine Hauptaktion je Fläche) · secondary · ghost · danger · ai
     size: md (36 px) · sm (28 px). href → rendert <a>. icon: Heroicon-Name. --}}
@props([
    'variant' => 'secondary',
    'size' => 'md',
    'icon' => null,
    'iconRight' => null,
    'href' => null,
    'type' => 'button',
])
@php
    $basis = 'fa-btn inline-flex items-center justify-center gap-1.5 whitespace-nowrap font-medium rounded-[var(--fa-radius-control)] transition-colors duration-150 disabled:opacity-50 disabled:pointer-events-none';
    $groesse = $size === 'sm' ? 'h-7 px-2.5 text-[length:var(--fa-text-sm)]' : 'h-9 px-3.5 text-[length:var(--fa-text-md)]';
    $art = match ($variant) {
        'primary' => 'bg-[var(--fa-accent)] text-[var(--fa-on-accent)] hover:bg-[var(--fa-accent-hover)]',
        'ghost' => 'bg-transparent text-[var(--fa-ink-2)] hover:bg-[var(--fa-hover)] hover:text-[var(--fa-ink)]',
        'danger' => 'bg-[var(--fa-surface)] text-[var(--fa-crit)] border border-[var(--fa-crit-line)] hover:bg-[var(--fa-crit-soft)]',
        'ai' => 'bg-[var(--fa-accent-soft)] text-[var(--fa-accent)] border border-[var(--fa-accent-line)] hover:bg-[var(--fa-accent-soft-hover)]',
        default => 'bg-[var(--fa-surface)] text-[var(--fa-ink)] border border-[var(--fa-line-strong)] hover:bg-[var(--fa-hover)]',
    };
    $iconKlasse = $size === 'sm' ? 'w-3.5 h-3.5 shrink-0' : 'w-4 h-4 shrink-0';
@endphp
@if($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => "$basis $groesse $art"]) }}>
        @if($icon)@svg($icon, $iconKlasse)@endif{{ $slot }}@if($iconRight)@svg($iconRight, $iconKlasse)@endif
    </a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => "$basis $groesse $art"]) }}>
        @if($icon)@svg($icon, $iconKlasse)@endif{{ $slot }}@if($iconRight)@svg($iconRight, $iconKlasse)@endif
    </button>
@endif
