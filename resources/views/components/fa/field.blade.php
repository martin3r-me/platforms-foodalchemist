{{-- x-fa::field — Label über dem Feld, Hilfetext darunter, Fehler darunter in Rot.
     for: id des Eingabefelds. error: Livewire-Feldname (zeigt $errors) ODER direkter Text. --}}
@props(['label' => null, 'for' => null, 'hint' => null, 'error' => null, 'required' => false, 'optional' => false])
@php
    // error = Livewire-Feldname → Meldung aus $errors; sonst direkter Text.
    $fehlerText = null;
    if ($error) {
        $fehlerText = (isset($errors) && $errors->has($error)) ? $errors->first($error) : (str_contains($error, ' ') ? $error : null);
    }
@endphp
<div {{ $attributes->merge(['class' => 'flex flex-col gap-1.5 min-w-0']) }}>
    @if($label)
        <label @if($for) for="{{ $for }}" @endif class="text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]">
            {{ $label }}@if($required)<span class="text-[var(--fa-crit)]" aria-hidden="true"> *</span>@endif
            @if($optional)<span class="font-normal text-[var(--fa-ink-3)]"> (optional)</span>@endif
        </label>
    @endif
    {{ $slot }}
    @if($fehlerText)
        <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-crit)]" role="alert">{{ $fehlerText }}</p>
    @elseif($hint)
        <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">{{ $hint }}</p>
    @endif
</div>
