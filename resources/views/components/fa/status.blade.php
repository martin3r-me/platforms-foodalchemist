{{-- x-fa::status — Lebenszyklus-Status als Chip. EINE Zuordnung für GP, Rezept, Gericht.
     value: Enum oder String (approved · tentative · rejected · merged · draft · review · deprecated · stub). --}}
@props(['value'])
@php
    $schluessel = $value instanceof \BackedEnum ? $value->value : (string) $value;
    [$text, $ton] = match ($schluessel) {
        'approved' => ['Freigegeben', 'ok'],
        'tentative' => ['Vorläufig', 'warn'],
        'review' => ['Prüfen', 'warn'],
        'rejected' => ['Abgelehnt', 'crit'],
        'deprecated' => ['Veraltet', 'crit'],
        'merged' => ['Zusammengeführt', 'neutral'],
        'stub' => ['Platzhalter', 'neutral'],
        'draft' => ['Entwurf', 'neutral'],
        default => [ucfirst($schluessel), 'neutral'],
    };
@endphp
<x-fa::badge :tone="$ton" {{ $attributes->merge(['data-status' => $schluessel]) }}>{{ $text }}</x-fa::badge>
