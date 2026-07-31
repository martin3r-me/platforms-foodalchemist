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

@once
    <style>
        [data-fa-kpis] .kpi-label{ font-size:11px !important; }
        /* tabular-nums: Ziffern müssen über die Kacheln hinweg auf einer Spalte stehen,
           sonst „wackelt" der Streifen bei jedem Live-Update (Concepter rechnet mit). */
        /* Textwerte (Lieferant, GP-Name) können lang sein — abschneiden statt das Raster sprengen.
           Für Zahlen ohne Wirkung. Volltext steht im title (siehe Feld `title`). */
        [data-fa-kpis] .kpi-value{ font-size:16px !important; font-weight:600; line-height:1.15; margin-top:2px; font-variant-numeric:tabular-nums; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        /* Unvollständigkeits-Zeichen am Wert (z. B. „~" wenn eine Position ohne Portionsgewicht
           in die Summe läuft) — bewusst als eigenes Feld statt rohem HTML im Wert. */
        [data-fa-kpis] .kpi-hint{ font-weight:400; color:#b45309; margin-left:2px; }
        .fa-editor-panel [data-fa-kpis] .kpi-hint{ color:#fcd34d; }
        /* Hell-Theme */
        [data-fa-kpis] .kpi-neutral{ background:#fff !important; border-color:rgba(0,0,0,.06) !important; }
        [data-fa-kpis] .kpi-accent { background:rgba(139,92,246,.08) !important; border-color:rgba(139,92,246,.25) !important; }
        [data-fa-kpis] .kpi-good   { background:rgba(16,185,129,.09) !important; border-color:rgba(16,185,129,.26) !important; }
        [data-fa-kpis] .kpi-warn   { background:rgba(245,158,11,.11) !important; border-color:rgba(245,158,11,.30) !important; }
        [data-fa-kpis] .kpi-bad    { background:rgba(244,63,94,.08) !important; border-color:rgba(244,63,94,.26) !important; }
        [data-fa-kpis] .kpi-neutral .kpi-value{ color:#111827; }
        [data-fa-kpis] .kpi-accent  .kpi-value{ color:#6d28d9; }
        [data-fa-kpis] .kpi-good    .kpi-value{ color:#047857; }
        [data-fa-kpis] .kpi-warn    .kpi-value{ color:#b45309; }
        [data-fa-kpis] .kpi-bad     .kpi-value{ color:#be123c; }
        /* Dunkel-Theme (Editor-Grund) — schlägt die generische Kachel-Regel aus modal.blade.php */
        .fa-editor-panel [data-fa-kpis] .kpi-neutral{ background:rgba(255,255,255,.06) !important; border-color:rgba(255,255,255,.10) !important; }
        .fa-editor-panel [data-fa-kpis] .kpi-accent { background:rgba(139,92,246,.17) !important; border-color:rgba(167,139,250,.42) !important; }
        .fa-editor-panel [data-fa-kpis] .kpi-good   { background:rgba(16,185,129,.16) !important; border-color:rgba(16,185,129,.40) !important; }
        .fa-editor-panel [data-fa-kpis] .kpi-warn   { background:rgba(245,158,11,.16) !important; border-color:rgba(245,158,11,.40) !important; }
        .fa-editor-panel [data-fa-kpis] .kpi-bad    { background:rgba(244,63,94,.16) !important; border-color:rgba(244,63,94,.40) !important; }
        .fa-editor-panel [data-fa-kpis] .kpi-neutral .kpi-value{ color:#f1f5f9; }
        .fa-editor-panel [data-fa-kpis] .kpi-accent  .kpi-value{ color:#c4b5fd; }
        .fa-editor-panel [data-fa-kpis] .kpi-good    .kpi-value{ color:#6ee7b7; }
        .fa-editor-panel [data-fa-kpis] .kpi-warn    .kpi-value{ color:#fcd34d; }
        .fa-editor-panel [data-fa-kpis] .kpi-bad     .kpi-value{ color:#fda4af; }
    </style>
@endonce

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
