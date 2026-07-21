{{--
    FA Hinweis-Streifen — farbige Fläche statt grauem Fließtext (Warnung/Info/…).
    Attribute (z. B. data-formel-fehlt) werden durchgereicht.
--}}
@props(['tone' => 'warning'])
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<div {{ $attributes->merge(['class' => 'rounded-lg px-3 py-2 text-[11px] leading-relaxed ' . ($alertTone[$tone] ?? $alertTone['warning'])]) }}>{{ $slot }}</div>
