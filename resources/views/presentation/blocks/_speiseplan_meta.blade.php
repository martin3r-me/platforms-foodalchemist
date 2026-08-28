{{-- Speiseplan-Zusatzangaben: Kostformen (Werktage) + DGE-Ø-Nährwerte. Wird sowohl im
     Wochenraster (grid) als auch im Listen-Modus (über die Legende) gerendert. --}}
@if(!empty($content['kostformen']))
    <p class="pt-legend" style="border:0; margin-top:14px;"><strong>Kostformen (Werktage):</strong>
        @foreach($content['kostformen'] as $kf)
            <span style="margin-right:14px;">{{ $kf['label'] ?? '' }}: @if($kf['erfuellt'] ?? false)täglich ✓@elseif(($kf['abgedeckt'] ?? 0) > 0){{ $kf['abgedeckt'] }}/{{ $kf['tage'] ?? 5 }}@else—@endif</span>
        @endforeach
    </p>
@endif

@php $nw = $content['naehrwerte'] ?? null; @endphp
@if($nw && ($nw['tage_mit_daten'] ?? 0) > 0)
    @php $n = $nw['schnitt'] ?? []; @endphp
    <p class="pt-legend" style="border:0; margin-top:4px;"><strong>Ø Nährwerte/Person/Tag:</strong>
        <span style="margin-right:14px;">kcal {{ ($n['kcal'] ?? null) !== null ? number_format($n['kcal'], 0, ',', '.') : '—' }}</span>
        <span style="margin-right:14px;">Eiweiß {{ ($n['protein_g'] ?? null) !== null ? number_format($n['protein_g'], 1, ',', '.') . ' g' : '—' }}</span>
        <span style="margin-right:14px;">Fett {{ ($n['fett_g'] ?? null) !== null ? number_format($n['fett_g'], 1, ',', '.') . ' g' : '—' }}</span>
        <span style="margin-right:14px;">Salz {{ ($n['salz_g'] ?? null) !== null ? number_format($n['salz_g'], 2, ',', '.') . ' g' : '—' }}</span>
    </p>
@endif
