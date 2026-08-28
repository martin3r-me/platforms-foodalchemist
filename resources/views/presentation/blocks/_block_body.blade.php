{{-- Ein Block innerhalb einer Kapitel-Sektion: Kopf/Zeile + Untertitel + eingerückte Gericht-Zeilen. --}}
@php
    $showPrice = $style['show_price'] ?? true;
    $showCodes = $style['show_codes'] ?? true;
    $blockPrice = null;
    // Null-Preise ausblenden (unpreise Konzepte zeigen sonst „0,00 €").
    if ($showPrice && ! empty($b['price']) && ((float) ($b['price']['pp'] ?? 0) > 0 || (float) ($b['price']['pauschal'] ?? 0) > 0)) {
        $blockPrice = number_format((float) ($b['price']['pp'] ?? 0), 2, ',', '.') . ' €' . (($b['price']['unit'] ?? null) === 'gast' ? ' /Pers.' : '');
    }
@endphp
<div class="pt-block">
    @if(!empty($b['is_header']))
        <h3 class="pt-block-header">{{ $b['label'] }}@if($showCodes && !empty($b['codes']))<span class="pt-codes">{{ implode(' ', array_map('strval', $b['codes'])) }}</span>@endif</h3>
    @elseif(!empty($b['label']) && $blockPrice !== null)
        <div class="pt-line">
            <span class="pt-line-label">{{ $b['label'] }}@if($showCodes && !empty($b['codes']))<span class="pt-codes">{{ implode(' ', array_map('strval', $b['codes'])) }}</span>@endif</span>
            <span class="pt-line-dots" aria-hidden="true"></span>
            <span class="pt-line-price">{{ $blockPrice }}</span>
        </div>
    @elseif(!empty($b['label']))
        <h3 class="pt-block-header">{{ $b['label'] }}@if($showCodes && !empty($b['codes']))<span class="pt-codes">{{ implode(' ', array_map('strval', $b['codes'])) }}</span>@endif</h3>
    @endif

    @if(!empty($b['subtitle']))<p class="pt-block-sub">{{ $b['subtitle'] }}</p>@endif

    @foreach(($b['items'] ?? []) as $it)
        <div class="pt-line pt-item pt-indent-{{ min((int) ($it['indent'] ?? 0), 2) }}">
            <span class="pt-line-label">{{ $it['label'] }}@if($showCodes && !empty($it['codes']))<span class="pt-codes">{{ implode(' ', array_map('strval', $it['codes'])) }}</span>@endif@if(!empty($it['subtitle']))<span style="color:var(--pt-muted); font-weight:400"> — {{ $it['subtitle'] }}</span>@endif</span>
            @if($showPrice && ($it['price'] ?? null) !== null)
                <span class="pt-line-dots" aria-hidden="true"></span>
                <span class="pt-line-price">{{ number_format((float) $it['price'], 2, ',', '.') }} €</span>
            @endif
        </div>
    @endforeach
</div>
