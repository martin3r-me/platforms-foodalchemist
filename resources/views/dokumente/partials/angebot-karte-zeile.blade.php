{{-- #380 Composer: eine Gäste-Menü-Zeile der Angebots-Karte. Erwartet $z (gerichtZeilen-Row) + $einzel (bool). --}}
@php($typ = $z['type'] ?? 'gericht')
@php($ml = (int) ($z['einrueckung'] ?? 0) * 12)
@php($zeigePreis = ($einzel ?? false) && ($z['preis'] ?? null) !== null && (float) $z['preis'] > 0)
@if($typ === 'header')
    <div class="subheader" style="margin-left: {{ $ml }}px">{{ $z['text'] }}</div>
@elseif($typ === 'paket')
    <div class="dish paket" style="margin-left: {{ $ml }}px">{{ $z['text'] }}@if($zeigePreis)<span class="dp">{{ number_format((float) $z['preis'], 2, ',', '.') }} €/Gast</span>@endif</div>
@else
    <div class="dish" style="margin-left: {{ $ml }}px"><span class="pipe">|</span>{{ $z['text'] }}@if($zeigePreis)<span class="dp">{{ number_format((float) $z['preis'], 2, ',', '.') }} €/Gast</span>@endif</div>
@endif
