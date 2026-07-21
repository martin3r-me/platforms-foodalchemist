{{-- Geschmacks-Spinnennetz (7 Achsen, read-only, server-gerendertes SVG + Alpine-Tooltip).
     Fläche = gegarte Sensorik ($sensGeschmack, 0–1). Aroma-Anker-Wert je Achse im Tooltip ($ankerGeschmack, 0–1, optional).
     Erwartet: sensGeschmack (Pflicht-Map), ankerGeschmack ([]), dominant ([]), luecken ([]).
     Farben als inline-Literal (KEINE interpolierten JIT-Klassen → würden im statischen CSS-Build fehlen, s. sensorik.blade.php). --}}
@php
    $radarLabels = ['suess' => 'Süß', 'salzig' => 'Salzig', 'sauer' => 'Sauer', 'bitter' => 'Bitter', 'umami' => 'Umami', 'fettig' => 'Fettig', 'scharf' => 'Scharf'];
    $sens  = $sensGeschmack ?? [];
    $anker = $ankerGeschmack ?? [];
    $dom   = $dominant ?? [];
    $luk   = $luecken ?? [];
    $axes  = array_keys($radarLabels);
    $n     = count($axes);
    $cx = 150; $cy = 150; $maxR = 108;
    $rings = [0.25, 0.5, 0.75, 1.0];
    $step  = 360 / $n;
    $pt = function ($val01, $i) use ($cx, $cy, $maxR, $step) {
        $a = deg2rad(-90 + $i * $step);
        $r = max(0.0, min(1.0, (float) $val01)) * $maxR;
        return [round($cx + $r * cos($a), 2), round($cy + $r * sin($a), 2)];
    };
    $polyStr = implode(' ', array_map(function ($k) use ($sens, $pt, $axes) {
        [$x, $y] = $pt((float) ($sens[$k] ?? 0), array_search($k, $axes, true));
        return "$x,$y";
    }, $axes));
@endphp

<div x-data="{ act: null }" class="relative">
    <svg viewBox="0 0 300 300" class="w-full max-w-[420px] mx-auto" xmlns="http://www.w3.org/2000/svg">
        <defs>
            <radialGradient id="tasteRadarGlow" cx="50%" cy="50%" r="50%">
                <stop offset="0%" stop-color="rgb(139 92 246)" stop-opacity="0.5" />
                <stop offset="55%" stop-color="rgb(139 92 246)" stop-opacity="0.12" />
                <stop offset="100%" stop-color="rgb(139 92 246)" stop-opacity="0" />
            </radialGradient>
        </defs>

        {{-- Gitter-Ringe --}}
        @foreach($rings as $lv)
            <circle cx="{{ $cx }}" cy="{{ $cy }}" r="{{ round($lv * $maxR, 2) }}"
                fill="none" stroke="{{ $lv == 1.0 ? 'rgb(0 0 0 / 0.14)' : 'rgb(0 0 0 / 0.07)' }}" stroke-width="1" />
        @endforeach

        {{-- Speichen --}}
        @foreach($axes as $i => $k)
            @php([$sx, $sy] = $pt(1, $i))
            <line x1="{{ $cx }}" y1="{{ $cy }}" x2="{{ $sx }}" y2="{{ $sy }}" stroke="rgb(0 0 0 / 0.08)" stroke-width="1" />
        @endforeach

        {{-- Zentrum-Glow --}}
        <circle cx="{{ $cx }}" cy="{{ $cy }}" r="46" fill="url(#tasteRadarGlow)" />

        {{-- Polygon = Sensorik --}}
        <polygon points="{{ $polyStr }}"
            fill="rgb(139 92 246)" fill-opacity="0.18" stroke="rgb(139 92 246)" stroke-width="1.8" stroke-linejoin="round" />

        {{-- Vertex-Punkte (dominant betont, Lücke ausgegraut, 0-Werte am Zentrum → kein Punkt) --}}
        @foreach($axes as $i => $k)
            @php($v = (float) ($sens[$k] ?? 0))
            @if($v > 0)
                @php([$dx, $dy] = $pt($v, $i))
                @php($isLuk = in_array($k, $luk, true))
                @php($isDom = in_array($k, $dom, true))
                <circle cx="{{ $dx }}" cy="{{ $dy }}" r="{{ $isDom ? 4 : 3.2 }}"
                    fill="{{ $isLuk ? 'rgb(156 163 175)' : 'rgb(139 92 246)' }}" stroke="#fff" stroke-width="1.5" />
            @endif
        @endforeach

        {{-- Ring-Wert-Labels (entlang der oberen Achse) --}}
        @foreach($rings as $lv)
            <text x="{{ $cx + 4 }}" y="{{ round($cy - $lv * $maxR + 3, 2) }}" style="font-size: 7px; fill: #cbd5e1;">{{ number_format($lv, 2, ',', '.') }}</text>
        @endforeach

        {{-- Achsen-Labels --}}
        @foreach($axes as $i => $k)
            @php($a = -90 + $i * $step)
            @php($lx = round($cx + ($maxR + 16) * cos(deg2rad($a)), 2))
            @php($ly = round($cy + ($maxR + 16) * sin(deg2rad($a)), 2))
            @php($anchor = ($a > -80 && $a < 80) ? 'start' : (($a > 100 || $a < -100) ? 'end' : 'middle'))
            @php($isLuk = in_array($k, $luk, true))
            <text x="{{ $lx }}" y="{{ $ly }}" text-anchor="{{ $anchor }}" dominant-baseline="central"
                style="font-size: 9px; font-weight: {{ $isLuk ? '400' : '600' }}; fill: {{ $isLuk ? '#9ca3af' : '#4b5563' }};">{{ $radarLabels[$k] }}</text>
        @endforeach

        {{-- Unsichtbare Hover-Flächen → Alpine-Tooltip --}}
        @foreach($axes as $i => $k)
            @php($a = -90 + $i * $step)
            @php($hx = round($cx + ($maxR + 4) * cos(deg2rad($a)), 2))
            @php($hy = round($cy + ($maxR + 4) * sin(deg2rad($a)), 2))
            <circle cx="{{ $hx }}" cy="{{ $hy }}" r="24" fill="transparent" class="cursor-pointer"
                @mouseenter="act = '{{ $k }}'" @mouseleave="act = null" />
        @endforeach
    </svg>

    {{-- Tooltips (außerhalb des SVG für sauberes Styling), zentriert eingeblendet --}}
    @foreach($axes as $k)
        @php($sv = number_format((float) ($sens[$k] ?? 0), 2, ',', '.'))
        @php($hasAnker = array_key_exists($k, $anker))
        @php($av = $hasAnker ? (int) round(((float) $anker[$k]) * 100) : null)
        <div x-show="act === '{{ $k }}'" x-cloak x-transition.opacity
            class="absolute z-20 px-3 py-2 rounded-lg text-white shadow-lg pointer-events-none whitespace-nowrap"
            style="left: 50%; top: 50%; transform: translate(-50%, -50%); background: #1f2937;">
            <div class="font-semibold text-[11px] mb-1">{{ $radarLabels[$k] }}</div>
            <div class="flex items-center justify-between gap-3 text-[10px] text-gray-300">
                <span><span class="inline-block w-2 h-2 rounded-full mr-1 align-middle" style="background: rgb(139 92 246);"></span>Sensorik</span>
                <span class="tabular-nums">{{ $sv }}</span>
            </div>
            @if($hasAnker)
                <div class="flex items-center justify-between gap-3 text-[10px] text-gray-300 mt-0.5">
                    <span><span class="inline-block w-2 h-2 rounded-full mr-1 align-middle" style="background: rgb(167 139 250);"></span>Aroma-Anker</span>
                    <span class="tabular-nums">{{ $av }} %</span>
                </div>
            @endif
        </div>
    @endforeach
</div>
