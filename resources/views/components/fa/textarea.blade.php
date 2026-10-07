{{-- x-fa::textarea — mehrzeiliges Feld, wächst nicht von selbst. --}}
@props(['rows' => 3])
<textarea rows="{{ $rows }}" {{ $attributes->merge(['class' => 'fa-control py-2 text-[length:var(--fa-text-md)] leading-relaxed']) }}>{{ $slot }}</textarea>
