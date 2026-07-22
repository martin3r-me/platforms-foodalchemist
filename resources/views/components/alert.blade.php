{{--
    FA Hinweis-Streifen — farbige Fläche statt grauem Fließtext (Warnung/Info/…).
    Attribute (z. B. data-formel-fehlt) werden durchgereicht.
--}}
{{-- Tönungs-Map inline (keine Ui.php-Token, keine neuen CSS-Klassen — alle bereits gebaut). --}}
@props(['tone' => 'warning'])
@php($alertTone = [
    'warning' => 'bg-amber-500/10 text-amber-700',
    'danger' => 'bg-rose-500/10 text-rose-700',
    'info' => 'bg-sky-500/10 text-sky-700',
    'accent' => 'bg-violet-500/10 text-violet-700',
    'success' => 'bg-emerald-500/10 text-emerald-700',
])

<div {{ $attributes->merge(['class' => 'rounded-lg px-3 py-2 text-[11px] leading-relaxed ' . ($alertTone[$tone] ?? $alertTone['warning'])]) }}>{{ $slot }}</div>
