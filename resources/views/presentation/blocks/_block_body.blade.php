{{-- Ein Block innerhalb einer Kapitel-Sektion: Kopf/Zeile + Untertitel + eingerückte Gericht-Zeilen. --}}
@php
    $showPrice = $style['show_price'] ?? true;
    $showCodes = $style['show_codes'] ?? true;
    $showFotos = $style['show_dish_photos'] ?? false;   // Gericht-Fotos nur wenn im Design aktiviert
    $showLinien = $style['show_rules'] ?? true;           // Trennlinien zwischen den Zeilen
    $showKonzeptName = $style['show_block_header'] ?? true; // Konzept-/Paketname über der Liste
    $letzterTitel = null;                                  // Stationstitel direkt doppelt (Textblock-Titel = folgende Gang-Überschrift) nur einmal zeigen
    $blockPrice = null;
    // Null-Preise ausblenden (unpreise Konzepte zeigen sonst „0,00 €").
    if ($showPrice && ! empty($b['price']) && ((float) ($b['price']['pp'] ?? 0) > 0 || (float) ($b['price']['pauschal'] ?? 0) > 0)) {
        $blockPrice = number_format((float) ($b['price']['pp'] ?? 0), 2, ',', '.') . ' €' . (($b['price']['unit'] ?? null) === 'gast' ? ' /Pers.' : '');
    }
@endphp
{{--
  Semantische Klassen (für Design-CSS, Liste in presentation_designs.POST/PUT):
  .pt-block--{kind} · .pt-block-header(--{kind}) · .pt-text-block(-title|-body) · .pt-line--concept
  .pt-line--paket (.pt-paket-name) · .pt-line--dish (.pt-dish-name) · .pt-item-sub
  Bestehende Klassen (pt-line, pt-item, pt-indent-N, pt-line-label …) bleiben für alte Designs erhalten.
--}}
@php($bKind = preg_replace('/[^a-z0-9_-]/', '', strtolower((string) ($b['kind'] ?? ''))))
<div class="pt-block{{ $bKind !== '' ? ' pt-block--' . $bKind : '' }}{{ $showLinien ? '' : ' pt-ohne-linien' }}">
    @if(!empty($b['is_header']))
        <h3 class="pt-block-header pt-block-header--header">{{ $b['label'] }}@if($showCodes && !empty($b['codes']))<span class="pt-codes">{{ implode(' ', array_map('strval', $b['codes'])) }}</span>@endif</h3>
    @elseif($bKind === 'text')
        {{-- Freitext-Block des Angebots: kein Überschriften-Look mehr (vorher h3.pt-block-header). --}}
        @if(!empty($b['label']))<div class="pt-text-block pt-text-block--angebot"><p class="pt-text-block-body">{{ $b['label'] }}</p></div>@endif
    @elseif(!empty($b['label']) && $blockPrice !== null)
        <div class="pt-line pt-line--concept">
            <span class="pt-line-label">{{ $b['label'] }}@if($showCodes && !empty($b['codes']))<span class="pt-codes">{{ implode(' ', array_map('strval', $b['codes'])) }}</span>@endif</span>
            <span class="pt-line-dots" aria-hidden="true"></span>
            <span class="pt-line-price">{{ $blockPrice }}</span>
        </div>
    @elseif(!empty($b['label']) && $showKonzeptName)
        <h3 class="pt-block-header{{ $bKind !== '' ? ' pt-block-header--' . $bKind : '' }}">{{ $b['label'] }}@if($showCodes && !empty($b['codes']))<span class="pt-codes">{{ implode(' ', array_map('strval', $b['codes'])) }}</span>@endif</h3>
    @endif

    @if(!empty($b['subtitle']))<p class="pt-block-sub">{{ $b['subtitle'] }}</p>@endif

    @foreach(($b['items'] ?? []) as $it)
        @php($ind = min((int) ($it['indent'] ?? 0), 2))
        @php($iKind = $it['kind'] ?? 'gericht')
        @php($istDoppelt = $iKind === 'header' && $letzterTitel !== null && mb_strtolower(trim((string) $it['label'])) === $letzterTitel)
        @php($letzterTitel = $iKind === 'text' && ! empty($it['title']) ? mb_strtolower(trim((string) $it['title'])) : null)
        @if($istDoppelt)
            {{-- gleiche Überschrift wie der Textblock direkt davor → nicht nochmal zeigen --}}
        @elseif($iKind === 'header')
            {{-- Konzept-interne Gang-Überschrift (AUMSE/STARTER/…) — fett abgesetzt, wie im PDF. --}}
            <h4 class="pt-subheader pt-indent-{{ $ind }}">{{ $it['label'] }}@if($showCodes && !empty($it['codes']))<span class="pt-codes">{{ implode(' ', array_map('strval', $it['codes'])) }}</span>@endif</h4>
        @elseif($iKind === 'text')
            {{-- Text-Block aus dem Konzept (z. B. Stationstext zwischen den Paketen). --}}
            <div class="pt-text-block pt-text-block--konzept pt-indent-{{ $ind }}">
                @if(!empty($it['title']))<h4 class="pt-text-block-title">{{ $it['title'] }}</h4>@endif
                @if(($it['label'] ?? '') !== '')<p class="pt-text-block-body">{{ $it['label'] }}</p>@endif
            </div>
        @else
            @php($lineKind = $iKind === 'paket' ? 'paket' : 'dish')
            <div class="pt-line pt-item pt-line--{{ $lineKind }} pt-indent-{{ $ind }} @if($showFotos && !empty($it['image']['url'])) pt-item--foto @endif">
                @if($showFotos && !empty($it['image']['url']))
                    <img class="pt-item-foto pt-zoomable" src="{{ $it['image']['url'] }}" alt="" loading="lazy">
                @endif
                <span class="pt-line-label {{ $lineKind === 'paket' ? 'pt-paket-name' : 'pt-dish-name' }}">{{ $it['label'] }}@if($showCodes && !empty($it['codes']))<span class="pt-codes">{{ implode(' ', array_map('strval', $it['codes'])) }}</span>@endif@if(!empty($it['subtitle']))<span class="pt-item-sub"> — {{ $it['subtitle'] }}</span>@endif</span>
                @if($showPrice && ($it['price'] ?? null) !== null)
                    <span class="pt-line-dots" aria-hidden="true"></span>
                    <span class="pt-line-price">{{ number_format((float) $it['price'], 2, ',', '.') }} €</span>
                @endif
            </div>
        @endif
    @endforeach
</div>
