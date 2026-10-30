{{-- Speiseplan-Wochenraster (Menü-Linien × Tage) + Kostformen + DGE-Ø-Nährwerte + LMIV. --}}
@php
    $grid = $content['grid'] ?? null;
    $showFotos = $style['show_dish_photos'] ?? false;   // Gericht-Fotos nur wenn im Design aktiviert
@endphp
@if($grid && !empty($grid['lines']))
    <section class="pt-section pt-reveal">
        <div class="pt-wide">
            <div class="pt-grid-rahmen" style="overflow-x:auto">
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
                                    {{-- Mit Fotos: Mindestbreite je Tag, damit alle Bilder gleich groß sind (Spalten wachsen sonst mit der Textlänge). --}}
                                    <td @class(['pt-grid-zelle--foto' => $showFotos])>
                                        @foreach($cells as $cell)
                                            <div style="margin-bottom:4px;">@if($showFotos && !empty($cell['image']['url']))<img class="pt-grid-foto pt-zoomable" src="{{ $cell['image']['url'] }}" alt="" loading="lazy">@endif{{ $cell['label'] ?? '' }}@if(!empty($cell['codes']))<span class="pt-codes">{{ implode(' ', array_map('strval', $cell['codes'])) }}</span>@endif@if(!empty($cell['price']))<span class="pt-price" style="margin-left:.4em;white-space:nowrap;opacity:.85;font-variant-numeric:tabular-nums;">{{ $cell['price'] }}</span>@endif</div>
                                        @endforeach
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            {{-- #1003: am Handy (≤ 640 px) dieselben Zellen als Tagesliste (Tag → Linie → Gericht) statt der breiten Tabelle;
                 Computer und Druck zeigen weiter die Tabelle. --}}
            <div class="pt-grid-tage">
                @foreach(($grid['tage'] ?? []) as $tag)
                    @php
                        $linien = collect($grid['lines'])->map(fn ($l) => ['line' => $l, 'cells' => $l['cells'][$tag['key'] ?? ''] ?? []])->filter(fn ($x) => $x['cells'] !== []);
                    @endphp
                    @if($linien->isNotEmpty())
                        <div class="pt-grid-tag">
                            <h3 class="pt-grid-tag-titel">{{ $tag['label'] ?? '' }}</h3>
                            @foreach($linien as $x)
                                <div class="pt-grid-tag-linie">
                                    <span class="pt-grid-tag-name">@if(!empty($x['line']['color']))<span class="pt-grid-punkt" style="background:{{ $x['line']['color'] }};"></span>@endif{{ $x['line']['name'] ?? '' }}</span>
                                    @foreach($x['cells'] as $cell)
                                        <div class="pt-grid-tag-gericht">@if($showFotos && !empty($cell['image']['url']))<img class="pt-grid-tag-foto pt-zoomable" src="{{ $cell['image']['url'] }}" alt="" loading="lazy">@endif{{ $cell['label'] ?? '' }}@if(!empty($cell['codes']))<span class="pt-codes">{{ implode(' ', array_map('strval', $cell['codes'])) }}</span>@endif@if(!empty($cell['price']))<span class="pt-price pt-grid-tag-preis">{{ $cell['price'] }}</span>@endif</div>
                                    @endforeach
                                </div>
                            @endforeach
                        </div>
                    @endif
                @endforeach
            </div>
        </div>
    </section>
@endif
