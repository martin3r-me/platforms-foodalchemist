{{--
    Spec 28 / E0.2: KPI-Kachel-Streifen für den Editor-Kopf (x-slot:kpiHeader).

    Herausgelöst aus dem Master-Editor (Basisrezepte, `recipe-modal`) — dort lag die Palette als
    roher <style>-Block inline und war damit nicht wiederverwendbar. Jeder Voll-Editor (Rezept,
    Gericht/VK, Concepter, GP, LA) nutzt jetzt diesen Baustein.

    Nutzung:
        <x-slot:kpiHeader>
            <x-foodalchemist::kpi-tiles marker="editor-kpis" :tiles="[
                ['label' => 'Yield',      'value' => '2,400 kg',  'kpi' => 'yield'],
                ['label' => 'EK / kg',    'value' => '4,20 €/kg', 'kpi' => 'ekkg', 'tone' => 'accent'],
                ['label' => 'Mit Preis',  'value' => '9/9',       'kpi' => 'priced', 'tone' => $ok ? 'good' : 'warn'],
            ]" />
        </x-slot:kpiHeader>

    Tone-Semantik (D-5-Regel, bewusst knapp halten):
        accent  — DER Leitwert des Editors. Genau EINER pro Streifen (violetter Marken-Akzent).
        good    — Vollständigkeit erreicht / Schwelle eingehalten.
        warn    — Lücke, die man schließen kann (kein Alarm).
        bad     — echter Missstand.
        neutral — Kennzahl ohne Bewertung (Default).
    Nie mehrere Alarmfarben nebeneinander — sonst trägt keine mehr Information.

    Warum rohes CSS statt Tailwind-Utilities: die Werte müssen auf hellem UND auf dunklem
    Editor-Grund (`.fa-editor-panel`) sitzen, und der Grund hellt Flächen generisch auf
    (modal.blade.php). Farbe per Utility-Klasse würde dort verschluckt — README §159 (kein `dark:`).

    Marker: `data-fa-kpis` liegt immer an (Styling-Anker), `data-{marker}` zusätzlich für die
    bestehenden Pest-Marker (`data-editor-kpis`, `data-vk-editor-kpis` — die Tests greifen darauf).
--}}
@props([
    {{-- Kachel-Felder: label · value · tone · kpi (Marker) · title (Tooltip)
         · hint + hint_title (kleines Zeichen hinter dem Wert, z. B. „~" für unvollständig) --}}
    'tiles' => [],
    'cols' => null,                {{-- md-Spalten; default = Anzahl Kacheln (3–6) --}}
    'marker' => null,              {{-- zusätzliches data-Attribut für Tests/Selektoren --}}
])
@php
    $tiles = array_values(array_filter($tiles, fn ($t) => is_array($t)));
    $spalten = $cols ?? count($tiles);
    // literal, weil Tailwind nur Blade scannt — berechnete Klassennamen fehlen im Kompilat.
    // Hinweis: in PHP-Blöcken nur PHP-Kommentare verwenden. Blade-Kommentare werden hier
    // nicht gestrippt, und der Blockinhalt landet verbatim im Kompilat (BladeCompilesTest
    // meldet jede Direktive, die dort als Text auftaucht — auch eine bloß erwähnte).
    // Bis 7 Spalten, weil der Concepter-Streifen 7 Kacheln in EINER Reihe führte — bei einer
    // Klammer auf 6 wäre die siebte verwaist umgebrochen. Mehr als 7 wird umgebrochen (5er-Raster).
    $gridCols = match (max(2, min(7, (int) $spalten))) {
        2 => 'md:grid-cols-2',
        3 => 'md:grid-cols-3',
        4 => 'md:grid-cols-4',
        6 => 'md:grid-cols-6',
        7 => 'md:grid-cols-7',
        default => 'md:grid-cols-5',
    };
    $toneKlasse = fn (?string $t) => 'kpi-' . (in_array($t, ['accent', 'good', 'warn', 'bad'], true) ? $t : 'neutral');
@endphp

{{-- fa-pass: Kachel-Optik liegt in resources/css/foodalchemist-pass.css (Tokens, hell + Werkbank) — kein eigener style-Block mehr. --}}

<div {{ $attributes->merge(['class' => 'grid grid-cols-2 ' . $gridCols . ' gap-2']) }}
     data-fa-kpis @if($marker) data-{{ $marker }} @endif>
    @foreach($tiles as $tile)
        <div class="rounded-lg border shadow-sm px-3 py-2 {{ $toneKlasse($tile['tone'] ?? null) }}"
             @if(!empty($tile['kpi'])) data-kpi="{{ $tile['kpi'] }}" @endif
             @if(!empty($tile['title'])) title="{{ $tile['title'] }}" @endif>
            <span class="text-[10px] font-medium uppercase tracking-wider text-gray-600 kpi-label">{{ $tile['label'] ?? '' }}</span>
            <p class="kpi-value">{{ $tile['value'] ?? '—' }}@if(!empty($tile['hint']))<span class="kpi-hint" @if(!empty($tile['hint_title'])) title="{{ $tile['hint_title'] }}" @endif>{{ $tile['hint'] }}</span>@endif</p>
        </div>
    @endforeach
</div>
