{{-- x-fa::money — Geldbetrag, deutsch formatiert, gleiche Ziffernbreite.
     value null → Signal „Preis fehlt" (der FA schätzt nie). per: Einheit-Suffix, z. B. "kg". --}}
@props(['value' => null, 'per' => null, 'decimals' => 2, 'missing' => 'Preis fehlt'])
@if($value === null || $value === '')
    <x-fa::badge tone="crit" {{ $attributes }}>{{ $missing }}</x-fa::badge>
@else
    <span {{ $attributes->merge(['class' => 'tabular-nums whitespace-nowrap']) }}>{{ number_format((float) $value, $decimals, ',', '.') }}&nbsp;€@if($per)<span class="text-[var(--fa-ink-3)]">/{{ $per }}</span>@endif</span>
@endif
