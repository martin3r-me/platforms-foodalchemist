{{--
    Eine Zeile in einer Filter-Sidebar (Warengruppen, Hauptgruppen, Kategorien, Klassen …).

    WARUM DIESER BAUSTEIN: der „aktiv"-Zustand stand 30× handgeschrieben in 18 Dateien —
    dieselbe Krankheit, die der Editor vor Spec 28 hatte. Jetzt entscheidet EINE Datei, wie
    „aktiv" aussieht, für alle 12 Filter-Sidebars und jede künftige Seite.

    KONTRAST-MODELL (Spec 28 / Weg A): **Balken = offener Zweig · Füllung = Auswahl.**
    Eine Elternzeile mit offenem Ast behält die Füllung nur, solange KEIN Kind gewählt ist
    (`child-active`). Sobald ein Kind gewählt ist, tritt das Elternteil auf den Balken zurück —
    so ist immer genau EINE Ebene als aktiv zu sehen. Vorher trugen Eltern und Kind gleichzeitig
    die violette Füllung und man sah nicht, auf welcher Ebene man stand.

    Der transparente Balken auf inaktiven Zeilen ist Absicht: gleiche Breite wie der aktive,
    damit beim Wechsel nichts springt.

    Nutzung — wire:click / wire:key / data-Marker fließen über $attributes durch:

        x-foodalchemist::filter-row  wire:click="waehleWg('01')"  :active="$wg === '01'"
            :child-active="$sub !== ''"  :count="$counts['01'] ?? 0"
          → Slot: das Label (darf eigenes Markup tragen, z. B. den [CODE]-Präfix)

        Kind-Ebene:  level="child"  :active="…"  :count="…"

    Kind-Zeilen gehören in <x-foodalchemist::filter-ast>, das die Führungslinie zeichnet.
--}}
@props([
    'active' => false,        {{-- diese Zeile ist die Auswahl --}}
    'childActive' => false,   {{-- nur Eltern: ein Kind ist gewählt → Elternteil tritt zurück --}}
    'count' => null,          {{-- Zähler rechts; 0 wird gedämpft (nichts zu holen) --}}
    'level' => 'top',         {{-- top | child --}}
])
@php
    $kind = $level === 'child';

    // Grundform: Kind-Zeilen sind kleiner und enger, tragen aber keinen eigenen Balken —
    // ihre Ebene ist bereits durch die Führungslinie des Astes verankert.
    $basis = $kind
        ? 'w-full flex items-center justify-between px-2 py-0.5 rounded text-[11px] transition-all duration-150'
        : 'w-full flex items-center justify-between px-2 py-1 rounded-lg text-xs transition-all duration-150 border-l-2';

    if ($kind) {
        $zustand = $active
            ? 'bg-violet-500/10 text-violet-700 font-medium'
            : 'text-gray-700 hover:bg-black/[0.03]';
    } elseif ($active) {
        $zustand = 'border-violet-500 text-violet-700 font-medium'
            . ($childActive ? '' : ' bg-gradient-to-r from-violet-500/10 to-indigo-500/10');
    } else {
        $zustand = 'border-transparent text-gray-700 hover:bg-black/[0.03]';
    }

    // Zähler: Zahlen stehen auf einer Spalte (tabular-nums). Eine 0 wird gedämpft — die Zeile
    // bleibt klickbar, sagt aber optisch „hier ist nichts".
    $zaehlerFarbe = ((int) $count) === 0 ? 'text-gray-400' : 'text-gray-500';
    $zaehlerGroesse = $kind ? '' : 'text-[11px] ';
    // Tausenderpunkte an EINER Stelle: vorher waren Baum-Zähler roh (6930) und die
    // „Alle …"-Zeile formatiert (6.930) — dieselbe Zahl in zwei Schreibweisen.
    $zaehlerText = is_numeric($count) ? number_format((float) $count, 0, ',', '.') : $count;
@endphp

<button type="button" {{ $attributes->merge(['class' => $basis . ' ' . $zustand]) }} data-fa-filter-row>
    <span class="min-w-0 truncate">{{ $slot }}</span>
    @if($count !== null)
        <span class="{{ $zaehlerGroesse }}{{ $zaehlerFarbe }} shrink-0 ml-2 tabular-nums">{{ $zaehlerText }}</span>
    @endif
</button>
