<!DOCTYPE html>
<html lang="de">
@php($pdf = $istPdf ?? false)
{{-- F3: Concept-„Karte" — schöne Einzel-Concept-Ausgabe im Foodbook-Look (zweite Ausgabe
     neben dem technischen Report). Fixe Marken-Violett wie der Foodbook-Default; DomPDF-safe. --}}
@php($brand = '#6d28d9')
@php($band = $brand)
@php($footerText = 'Erstellt mit Food Alchemist')
@php($brandRgb = [0.427, 0.157, 0.851])
<head>
    <meta charset="utf-8">
    <title>Karte — {{ $titel }}</title>
    <style>
        @page { margin: {{ $pdf ? '2.4cm 1.5cm 1.7cm 1.5cm' : '0' }}; }
        * { box-sizing: border-box; }
        body { font-family: "DejaVu Sans", Arial, sans-serif; color: #1f2937; font-size: 12px; line-height: 1.55; margin: 0; padding: 0; }
        .doc { max-width: {{ $pdf ? 'none' : '680px' }}; margin: 0 auto; padding: {{ $pdf ? '0' : '2.4cm 1.5cm 1.7cm 1.5cm' }}; }

        .band-top {
            {{ $pdf ? 'position: fixed; top: -2.4cm; left: -1.5cm; width: 21cm;' : '' }}
            height: 1.4cm; background: {{ $band }}; color: #fff; padding: 0 1.5cm;
        }
        .band-top .bt-label { {{ $pdf ? 'display: block; padding-top: 0.52cm;' : 'line-height: 1.4cm;' }} font-size: 10px; letter-spacing: .08em; text-transform: uppercase; opacity: .92; }
        .band-bottom {
            {{ $pdf ? 'position: fixed; bottom: -1.7cm; left: -1.5cm; width: 21cm;' : '' }}
            height: 1.0cm; border-top: 2px solid {{ $brand }};
            color: #9ca3af; font-size: 9px; padding: 0 1.5cm;
        }
        .band-bottom .bb-foot { {{ $pdf ? 'display: block;' : '' }} line-height: 1.0cm; }

        .cover-kicker { font-size: 11px; letter-spacing: .16em; text-transform: uppercase; color: #6b7280; }
        .cover-title { font-size: 30px; font-weight: bold; color: #111827; margin: 4px 0 2px; }
        .cover-claim { font-size: 16px; font-style: italic; color: {{ $brand }}; margin: 2px 0 0; }
        .cover-rule { height: 4px; width: 4.5cm; background: {{ $brand }}; margin: 14px 0 12px; border-radius: 2px; }
        .cover-text { color: #4b5563; font-size: 12px; margin: 0 0 8px; white-space: pre-line; }

        .karte { margin: 14px 0; }
        .dish { color: #374151; padding: 2px 0; font-size: 13px; }
        .dish .pipe { color: {{ $brand }}; font-weight: bold; margin-right: 5px; }
        .dish.paket { font-weight: bold; color: #111827; margin-top: 6px; }
        .dish.header { font-weight: bold; color: #111827; margin: 12px 0 2px; font-size: 13px; text-transform: uppercase; letter-spacing: .04em; }
        .leer { color: #cbd5e1; }

        .price { margin-top: 22px; border-top: 2px solid {{ $brand }}; padding-top: 10px; }
        .price table { width: 100%; border-collapse: collapse; }
        .price td { padding: 4px 0; }
        .price .total { font-size: 18px; font-weight: bold; color: #111827; }
        .right { text-align: right; }

        .actions { margin-bottom: 18px; }
        .btn { display: inline-block; padding: 6px 12px; background: {{ $brand }}; color: #fff; text-decoration: none; border-radius: 6px; margin-right: 6px; }
        .btn.ghost { background: #eee; color: #374151; }
        @media print { .actions { display: none; } }
    </style>
</head>
<body>

<div class="band-top">
    <span class="bt-label">{{ $titel }}</span>
</div>

<div class="band-bottom">
    <span class="bb-foot">{{ $footerText }}</span>
</div>

<div class="doc">

    @unless($pdf)
        <div class="actions">
            <a class="btn" href="{{ request()->fullUrlWithQuery(['pdf' => 1]) }}">PDF herunterladen</a>
            <a class="btn ghost" href="javascript:window.print()">Drucken</a>
        </div>
    @endunless

    <div class="cover-kicker">Menü-Karte</div>
    <div class="cover-title">{{ $titel }}</div>
    @if($claim)<div class="cover-claim">„{{ $claim }}"</div>@endif
    <div class="cover-rule"></div>
    @if(! empty($text))<div class="cover-text">{!! nl2br(e($text)) !!}</div>@endif

    <div class="karte">
        @forelse($gerichte as $g)
            @if(($g['type'] ?? '') === 'header')
                <div class="dish header" style="margin-left: {{ ($g['einrueckung'] ?? 0) * 12 }}px">{{ $g['text'] }}</div>
            @elseif(($g['type'] ?? '') === 'paket')
                <div class="dish paket" style="margin-left: {{ ($g['einrueckung'] ?? 0) * 12 }}px">{{ $g['text'] }}@if(($g['preis'] ?? null) !== null && $g['preis'] > 0)<span style="float: right; font-weight: normal; color: #6b7280;">{{ number_format($g['preis'], 2, ',', '.') }} €/Gast</span>@endif</div>
            @else
                <div class="dish" style="margin-left: {{ ($g['einrueckung'] ?? 0) * 12 }}px"><span class="pipe">|</span>{{ $g['text'] }}</div>
            @endif
        @empty
            <div class="dish leer">— noch keine Gerichte —</div>
        @endforelse
    </div>

    @if(($preis_pp ?? null) !== null && $preis_pp > 0)
        <div class="price">
            <table>
                <tr>
                    <td class="total">Preis pro Gast</td>
                    <td class="right total">{{ number_format($preis_pp, 2, ',', '.') }} €</td>
                </tr>
            </table>
            @php($mwstSatz = ($mwst ?? null) ? (($mwst['default_satz'] ?? 'ermaessigt') === 'regulaer' ? ($mwst['regulaer'] ?? 19) : ($mwst['ermaessigt'] ?? 7)) : null)
            @php($mwstText = 'Alle Preise netto' . ($mwstSatz !== null ? ' zzgl. gesetzl. MwSt (' . rtrim(rtrim(number_format((float) $mwstSatz, 1, ',', '.'), '0'), ',') . ' %)' : '') . '.')
            <div style="color:#9ca3af; font-size:10px; margin-top:6px">{{ $mwstText }}</div>
        </div>
    @endif

</div>

@if($pdf)
    <script type="text/php">
        if (isset($pdf)) {
            $font = $fontMetrics->getFont("DejaVu Sans", "bold");
            $size = 8;
            $x = $pdf->get_width() - 66;
            $y = $pdf->get_height() - 30;
            $pdf->page_text($x, $y, "{PAGE_NUM} / {PAGE_COUNT}", $font, $size, array({{ $brandRgb[0] }}, {{ $brandRgb[1] }}, {{ $brandRgb[2] }}));
        }
    </script>
@endif
</body>
</html>
