{{-- Geschmacks-Spinnennetz (7 Achsen, nur lesen, server-gerendertes SVG + Alpine-Tooltip).
     Fläche = gegarte Sensorik ($sensGeschmack, 0–1). Aroma-Anker-Wert je Achse im Tooltip ($ankerGeschmack, 0–1, optional).
     Erwartet: sensGeschmack (Pflicht-Map), ankerGeschmack ([]), dominant ([]), luecken ([]).
     fa-pass: Farben über Tailwind-Klassen mit --fa-*-Tokens (fill-/stroke-[var(…)]) statt fester Werte —
     so sitzt das Netz hell UND im Werkbank-Modus. Achsen-Beschriftung mindestens 12 Einheiten (Regel: keine
     Schrift unter 12 px); das Netz ist dafür etwas kleiner, die Ring-Werte stehen im Tooltip statt im Bild. --}}
@php
    $radarLabels = ['suess' => 'Süß', 'salzig' => 'Salzig', 'sauer' => 'Sauer', 'bitter' => 'Bitter', 'umami' => 'Umami', 'fettig' => 'Fettig', 'scharf' => 'Scharf'];
    $sens = $sensGeschmack ?? [];
    $anker = $ankerGeschmack ?? [];
    $dom = $dominant ?? [];
    $luk = $luecken ?? [];
    $axes = array_keys($radarLabels);
    $n = count($axes);
    $cx = 150; $cy = 150; $maxR = 96;
    $rings = [0.25, 0.5, 0.75, 1.0];
    $step = 360 / $n;
    $pt = function ($val01, $i) use ($cx, $cy, $maxR, $step) {
        $a = deg2rad(-90 + $i * $step);
        $r = max(0.0, min(1.0, (float) $val01)) * $maxR;
        return [round($cx + $r * cos($a), 2), round($cy + $r * sin($a), 2)];
    };
    $polyStr = implode(' ', array_map(function ($k) use ($sens, $pt, $axes) {
        [$x, $y] = $pt((float) ($sens[$k] ?? 0), array_search($k, $axes, true));
        return "$x,$y";
    }, $axes));

    // Vorberechnet je Achse: Speiche, Punkt, Beschriftung, Hover-Fläche (keine @php-Kurzform in Schleifen).
    $achsen = [];
    foreach ($axes as $i => $k) {
        $a = -90 + $i * $step;
        [$sx, $sy] = $pt(1, $i);
        $v = (float) ($sens[$k] ?? 0);
        [$dx, $dy] = $pt($v, $i);
        $achsen[$k] = [
            'sx' => $sx, 'sy' => $sy,
            'wert' => $v, 'dx' => $dx, 'dy' => $dy,
            'luecke' => in_array($k, $luk, true),
            'dominant' => in_array($k, $dom, true),
            'lx' => round($cx + ($maxR + 14) * cos(deg2rad($a)), 2),
            'ly' => round($cy + ($maxR + 14) * sin(deg2rad($a)), 2),
            'anchor' => ($a > -80 && $a < 80) ? 'start' : (($a > 100 || $a < -100) ? 'end' : 'middle'),
            'hx' => round($cx + ($maxR + 4) * cos(deg2rad($a)), 2),
            'hy' => round($cy + ($maxR + 4) * sin(deg2rad($a)), 2),
            'sv' => number_format($v, 2, ',', '.'),
            'av' => array_key_exists($k, $anker) ? (int) round(((float) $anker[$k]) * 100) : null,
        ];
    }
@endphp

<div x-data="{ act: null }" class="relative" data-geschmack-radar>
    <svg viewBox="0 0 300 300" class="w-full max-w-[360px] mx-auto overflow-visible" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Geschmacksprofil">
        {{-- Gitter-Ringe --}}
        @foreach($rings as $lv)
            <circle class="fill-none {{ $lv == 1.0 ? 'stroke-[var(--fa-line-strong)]' : 'stroke-[var(--fa-line)]' }}"
                cx="{{ $cx }}" cy="{{ $cy }}" r="{{ round($lv * $maxR, 2) }}" stroke-width="1" />
        @endforeach

        {{-- Speichen --}}
        @foreach($achsen as $k => $ax)
            <line class="stroke-[var(--fa-line)]" x1="{{ $cx }}" y1="{{ $cy }}" x2="{{ $ax['sx'] }}" y2="{{ $ax['sy'] }}" stroke-width="1" />
        @endforeach

        {{-- Polygon = Sensorik --}}
        <polygon points="{{ $polyStr }}" class="fill-[var(--fa-accent)] stroke-[var(--fa-accent)]"
            fill-opacity="0.18" stroke-width="1.8" stroke-linejoin="round" />

        {{-- Punkte (dominant betont, Lücke ausgegraut, 0-Werte am Zentrum → kein Punkt) --}}
        @foreach($achsen as $k => $ax)
            @if($ax['wert'] > 0)
                <circle cx="{{ $ax['dx'] }}" cy="{{ $ax['dy'] }}" r="{{ $ax['dominant'] ? 4 : 3.2 }}"
                    class="{{ $ax['luecke'] ? 'fill-[var(--fa-ink-3)]' : 'fill-[var(--fa-accent)]' }} stroke-[var(--fa-surface)]" stroke-width="1.5" />
            @endif
        @endforeach

        {{-- Achsen-Beschriftung --}}
        @foreach($achsen as $k => $ax)
            <text x="{{ $ax['lx'] }}" y="{{ $ax['ly'] }}" text-anchor="{{ $ax['anchor'] }}" dominant-baseline="central"
                class="text-[length:var(--fa-text-sm)] {{ $ax['luecke'] ? 'font-normal fill-[var(--fa-ink-3)]' : 'font-semibold fill-[var(--fa-ink-2)]' }}">{{ $radarLabels[$k] }}</text>
        @endforeach

        {{-- Unsichtbare Hover-Flächen → Alpine-Tooltip --}}
        @foreach($achsen as $k => $ax)
            <circle cx="{{ $ax['hx'] }}" cy="{{ $ax['hy'] }}" r="24" fill="transparent" class="cursor-pointer"
                @mouseenter="act = '{{ $k }}'" @mouseleave="act = null" />
        @endforeach
    </svg>

    {{-- Tooltips (außerhalb des SVG), mittig eingeblendet --}}
    @foreach($achsen as $k => $ax)
        <div x-show="act === '{{ $k }}'" x-cloak x-transition.opacity
            class="absolute z-20 left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 flex flex-col gap-1 px-3 py-2 rounded-[var(--fa-radius-control)] fa-surface shadow-lg pointer-events-none whitespace-nowrap">
            <div class="text-[length:var(--fa-text-sm)] font-semibold text-[var(--fa-ink)]">{{ $radarLabels[$k] }}</div>
            <div class="flex items-center justify-between gap-4 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]">
                <span class="inline-flex items-center gap-1.5"><span class="inline-block w-2 h-2 rounded-full bg-[var(--fa-accent)]"></span>Sensorik</span>
                <span class="tabular-nums font-medium text-[var(--fa-ink)]">{{ $ax['sv'] }}</span>
            </div>
            @if($ax['av'] !== null)
                <div class="flex items-center justify-between gap-4 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]">
                    <span class="inline-flex items-center gap-1.5"><span class="inline-block w-2 h-2 rounded-full bg-[var(--fa-accent-line)]"></span>Aroma-Anker</span>
                    <span class="tabular-nums font-medium text-[var(--fa-ink)]">{{ $ax['av'] }} %</span>
                </div>
            @endif
        </div>
    @endforeach
</div>
