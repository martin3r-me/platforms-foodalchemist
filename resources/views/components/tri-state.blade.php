{{--
    M0-10 / P-4: Tri-State-Kontrolle (−/≈/Haken + unbekannt) — GL-01-4-Wert-Modell.
    fa-pass (2026-10-05): Tokens, Haken als Heroicon; Alpine-Logik und Marker unverändert.

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
    // Zeichen: − nicht enthalten · ≈ Spuren · Haken (Heroicon) enthalten. Farben nur über Tokens.
    $buttons = [
        'nicht_enthalten' => ['−', 'nicht enthalten', 'bg-[var(--fa-neutral-soft)] text-[var(--fa-ink)] border-[var(--fa-line-strong)]'],
        'spuren' => ['≈', 'Spuren', 'bg-[var(--fa-warn-soft)] text-[var(--fa-warn)] border-[var(--fa-warn)]'],
        'enthalten' => [null, 'enthalten', 'bg-[var(--fa-crit-soft)] text-[var(--fa-crit)] border-[var(--fa-crit)]'],
    ];
    $btnBase = 'w-6 h-6 inline-flex items-center justify-center text-[length:var(--fa-text-sm)] font-medium rounded-[var(--fa-radius-control)] border transition-colors duration-150';
    $btnInaktiv = 'border-[var(--fa-line)] text-[var(--fa-ink-3)]';
    // fehlende Keys = unbekannt (GL-01: nie NULL-Lücken im Binding)
    $initial = collect($items)->mapWithKeys(fn ($label, $key) => [$key => $values[$key] ?? 'unbekannt'])->all();
@endphp

<div {{ $attributes->merge(['class' => 'divide-y divide-[var(--fa-line)]']) }}
     x-data="{ werte: @if($model) $wire.entangle('{{ $model }}') @else {{ Js::from($initial) }} @endif }"
     data-tri-state>
    @foreach($items as $key => $label)
        <div class="flex items-center justify-between gap-3 py-1" data-tri-row="{{ $key }}">
            <span class="text-[length:var(--fa-text-md)] text-[var(--fa-ink)] min-w-0 truncate">{{ $label }}</span>
            <div class="flex items-center gap-1 shrink-0">
                @foreach($buttons as $wert => [$zeichen, $titel, $aktivKlasse])
                    <button type="button" title="{{ $titel }}" aria-label="{{ $label }}: {{ $titel }}"
                            @if($readonly)
                                disabled
                                :class="(werte['{{ $key }}'] ?? 'unbekannt') === '{{ $wert }}' ? @js($aktivKlasse) : @js($btnInaktiv . ' opacity-60')"
                            @else
                                @click="werte['{{ $key }}'] = (werte['{{ $key }}'] ?? 'unbekannt') === '{{ $wert }}' ? 'unbekannt' : '{{ $wert }}'"
                                :class="(werte['{{ $key }}'] ?? 'unbekannt') === '{{ $wert }}' ? @js($aktivKlasse) : @js($btnInaktiv . ' hover:text-[var(--fa-ink)] hover:bg-[var(--fa-hover)]')"
                            @endif
                            class="{{ $btnBase }}"
                            data-tri-btn="{{ $wert }}">@if($zeichen === null)@svg('heroicon-m-check', 'w-3.5 h-3.5')@else{{ $zeichen }}@endif</button>
                @endforeach
            </div>
        </div>
    @endforeach
</div>
