{{--
    Eine Zeile in einer Filter-Spalte (Warengruppen, Hauptgruppen, Kategorien, Klassen …).
    EINE Datei entscheidet, wie „aktiv" aussieht — für alle Filter-Spalten.

    fa-pass Welle 2 (2026-10-05): Auswahl = Akzentfüllung, kein farbiger Seitenbalken mehr.
    Genau EINE Ebene ist gefüllt: ist ein Kind gewählt, trägt das Elternteil nur noch fette Schrift.

    Nutzung — wire:click / wire:key / data-Marker fließen über $attributes durch:
        x-foodalchemist::filter-row  wire:click="waehleWg('01')"  :active="$wg === '01'"
            :child-active="$sub !== ''"  :count="$counts['01'] ?? 0"
        Kind-Ebene:  level="child"  (in <x-foodalchemist::filter-ast>)
--}}
@props([
    'active' => false,
    'childActive' => false,
    'count' => null,
    'level' => 'top',
])
@php
    $kind = $level === 'child';
    $basis = 'w-full flex items-center justify-between gap-2 rounded-[var(--fa-radius-control)] transition-colors duration-150 text-left '
        . ($kind ? 'h-7 px-2 text-[length:var(--fa-text-sm)]' : 'h-8 px-2.5 text-[length:var(--fa-text-md)]');

    if ($active && ! $childActive) {
        $zustand = 'bg-[var(--fa-accent-soft)] text-[var(--fa-accent)] font-medium';
    } elseif ($active) {
        $zustand = 'text-[var(--fa-ink)] font-semibold hover:bg-[var(--fa-hover)]';
    } else {
        $zustand = 'text-[var(--fa-ink-2)] hover:bg-[var(--fa-hover)] hover:text-[var(--fa-ink)]';
    }

    $zaehlerFarbe = ((int) $count) === 0 ? 'text-[var(--fa-ink-3)] opacity-60' : 'text-[var(--fa-ink-3)]';
    $zaehlerText = is_numeric($count) ? number_format((float) $count, 0, ',', '.') : $count;
@endphp

<button type="button" {{ $attributes->merge(['class' => $basis . ' ' . $zustand]) }} @if($active) aria-current="true" @endif data-fa-filter-row>
    <span class="min-w-0 truncate">{{ $slot }}</span>
    @if($count !== null)
        <span class="text-[length:var(--fa-text-sm)] {{ $zaehlerFarbe }} shrink-0 tabular-nums">{{ $zaehlerText }}</span>
    @endif
</button>
