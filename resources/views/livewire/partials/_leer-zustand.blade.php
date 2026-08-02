{{-- Einheitlicher Leer-Zustand (Cockpit) — ersetzt die 3 fast gleichen „nichts hier"-Blöcke
     im Signale-Cockpit (Überblick, Signale, Vorschläge, Pflege). Eine Größe, eine Copy.
     Erwartet: $icon (heroicon-o-…), $text; optional $ton ('gut' = emerald | 'neutral' = grau). --}}
@php
    $ton = $ton ?? 'neutral';
    $iconKlasse = $ton === 'gut' ? 'text-emerald-400/70' : 'text-gray-300';
@endphp
<div class="flex flex-col items-center justify-center py-10 text-center">
    @svg($icon, 'w-9 h-9 '.$iconKlasse)
    <p class="text-xs text-gray-500 mt-2">{{ $text }}</p>
</div>
