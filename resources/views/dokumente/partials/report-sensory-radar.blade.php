@php
    $radarLabels = [
        'suess' => 'Süß',
        'salzig' => 'Salzig',
        'sauer' => 'Sauer',
        'bitter' => 'Bitter',
        'umami' => 'Umami',
        'fettig' => 'Fettig',
        'scharf' => 'Scharf',
    ];
    $sens = $geschmack ?? [];
    $dom = $dominant ?? [];
    $luk = $luecken ?? [];
    $axes = array_keys($radarLabels);
    $n = count($axes);
    $cx = 150;
    $cy = 150;
    $maxR = 94;
    $rings = [0.25, 0.5, 0.75, 1.0];
    $step = 360 / max(1, $n);
    $pt = function ($val01, $i) use ($cx, $cy, $maxR, $step) {
        $a = deg2rad(-90 + $i * $step);
        $r = max(0.0, min(1.0, (float) $val01)) * $maxR;

        return [round($cx + $r * cos($a), 2), round($cy + $r * sin($a), 2)];
    };
    $polyStr = implode(' ', array_map(function ($k) use ($sens, $pt, $axes) {
        [$x, $y] = $pt((float) ($sens[$k] ?? 0), array_search($k, $axes, true));

        return "$x,$y";
    }, $axes));
    $svg = '<svg viewBox="0 0 300 300" width="260" height="260" xmlns="http://www.w3.org/2000/svg">';
    $svg .= '<rect x="0" y="0" width="300" height="300" fill="#ffffff"/>';
    foreach ($rings as $lv) {
        $svg .= '<circle cx="'.$cx.'" cy="'.$cy.'" r="'.round($lv * $maxR, 2).'" fill="none" stroke="'.($lv == 1.0 ? '#c4b5fd' : '#e5e7eb').'" stroke-width="'.($lv == 1.0 ? '1.2' : '0.8').'"/>';
    }
    foreach ($axes as $i => $k) {
        [$sx, $sy] = $pt(1, $i);
        $svg .= '<line x1="'.$cx.'" y1="'.$cy.'" x2="'.$sx.'" y2="'.$sy.'" stroke="#e5e7eb" stroke-width="0.8"/>';
    }
    $svg .= '<polygon points="'.$polyStr.'" fill="#ede9fe" stroke="#7c3aed" stroke-width="2" stroke-linejoin="round"/>';
    foreach ($axes as $i => $k) {
        $v = (float) ($sens[$k] ?? 0);
        if ($v <= 0) {
            continue;
        }
        [$dx, $dy] = $pt($v, $i);
        $isLuk = in_array($k, $luk, true);
        $isDom = in_array($k, $dom, true);
        $svg .= '<circle cx="'.$dx.'" cy="'.$dy.'" r="'.($isDom ? '4.2' : '3.2').'" fill="'.($isLuk ? '#9ca3af' : '#7c3aed').'" stroke="#ffffff" stroke-width="1.4"/>';
    }
    foreach ($rings as $lv) {
        $svg .= '<text x="'.($cx + 4).'" y="'.round($cy - $lv * $maxR + 3, 2).'" font-size="7" fill="#9ca3af">'.e(number_format($lv, 2, ',', '.')).'</text>';
    }
    foreach ($axes as $i => $k) {
        $a = -90 + $i * $step;
        $lx = round($cx + ($maxR + 16) * cos(deg2rad($a)), 2);
        $ly = round($cy + ($maxR + 16) * sin(deg2rad($a)), 2);
        $anchor = ($a > -80 && $a < 80) ? 'start' : (($a > 100 || $a < -100) ? 'end' : 'middle');
        $isLuk = in_array($k, $luk, true);
        $svg .= '<text x="'.$lx.'" y="'.$ly.'" text-anchor="'.$anchor.'" font-size="9" font-weight="'.($isLuk ? '400' : '600').'" fill="'.($isLuk ? '#9ca3af' : '#4b5563').'">'.e($radarLabels[$k]).'</text>';
    }
    $svg .= '</svg>';
@endphp

<div class="sensorik-radar">
    <div class="sensorik-radar-chart">
        <img src="data:image/svg+xml;base64,{{ base64_encode($svg) }}" width="260" height="260" alt="Sensorik-Rad">
    </div>
    <div class="sensorik-radar-values">
        <table>
            <thead><tr><th>Achse</th><th>Wert</th><th>Hinweis</th></tr></thead>
            <tbody>
                @foreach($axes as $k)
                    <tr>
                        <td>{{ $radarLabels[$k] }}</td>
                        <td>{{ number_format((float) ($sens[$k] ?? 0), 2, ',', '.') }}</td>
                        <td>
                            @if(in_array($k, $dom, true))
                                dominant
                            @elseif(in_array($k, $luk, true))
                                Lücke
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
