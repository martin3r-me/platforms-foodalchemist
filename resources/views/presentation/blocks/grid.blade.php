{{-- Speiseplan-Wochenraster (Menü-Linien × Tage). Vollausbau in Phase 3 (Speiseplan);
     rendert defensiv aus content.grid, sobald normalizeSpeiseplan es liefert. --}}
@php $grid = $content['grid'] ?? null; @endphp
@if($grid && !empty($grid['lines']))
    <div style="overflow-x:auto">
        <table class="pt-grid" style="width:100%; border-collapse:collapse;">
            <thead>
                <tr>
                    <th style="text-align:left; padding:6px;">&nbsp;</th>
                    @foreach(($grid['tage'] ?? []) as $tag)
                        <th style="text-align:left; padding:6px; color:var(--pt-primary);">{{ $tag['label'] ?? '' }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach($grid['lines'] as $line)
                    <tr>
                        <th style="text-align:left; padding:6px; white-space:nowrap;">{{ $line['name'] ?? '' }}</th>
                        @foreach(($grid['tage'] ?? []) as $tag)
                            @php $cells = $line['cells'][$tag['key'] ?? ''] ?? []; @endphp
                            <td style="padding:6px; vertical-align:top;">
                                @foreach($cells as $cell)
                                    <div>{{ $cell['label'] ?? '' }}@if(!empty($cell['codes'])) <span class="pt-codes">{{ implode(' ', array_map('strval', $cell['codes'])) }}</span>@endif</div>
                                @endforeach
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
