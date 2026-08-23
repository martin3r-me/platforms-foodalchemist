{{--
    Geteilte Kandidaten-Zeile für Katalog-Picker (Foodbook · Speisekarte · später Produktion/Basisrezept).
    Haus-Muster aus produktion/editor-ziele + recipes/zutaten-kern: `+`-Einfüge-Marker (violett),
    Wording bricht UM statt abzuschneiden (break-words leading-snug), optionaler Preis rechts.

    Klick/Key kommen vom Aufrufer als Attribute (wire:click/@click/wire:key) und werden gemerged.
    Label als Default-Slot (erlaubt Inline-Zusatz wie „(Kunde-IP)"). `disabled` → grau + nicht klickbar.

    Klassen literal (KEINE dynamischen `bg-{x}`-Strings — Tailwind scannt nur statische Klassen, README §234).
--}}
@props(['title' => null, 'price' => null, 'disabled' => false])
@php
    $rowClass = 'group w-full flex items-start gap-1.5 px-2 py-1.5 rounded-lg text-xs text-left '
        . ($disabled ? 'opacity-40 cursor-not-allowed' : 'hover:bg-violet-500/10');
@endphp
<button type="button" @disabled($disabled) {{ $attributes->merge(['class' => $rowClass]) }}>
    <span class="shrink-0 font-semibold leading-snug {{ $disabled ? 'text-gray-400' : 'text-violet-500' }}">+</span>
    <span class="min-w-0 flex-1 break-words leading-snug {{ $disabled ? 'text-gray-500' : 'text-gray-800' }}" @if($title) title="{{ $title }}" @endif>{{ $slot }}</span>
    @if($price !== null && $price !== '')<span class="shrink-0 leading-snug tabular-nums text-gray-500">{{ $price }}</span>@endif
</button>
