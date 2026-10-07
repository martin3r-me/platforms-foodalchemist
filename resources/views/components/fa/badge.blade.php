{{-- x-fa::badge — kleines Etikett. tone: neutral · accent · ok · warn · crit · info. icon optional. --}}
@props(['tone' => 'neutral', 'icon' => null])
@php
    $farbe = match ($tone) {
        'accent' => 'bg-[var(--fa-accent-soft)] text-[var(--fa-accent)]',
        'ok' => 'bg-[var(--fa-ok-soft)] text-[var(--fa-ok)]',
        'warn' => 'bg-[var(--fa-warn-soft)] text-[var(--fa-warn)]',
        'crit' => 'bg-[var(--fa-crit-soft)] text-[var(--fa-crit)]',
        'info' => 'bg-[var(--fa-info-soft)] text-[var(--fa-info)]',
        default => 'bg-[var(--fa-neutral-soft)] text-[var(--fa-ink-2)]',
    };
@endphp
<span {{ $attributes->merge(['class' => "inline-flex items-center gap-1 h-[22px] px-2 rounded-full text-[length:var(--fa-text-sm)] font-medium whitespace-nowrap $farbe"]) }}>
    @if($icon)@svg($icon, 'w-3.5 h-3.5 shrink-0 -ml-0.5')@endif{{ $slot }}
</span>
