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
                                            <div style="margin-bottom:4px;">{{ $cell['label'] ?? '' }}@if(!empty($cell['codes']))<span class="pt-codes">{{ implode(' ', array_map('strval', $cell['codes'])) }}</span>@endif@if(!empty($cell['price']))<span class="pt-price" style="margin-left:.4em;white-space:nowrap;opacity:.85;font-variant-numeric:tabular-nums;">{{ $cell['price'] }}</span>@endif</div>
                                        @endforeach
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </section>
@endif
