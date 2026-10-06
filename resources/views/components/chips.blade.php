{{--
    M0-11 / P-5: Chip-Editor — Anker, Tags, Pairing, Eignungen.
    fa-pass (2026-10-05): Tokens (hell + Werkbank-Modus), Heroicon statt ×; Props/Alpine-Logik unverändert.

    Chips mit ×-Remove + „+ manuell…"-Add (Input mit Datalist/Combobox gegen das
    Vokabular-Array; Enter fügt hinzu, Duplikate werden ignoriert). Optional ★-Prefix
    (Kern-Anker). Rein clientseitig (Alpine), EIN Binding aufs Array — Sync deferred
    mit dem nächsten Livewire-Request (P-8). Kombiniert sich mit dem P-3-ki-header
    (Chips-Cluster sind KI-befüllbar): Baustein in dessen Default-Slot legen.

    Livewire:   <x-foodalchemist::chips model="anker" :vocabular="$slugs" star />
    Read-only:  <x-foodalchemist::chips :values="$werte" readonly />
--}}
@props([
    'model' => null,
    'values' => [],
    'vocabular' => [],
    'star' => false,
    'readonly' => false,
    'placeholder' => 'Hinzufügen …',
])

@php
    $listId = 'chips-vocab-' . substr(md5(($model ?? 'static') . implode('|', $vocabular)), 0, 8);
@endphp

<div {{ $attributes->merge(['class' => 'flex flex-wrap items-center gap-1.5']) }}
     x-data="{
        chips: @if($model) $wire.entangle('{{ $model }}') @else {{ Js::from(array_values($values)) }} @endif,
        neu: '',
        add() {
            const v = this.neu.trim();
            if (v && !this.chips.includes(v)) this.chips.push(v);
            this.neu = '';
        },
     }"
     data-chips>
    <template x-for="(chip, i) in chips" :key="chip">
        <span class="inline-flex items-center gap-1 h-[26px] {{ $star ? 'pl-2' : 'pl-2.5' }} {{ $readonly ? 'pr-2.5' : 'pr-1' }} rounded-full text-[length:var(--fa-text-sm)] font-medium bg-[var(--fa-accent-soft)] text-[var(--fa-accent)]"
              data-chip>
            @if($star)<span class="text-[var(--fa-warn)]" aria-hidden="true" title="Kern-Anker">★</span>@endif
            <span x-text="chip"></span>
            @unless($readonly)
                <button type="button" @click="chips.splice(i, 1)"
                        class="w-5 h-5 inline-flex items-center justify-center rounded-full opacity-70 hover:opacity-100 hover:text-[var(--fa-crit)] hover:bg-[var(--fa-crit-soft)] transition-colors duration-150"
                        :aria-label="'Entfernen: ' + chip" :title="'Entfernen: ' + chip" data-chip-remove>@svg('heroicon-m-x-mark', 'w-3.5 h-3.5')</button>
            @endunless
        </span>
    </template>

    @unless($readonly)
        <input type="text" x-model="neu" @keydown.enter.prevent="add()" @change="add()"
               list="{{ $listId }}" placeholder="{{ $placeholder }}"
               class="fa-control w-36 h-[26px] rounded-full text-[length:var(--fa-text-sm)]"
               data-chip-add />
        <datalist id="{{ $listId }}">
            @foreach($vocabular as $eintrag)
                <option value="{{ $eintrag }}"></option>
            @endforeach
        </datalist>
    @endunless
</div>
