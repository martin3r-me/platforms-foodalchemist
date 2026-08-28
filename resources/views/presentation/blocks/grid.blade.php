{{-- Speiseplan-Wochenraster (Menü-Linien × Tage) + Kostformen + DGE-Ø-Nährwerte + LMIV. --}}
@php $grid = $content['grid'] ?? null; @endphp
@if($grid && !empty($grid['lines']))
    <section class="pt-section pt-reveal">
        <div class="pt-wide">
            <div style="overflow-x:auto">
                <table class="pt-grid">
                    <thead>
                        <tr>
                            <th>&nbsp;</th>
                            @foreach(($grid['tage'] ?? []) as $tag)
                                <th>{{ $tag['label'] ?? '' }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($grid['lines'] as $line)
                            <tr>
                                <th style="white-space:nowrap;">
                                    @if(!empty($line['color']))<span style="display:inline-block; width:8px; height:8px; border-radius:99px; background:{{ $line['color'] }}; margin-right:6px;"></span>@endif
                                    {{ $line['name'] ?? '' }}
                                </th>
                                @foreach(($grid['tage'] ?? []) as $tag)
                                    @php $cells = $line['cells'][$tag['key'] ?? ''] ?? []; @endphp
                                    <td>
                                        @foreach($cells as $cell)
                                            <div style="margin-bottom:4px;">{{ $cell['label'] ?? '' }}@if(!empty($cell['codes']))<span class="pt-codes">{{ implode(' ', array_map('strval', $cell['codes'])) }}</span>@endif</div>
                                        @endforeach
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

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
        </div>
    </section>
@endif
