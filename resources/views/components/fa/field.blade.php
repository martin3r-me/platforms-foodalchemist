{{-- x-fa::field — Label über dem Feld, Hilfetext darunter, Fehler darunter in Rot.
     for: id des Eingabefelds. error: Livewire-Feldname (zeigt $errors) ODER direkter Text.
     einklappen (GP-Formular, Dominique 26.10.): Hilfetext standardmäßig zu, ⓘ neben dem Label klappt ihn auf und zu (button mit aria-expanded/aria-controls,
     Text bleibt im DOM). Braucht `for` (stabile id für aria-controls); ohne `for` bleibt der Hilfetext offen wie bisher. --}}
@props(['label' => null, 'for' => null, 'hint' => null, 'error' => null, 'required' => false, 'optional' => false, 'einklappen' => false])
@php
    // error = Livewire-Feldname → Meldung aus $errors; sonst direkter Text.
    $fehlerText = null;
    if ($error) {
        $fehlerText = (isset($errors) && $errors->has($error)) ? $errors->first($error) : (str_contains($error, ' ') ? $error : null);
    }
    $hinweisId = $einklappen && $hint && $for ? 'hinweis-' . $for : null;
@endphp
<div {{ $attributes->merge(['class' => 'flex flex-col gap-1.5 min-w-0']) }} @if($hinweisId) x-data="{ hinweisOffen: false }" @endif>
    @if($label)
        <div class="flex items-center gap-1.5 min-w-0">
            <label @if($for) for="{{ $for }}" @endif class="text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]">
                {{ $label }}@if($required)<span class="text-[var(--fa-crit)]" aria-hidden="true"> *</span>@endif
                @if($optional)<span class="font-normal text-[var(--fa-ink-3)]"> (optional)</span>@endif
            </label>
            @if($hinweisId)
                <button type="button" x-on:click="hinweisOffen = ! hinweisOffen" aria-expanded="false" x-bind:aria-expanded="hinweisOffen ? 'true' : 'false'"
                        aria-controls="{{ $hinweisId }}" aria-label="Hilfe zu {{ $label }}" data-fa-field-info="{{ $for }}"
                        class="inline-flex items-center justify-center w-5 h-5 rounded-full text-[var(--fa-ink-3)] hover:text-[var(--fa-accent)] hover:bg-[var(--fa-accent-soft)]"
                        x-bind:class="hinweisOffen ? 'text-[var(--fa-accent)]' : ''">@svg('heroicon-o-information-circle', 'w-4 h-4')</button>
            @endif
        </div>
    @endif
    {{ $slot }}
    @if($fehlerText)
        <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-crit)]" role="alert">{{ $fehlerText }}</p>
    @elseif($hint)
        <p @if($hinweisId) id="{{ $hinweisId }}" x-show="hinweisOffen" x-cloak data-fa-field-hinweis="{{ $for }}" @endif class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">{{ $hint }}</p>
    @endif
</div>
