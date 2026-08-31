{{-- Gesamt-Preis pro Person (nur wenn vorhanden — bei à la carte / preislos entfällt der Block).
     Bei Angeboten (MwSt-Daten im total) zusätzlich brutto (inkl. MwSt). --}}
@php
    $total = $content['total'] ?? null;
    $ppNetto = $total['vk_pro_person'] ?? null;
    $ppBrutto = $total['vk_pro_person_brutto'] ?? null;
    $ppSatz = $total['mwst_satz'] ?? null;
    $hatMwst = $ppBrutto !== null && $ppSatz !== null;
    $modus = $style['preis_anzeige'] ?? 'beide';   // netto | brutto | beide (Stil-Option, nur wenn MwSt-Daten da)
    $satzTxt = $ppSatz !== null ? rtrim(rtrim(number_format((float) $ppSatz, 1, ',', '.'), '0'), ',') : '';
    $nettoSuffix = ($hatMwst && $modus === 'beide') ? ' · netto' : '';   // kein geklebtes @if im Label (Blade-Direktive würde sonst wörtlich ausgegeben)
@endphp
@if($total && $ppNetto !== null && (float) $ppNetto > 0)
    <div class="pt-measure pt-reveal">
        <div class="pt-price-summary">
            @if($hatMwst && $modus === 'brutto')
                <span class="pt-price-big">{{ number_format((float) $ppBrutto, 2, ',', '.') }} €</span>
                <span class="pt-price-label">pro Person · inkl. {{ $satzTxt }} % MwSt</span>
            @else
                <span class="pt-price-big">{{ number_format((float) $ppNetto, 2, ',', '.') }} €</span>
                <span class="pt-price-label">pro Person{{ $nettoSuffix }}</span>
                @if($hatMwst && $modus === 'beide')
                    <span style="display:block;margin-top:.4rem;font-size:.95rem;color:var(--pt-muted,#6b7280);">
                        {{ number_format((float) $ppBrutto, 2, ',', '.') }} € pro Person inkl. {{ $satzTxt }} % MwSt
                    </span>
                @endif
            @endif
        </div>
    </div>
@endif
