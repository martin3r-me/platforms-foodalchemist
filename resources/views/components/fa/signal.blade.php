{{-- x-fa::signal — Hinweis im Fließtext/Zelle: Symbol + kurzer Satz. tone: ok · warn · crit · info.
     Für ganze Hinweisflächen x-fa::notice nehmen. --}}
@props(['tone' => 'warn', 'icon' => null])
@php
    $farbe = ['ok' => 'text-[var(--fa-ok)]', 'warn' => 'text-[var(--fa-warn)]', 'crit' => 'text-[var(--fa-crit)]', 'info' => 'text-[var(--fa-info)]'][$tone] ?? 'text-[var(--fa-ink-2)]';
    $symbol = $icon ?? ['ok' => 'heroicon-m-check-circle', 'warn' => 'heroicon-m-exclamation-triangle', 'crit' => 'heroicon-m-exclamation-circle', 'info' => 'heroicon-m-information-circle'][$tone] ?? 'heroicon-m-information-circle';
@endphp
<span {{ $attributes->merge(['class' => "inline-flex items-center gap-1 text-[length:var(--fa-text-sm)] font-medium $farbe"]) }}>@svg($symbol, 'w-4 h-4 shrink-0'){{ $slot }}</span>
