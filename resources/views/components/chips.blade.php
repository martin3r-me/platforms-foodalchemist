{{--
    M0-11 / P-5: Chip-Editor — Anker, Tags, Pairing, Eignungen.

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
    'placeholder' => '+ manuell…',
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
        <span class="inline-flex items-center gap-1 {{ $star ? 'pl-1.5' : 'pl-2.5' }} {{ $readonly ? 'pr-2.5' : 'pr-1' }} py-0.5 rounded-full text-xs bg-violet-500/10 text-violet-700 dark:text-violet-300"
              data-chip>
            @if($star)<span class="text-amber-500">★</span>@endif
            <span x-text="chip"></span>
            @unless($readonly)
                <button type="button" @click="chips.splice(i, 1)"
                        class="w-4 h-4 inline-flex items-center justify-center rounded-full text-violet-400 hover:text-red-500 hover:bg-red-500/10 transition-colors duration-150"
                        :aria-label="'Entfernen: ' + chip" data-chip-remove>×</button>
            @endunless
        </span>
    </template>

    @unless($readonly)
        <input type="text" x-model="neu" @keydown.enter.prevent="add()" @change="add()"
               list="{{ $listId }}" placeholder="{{ $placeholder }}"
               class="w-32 px-2 py-0.5 text-xs bg-black/[0.03] dark:bg-white/5 rounded-full border-0 placeholder-gray-400 focus:ring-2 focus:ring-violet-500/20 focus:bg-white dark:focus:bg-white/10 transition-all duration-150"
               data-chip-add />
        <datalist id="{{ $listId }}">
            @foreach($vocabular as $eintrag)
                <option value="{{ $eintrag }}"></option>
            @endforeach
        </datalist>
    @endunless
</div>
