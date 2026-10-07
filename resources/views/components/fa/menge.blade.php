{{-- x-fa::menge — Menge + Einheit, ohne überflüssige Nullen (3,5 kg · 500 ml · 1,330 l → 1,33 l). --}}
@props(['value' => null, 'unit' => '', 'decimals' => 3])
@php($text = $value === null ? '–' : ($decimals > 0 ? rtrim(rtrim(number_format((float) $value, $decimals, ',', '.'), '0'), ',') : number_format((float) $value, 0, ',', '.')))
<span {{ $attributes->merge(['class' => 'tabular-nums whitespace-nowrap']) }}>{{ $text }}@if($unit !== '' && $value !== null)&nbsp;<span class="text-[var(--fa-ink-3)]">{{ $unit }}</span>@endif</span>
