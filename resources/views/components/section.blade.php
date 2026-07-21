{{--
    FA statische Panel-Sektion (NICHT ausklappbar) — Kopf mit Icon + Titel + optionalem
    Meta + Actions-Slot, Inhalt immer sichtbar darunter. Hairline-Trenner oben.
    Größere, lesbare Typo (14px Titel). Löst die Disclosure-Zeilen ab.
--}}
@props(['title', 'icon' => null, 'meta' => null])

<div {{ $attributes->merge(['class' => 'border-t border-black/5 pt-3']) }}>
    <div class="flex items-center gap-2 mb-2">
        @if($icon)<span class="text-gray-400 shrink-0">@svg($icon, 'w-4 h-4')</span>@endif
        <span class="text-sm font-medium text-gray-800">{{ $title }}</span>
        @if($meta !== null)<span class="text-xs text-gray-400">{{ $meta }}</span>@endif
        @isset($actions)<div class="ml-auto shrink-0">{{ $actions }}</div>@endisset
    </div>
    {{ $slot }}
</div>
