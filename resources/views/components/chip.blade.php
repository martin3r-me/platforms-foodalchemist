{{--
    FA Chip — einheitlicher Pill über die variantPill-Tönung (danger/warning/success/
    secondary/info/primary). Damit Chips über alle Panels identisch aussehen.
--}}
@props(['tone' => 'secondary'])
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<span {{ $attributes->merge(['class' => $pill . ' ' . ($variantPill[$tone] ?? $variantPill['secondary'])]) }}>{{ $slot }}</span>
