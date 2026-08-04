@php
    $n = $naehrwerte ?? [];
    $has = ($n['kcal'] ?? null) !== null;
    $num = fn ($v, $dec = 1, $suffix = '') => $v !== null && $v !== '' ? rtrim(rtrim(number_format((float) $v, $dec, ',', '.'), '0'), ',') . $suffix : '—';
@endphp

<h4>Nährwerte <span class="muted">je 100 g</span></h4>
@if($has)
    <table>
        <thead><tr><th>Brennwert</th><th>Eiweiß</th><th>Fett</th><th>davon ges.</th><th>KH</th><th>davon Zucker</th><th>Salz</th></tr></thead>
        <tbody>
            <tr>
                <td>{{ $num($n['kcal'] ?? null, 0, ' kcal') }}</td>
                <td>{{ $num($n['protein_g'] ?? null, 1, ' g') }}</td>
                <td>{{ $num($n['fat_g'] ?? null, 1, ' g') }}</td>
                <td>{{ $num($n['saturated_fat_g'] ?? null, 1, ' g') }}</td>
                <td>{{ $num($n['carbs_g'] ?? null, 1, ' g') }}</td>
                <td>{{ $num($n['sugar_g'] ?? null, 1, ' g') }}</td>
                <td>{{ $num($n['salt_g'] ?? null, 2, ' g') }}</td>
            </tr>
        </tbody>
    </table>
    <p class="muted">Konfidenz/Quelle: {{ strtoupper((string) ($n['confidence'] ?? $n['source'] ?? '—')) }}@if(($n['mapped'] ?? null) !== null) · {{ $n['mapped'] }}/{{ $n['total'] ?? '—' }} Zutaten mit Nährwertdaten @endif</p>
@else
    <p class="muted">Keine Nährwerte aggregiert.</p>
@endif
