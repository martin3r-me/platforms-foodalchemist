{{--
    Eine klickbare Zeile in einer Browser-Tabelle (GPs, Rezepte, Gerichte, Pakete, Angebote, Produktion).
    EINE Stelle entscheidet, wie „ausgewählt" aussieht.

    fa-pass Welle 2 (2026-10-05): Auswahl = Akzentfüllung (aria-selected), Hover = neutrale Tönung.
    Kein Seitenbalken mehr. Zellen-Stil kommt von der Tabelle (class="fa-table").

    Nutzung — wire:key / wire:click / x-data / data-Marker fließen über $attributes durch:
        x-foodalchemist::table-row  wire:key="gp-{id}"  wire:click="waehle({id})"  :active="…"  data-gp-zeile="{id}"
--}}
@props(['active' => false])

<tr {{ $attributes->merge(['class' => 'cursor-pointer border-t border-[var(--fa-line)] transition-colors duration-100 hover:bg-[var(--fa-hover)]']) }} aria-selected="{{ $active ? 'true' : 'false' }}" data-fa-table-row>
    {{ $slot }}
</tr>
