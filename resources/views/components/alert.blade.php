{{--
    FA Hinweis-Streifen — farbige Fläche statt grauem Fließtext (Warnung/Info/…).
    Attribute (z. B. data-formel-fehlt) werden durchgereicht.
    fa-pass (2026-10-05): Tokens (hell + Werkbank-Modus) + Symbol je Ton; Prop `tone` unverändert
    (warning · danger · info · accent · success). Für neue Hinweisflächen x-fa::notice nehmen.
--}}
@props(['tone' => 'warning'])
@php
    $alertTone = [
        'warning' => ['bg-[var(--fa-warn-soft)]', 'text-[var(--fa-warn)]', 'heroicon-m-exclamation-triangle'],
        'danger' => ['bg-[var(--fa-crit-soft)]', 'text-[var(--fa-crit)]', 'heroicon-m-exclamation-circle'],
        'info' => ['bg-[var(--fa-info-soft)]', 'text-[var(--fa-info)]', 'heroicon-m-information-circle'],
        'accent' => ['bg-[var(--fa-accent-soft)]', 'text-[var(--fa-accent)]', 'heroicon-m-sparkles'],
        'success' => ['bg-[var(--fa-ok-soft)]', 'text-[var(--fa-ok)]', 'heroicon-m-check-circle'],
    ];
    [$flaeche, $farbe, $symbol] = $alertTone[$tone] ?? $alertTone['warning'];
@endphp

<div {{ $attributes->merge(['class' => "flex items-start gap-2 rounded-[var(--fa-radius-control)] px-3 py-2 text-[length:var(--fa-text-sm)] leading-relaxed text-[var(--fa-ink)] $flaeche"]) }}>
    <span class="shrink-0 mt-px {{ $farbe }}" aria-hidden="true">@svg($symbol, 'w-4 h-4')</span>
    <div class="min-w-0 flex-1">{{ $slot }}</div>
</div>
