{{--
    M0-10 / P-4: Tri-State-Kontrolle (−/≈/✓ + unbekannt) — GL-01-4-Wert-Modell.

    Je Zeile (z. B. Allergen) drei Toggle-Buttons:
        −  nicht_enthalten (grau) · ≈  spuren (amber) · ✓  enthalten (rot)
    Ungesetzt/erneuter Klick auf den aktiven Button = 'unbekannt' (4. Zustand, GL-01).

    Rein clientseitig (Alpine), EIN Binding aufs Array (P-4/P-8: kein Server-
    Roundtrip beim Togglen — Sync deferred mit dem nächsten Livewire-Request).

    Nutzung im Livewire-Kontext (model = Name der Array-Property):
        <x-foodalchemist::tri-state :items="$allergenLabels" model="allergene" />
    Ohne Livewire / read-only (Rezept-Snapshot, Kind-Team):
        <x-foodalchemist::tri-state :items="$labels" :values="$werte" readonly />

    Werte-Domäne: AllergenValue-Strings (enthalten|spuren|nicht_enthalten|unbekannt).
--}}
@props([
    'items' => [],
    'model' => null,
    'values' => [],
    'readonly' => false,
])

@php
    $buttons = [
        'nicht_enthalten' => ['−', 'nicht enthalten', 'bg-gray-500/20 text-gray-700 dark:bg-white/15 dark:text-gray-200 border-gray-500/30'],
        'spuren' => ['≈', 'Spuren', 'bg-amber-500/15 text-amber-600 dark:text-amber-400 border-amber-500/30'],
        'enthalten' => ['✓', 'enthalten', 'bg-red-500/15 text-red-600 dark:text-red-400 border-red-500/30'],
    ];
    $btnBase = 'w-7 h-7 inline-flex items-center justify-center text-xs font-medium rounded-md border transition-all duration-150';
    $btnInaktiv = 'border-black/5 dark:border-white/10 text-gray-300 dark:text-gray-600';
    // fehlende Keys = unbekannt (GL-01: nie NULL-Lücken im Binding)
    $initial = collect($items)->mapWithKeys(fn ($label, $key) => [$key => $values[$key] ?? 'unbekannt'])->all();
@endphp

<div {{ $attributes->merge(['class' => 'divide-y divide-black/5 dark:divide-white/5']) }}
     x-data="{ werte: @if($model) $wire.entangle('{{ $model }}') @else {{ Js::from($initial) }} @endif }"
     data-tri-state>
    @foreach($items as $key => $label)
        <div class="flex items-center justify-between gap-3 py-1.5" data-tri-row="{{ $key }}">
            <span class="text-sm text-gray-700 dark:text-gray-300 min-w-0 truncate">{{ $label }}</span>
            <div class="flex items-center gap-1 shrink-0">
                @foreach($buttons as $wert => [$zeichen, $titel, $aktivKlasse])
                    <button type="button" title="{{ $titel }}"
                            @if($readonly)
                                disabled
                                :class="(werte['{{ $key }}'] ?? 'unbekannt') === '{{ $wert }}' ? @js($aktivKlasse) : @js($btnInaktiv . ' opacity-60')"
                            @else
                                @click="werte['{{ $key }}'] = (werte['{{ $key }}'] ?? 'unbekannt') === '{{ $wert }}' ? 'unbekannt' : '{{ $wert }}'"
                                :class="(werte['{{ $key }}'] ?? 'unbekannt') === '{{ $wert }}' ? @js($aktivKlasse) : @js($btnInaktiv . ' hover:text-gray-500 hover:bg-black/5 dark:hover:bg-white/10')"
                            @endif
                            class="{{ $btnBase }}"
                            data-tri-btn="{{ $wert }}">{{ $zeichen }}</button>
                @endforeach
            </div>
        </div>
    @endforeach
</div>
