{{-- x-fa::select — Auswahlliste. Optionen als Slot (<option>) ODER :options="[wert => label]" + placeholder. --}}
@props(['options' => null, 'placeholder' => null, 'size' => 'md'])
<select {{ $attributes->merge(['class' => 'fa-control fa-select pr-8 ' . ($size === 'sm' ? 'h-7 text-[length:var(--fa-text-sm)]' : 'h-9 text-[length:var(--fa-text-md)]')]) }}>
    @if($placeholder !== null)<option value="">{{ $placeholder }}</option>@endif
    @if(is_iterable($options))
        @foreach($options as $wert => $text)<option value="{{ $wert }}">{{ $text }}</option>@endforeach
    @endif
    {{ $slot }}
</select>
