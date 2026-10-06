{{-- x-fa::choice — Auswahl aus 2–6 Optionen als Chips (statt Dropdown).
     name: Livewire-Property (wire:model wird automatisch gesetzt, live per :live="true").
     multiple: Mehrfachauswahl (Checkboxen) statt Einfach (Radio).
     options: [wert => label]. Ab ~7 Optionen lieber x-fa::select.
     idPrefix: nötig, wenn dieselbe Property zweimal auf einer Seite gebunden wird (eindeutige ids). --}}
@props(['name', 'options' => [], 'multiple' => false, 'live' => true, 'label' => null, 'idPrefix' => null])
@php($modell = $live ? 'wire:model.live' : 'wire:model')
<fieldset {{ $attributes->merge(['class' => 'min-w-0']) }}>
    @if($label)<legend class="mb-1.5 text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]">{{ $label }}</legend>@endif
    <div class="flex flex-wrap gap-1.5">
        @foreach($options as $wert => $text)
            @php($id = 'fa-choice-' . \Illuminate\Support\Str::slug(($idPrefix ? $idPrefix . '-' : '') . $name . '-' . $wert))
            <label for="{{ $id }}" class="fa-chip" wire:key="{{ $id }}">
                <input id="{{ $id }}" type="{{ $multiple ? 'checkbox' : 'radio' }}" value="{{ $wert }}" name="{{ ($idPrefix ? $idPrefix . '-' : '') . $name }}{{ $multiple ? '[]' : '' }}" {{ $modell }}="{{ $name }}" class="sr-only peer">
                <span>{{ $text }}</span>
            </label>
        @endforeach
    </div>
</fieldset>
