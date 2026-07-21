{{--
    FA Signal-Kachel für die Detail-Panels — kräftige Tönung je Zustand (tone),
    großer Wert bei hero. Löst die handgemalten bg-black/[0.03]-Kacheln ab.
    Farbe kodiert Zustand: neutral · accent (Hero-Kennzahl) · success/warning/danger (Ampel) · info.
    Attribute (z. B. data-vk-brutto) werden auf die äußere Fläche durchgereicht.
    Ohne value-Prop wird der Slot als Inhalt gerendert (z. B. Score-Badge).
--}}
{{-- Kein extract() der Ui-Maps: deren 'label'-Token würde die label-Prop überschreiben. --}}
@props(['label', 'value' => null, 'tone' => 'neutral', 'hero' => false])
@php($kpiTone = \Platform\FoodAlchemist\Support\Ui::maps()['kpiTone'])
@php($t = $kpiTone[$tone] ?? $kpiTone['neutral'])

<div {{ $attributes->merge(['class' => 'rounded-lg px-3 py-2 ' . $t['bg']]) }}>
    <span class="block text-[10px] font-medium uppercase tracking-wider {{ $t['label'] }}">{{ $label }}</span>
    @if($value !== null)
        <p class="{{ $hero ? 'text-base font-bold' : 'text-xs font-semibold' }} tabular-nums {{ $t['value'] }}">{{ $value }}</p>
    @else
        {{ $slot }}
    @endif
</div>
