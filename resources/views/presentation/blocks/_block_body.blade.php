{{-- Ein einzelner Inhalts-Block (Kopf/Zeile + Untertitel + Gericht-Zeilen). Intern
     ge-@include't von chapter_loop und dish_list — kein Block-Typ (nicht user-adressierbar). --}}
@php
    $showPrice = $style['show_price'] ?? true;
    $showCodes = $style['show_codes'] ?? true;
    $codeStr = fn ($codes) => $showCodes && !empty($codes) ? implode(' ', array_map('strval', $codes)) : '';
@endphp
<div class="pt-block">
    @if($b['is_header'] ?? false)
        <h3 class="pt-block-header">{{ $b['label'] }}@if($codeStr($b['codes'] ?? [])) <span class="pt-codes">{{ $codeStr($b['codes'] ?? []) }}</span>@endif</h3>
    @elseif(!empty($b['label']))
        <div class="pt-line pt-block-line">
            <span class="pt-line-label">{{ $b['label'] }}@if($codeStr($b['codes'] ?? [])) <span class="pt-codes">{{ $codeStr($b['codes'] ?? []) }}</span>@endif</span>
            @if($showPrice && !empty($b['price']) && ($b['price']['pp'] ?? 0) > 0)
                <span class="pt-line-price">{{ number_format((float) $b['price']['pp'], 2, ',', '.') }} €@if(($b['price']['unit'] ?? null) === 'gast') <span class="pt-price-label">/Pers.</span>@endif</span>
            @endif
        </div>
    @endif

    @if(!empty($b['subtitle']))
        <p class="pt-block-sub">{{ $b['subtitle'] }}</p>
    @endif

    @foreach(($b['items'] ?? []) as $it)
        <div class="pt-line pt-item" style="padding-left: {{ (int) ($it['indent'] ?? 0) * 14 }}px">
            <span class="pt-line-label">{{ $it['label'] ?? '' }}@if($codeStr($it['codes'] ?? [])) <span class="pt-codes">{{ $codeStr($it['codes'] ?? []) }}</span>@endif</span>
            @if($showPrice && ($it['price'] ?? null) !== null)
                <span class="pt-line-price">{{ number_format((float) $it['price'], 2, ',', '.') }} €</span>
            @endif
        </div>
    @endforeach
</div>
