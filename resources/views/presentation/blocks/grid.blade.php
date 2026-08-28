{{-- Speiseplan-Wochenraster (Menü-Linien × Tage) + Kostformen + DGE-Ø-Nährwerte + LMIV. --}}
@php $grid = $content['grid'] ?? null; @endphp
@if($grid && !empty($grid['lines']))
    <div style="overflow-x:auto">
        <table class="pt-grid" style="width:100%; border-collapse:collapse; margin: var(--pt-gap) 0;">
            <thead>
                <tr>
                    <th style="text-align:left; padding:8px; border-bottom:2px solid color-mix(in srgb, var(--pt-accent) 40%, transparent);">&nbsp;</th>
                    @foreach(($grid['tage'] ?? []) as $tag)
                        <th style="text-align:left; padding:8px; color:var(--pt-primary); border-bottom:2px solid color-mix(in srgb, var(--pt-accent) 40%, transparent); white-space:nowrap;">{{ $tag['label'] ?? '' }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach($grid['lines'] as $line)
                    <tr>
                        <th style="text-align:left; padding:8px; white-space:nowrap; border-bottom:1px solid color-mix(in srgb, var(--pt-text) 10%, transparent);">
                            @if(!empty($line['color']))<span style="display:inline-block; width:8px; height:8px; border-radius:99px; background:{{ $line['color'] }}; margin-right:6px;"></span>@endif
                            {{ $line['name'] ?? '' }}
                        </th>
                        @foreach(($grid['tage'] ?? []) as $tag)
                            @php $cells = $line['cells'][$tag['key'] ?? ''] ?? []; @endphp
                            <td style="padding:8px; vertical-align:top; border-bottom:1px solid color-mix(in srgb, var(--pt-text) 10%, transparent);">
                                @foreach($cells as $cell)
                                    <div style="margin-bottom:4px;">{{ $cell['label'] ?? '' }}@if(!empty($cell['codes'])) <span class="pt-codes">{{ implode(' ', array_map('strval', $cell['codes'])) }}</span>@endif</div>
                                @endforeach
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @if(!empty($content['kostformen']))
        <p class="pt-legend" style="border:0; margin-top:8px;"><strong>Kostformen (Werktage):</strong>
            @foreach($content['kostformen'] as $kf)
                <span style="margin-right:12px;">{{ $kf['label'] ?? '' }}: @if($kf['erfuellt'] ?? false)täglich ✓@elseif(($kf['abgedeckt'] ?? 0) > 0){{ $kf['abgedeckt'] }}/{{ $kf['tage'] ?? 5 }}@else—@endif</span>
            @endforeach
        </p>
    @endif

    @php $nw = $content['naehrwerte'] ?? null; @endphp
    @if($nw && ($nw['tage_mit_daten'] ?? 0) > 0)
        @php $n = $nw['schnitt'] ?? []; @endphp
        <p class="pt-legend" style="border:0; margin-top:4px;"><strong>Ø Nährwerte/Person/Tag:</strong>
            <span style="margin-right:12px;">kcal {{ ($n['kcal'] ?? null) !== null ? number_format($n['kcal'], 0, ',', '.') : '—' }}</span>
            <span style="margin-right:12px;">Eiweiß {{ ($n['protein_g'] ?? null) !== null ? number_format($n['protein_g'], 1, ',', '.') . ' g' : '—' }}</span>
            <span style="margin-right:12px;">Fett {{ ($n['fett_g'] ?? null) !== null ? number_format($n['fett_g'], 1, ',', '.') . ' g' : '—' }}</span>
            <span style="margin-right:12px;">Salz {{ ($n['salz_g'] ?? null) !== null ? number_format($n['salz_g'], 2, ',', '.') . ' g' : '—' }}</span>
        </p>
    @endif
@endif
