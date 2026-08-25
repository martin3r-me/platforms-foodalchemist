<!DOCTYPE html>
<html lang="de">
@php($istIntern = $intern ?? false)
@php($pdf = $istPdf ?? false)
{{-- F3: Format-Druck — schöne Kunden-Ausgabe im Foodbook-Look. Formate tragen (noch) keine
     eigene Branding-Farbe → fixe Marken-Violett wie der Foodbook-Default. DomPDF-Leitplanken
     wie foodbook.blade: keine CSS-Variablen, kein Flex/Grid, Bilder base64, Bänder fixed. --}}
@php($brand = '#6d28d9')
@php($band = $brand)
@php($footerText = 'Erstellt mit Food Alchemist')
@php($brandRgb = [0.427, 0.157, 0.851])
<head>
    <meta charset="utf-8">
    <title>Format — {{ $name }}{{ $istIntern ? ' · INTERN' : '' }}</title>
    <style>
        @page { margin: {{ $pdf ? '2.4cm 1.5cm 1.7cm 1.5cm' : '0' }}; }
        * { box-sizing: border-box; }
        body { font-family: "DejaVu Sans", Arial, sans-serif; color: #1f2937; font-size: 12px; line-height: 1.55; margin: 0; padding: 0; }
        .doc { max-width: {{ $pdf ? 'none' : '760px' }}; margin: 0 auto; padding: {{ $pdf ? '0' : '2.4cm 1.5cm 1.7cm 1.5cm' }}; }

        /* ── Wiederkehrende Bänder (wie foodbook.blade) ── */
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

        /* ── Cover ── */
        .cover { {{ $pdf ? 'page-break-after: always;' : 'border-bottom: 2px dashed #e5e7eb; margin-bottom: 28px;' }} padding-top: {{ $pdf ? '1.4cm' : '0' }}; }
        .cover-kicker { font-size: 11px; letter-spacing: .16em; text-transform: uppercase; color: #6b7280; }
        .cover-title { font-size: 34px; font-weight: bold; color: #111827; margin: 4px 0 2px; }
        .cover-claim { font-size: 18px; font-style: italic; color: {{ $brand }}; margin: 2px 0 0; }
        .cover-rule { height: 4px; width: 4.5cm; background: {{ $brand }}; margin: 14px 0 16px; border-radius: 2px; }
        table.cover-meta { border-collapse: collapse; margin-bottom: 18px; }
        table.cover-meta td { padding: 2px 10px 2px 0; vertical-align: top; }
        table.cover-meta .k { color: #6b7280; width: 3.2cm; }
        .cover-story { color: #4b5563; font-size: 12px; margin: 10px 0 4px; white-space: pre-line; }
        .cover-photo { margin-top: 10px; }
        .cover-photo img { max-width: 100%; max-height: 11cm; border-radius: 6px; }
        .badge-intern { display: inline-block; font-size: 10px; font-weight: bold; letter-spacing: .06em; background: {{ $brand }}; color: #fff; padding: 3px 10px; border-radius: 10px; margin-top: 10px; }

        .section-title { font-size: 13px; font-weight: bold; letter-spacing: .06em; text-transform: uppercase; color: #111827; border-bottom: 2px solid {{ $brand }}; padding-bottom: 6px; margin: 0 0 12px; }

        /* ── Editionen (wie foodbook-Kapitel) ── */
        .kapitel { margin-bottom: 16px; }
        .kapitel h3 { color: #111827; margin: 22px 0 6px; border-bottom: 1px solid #e5e7eb; padding-bottom: 4px; font-weight: bold; font-size: 15px; }
        .kapitel h3 .pipe { color: {{ $brand }}; font-weight: bold; }
        .kapitel:first-of-type h3 { margin-top: 4px; }
        .kapitel .kaptext { color: #4b5563; font-size: 11px; margin: 0 0 8px; }
        .kapitel .kpreis { float: right; color: #6b7280; font-size: 11px; font-weight: normal; }

        table.cblock { width: 100%; border-collapse: collapse; margin: 12px 0; }
        table.cblock td { vertical-align: top; padding: 0; }
        td.cprice { width: 3.6cm; padding-right: 10px; }
        td.cprice .val { font-weight: bold; color: #111827; font-size: 13px; }
        td.cprice .basis { color: #6b7280; font-style: italic; font-size: 11px; }
        .ctag { color: {{ $brand }}; font-size: 11px; font-style: italic; margin: 1px 0 3px; }
        .dish { color: #374151; padding: 1px 0; }
        .dish .pipe { color: {{ $brand }}; font-weight: bold; margin-right: 5px; }
        .dish.paket { font-weight: bold; color: #111827; }
        .pos.header { font-weight: bold; color: #111827; margin: 12px 0 2px; font-size: 13px; }
        .pos.text { color: #374151; margin: 4px 0; white-space: pre-line; }
        .pos.spacer.klein { height: 8px; }
        .pos.spacer.mittel { height: 16px; }
        .pos.spacer.gross { height: 28px; }
        .leer { color: #cbd5e1; }

        /* ── Preis-Range ── */
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
    <span class="bt-label">{{ $name }}{{ $istIntern ? ' · INTERN' : '' }}</span>
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

    {{-- ── COVER (Format-Identität) ── --}}
    <div class="cover">
        <div class="cover-kicker">Format</div>
        <div class="cover-title">{{ $consumer_name ?: $name }}</div>
        @if($claim)<div class="cover-claim">„{{ $claim }}"</div>@endif
        <div class="cover-rule"></div>
        <table class="cover-meta">
            @if($range['min'] !== null)
                <tr><td class="k">Preisspanne</td><td>{{ $range['min'] === $range['max']
                    ? number_format($range['min'], 2, ',', '.') . ' € pro Gast'
                    : number_format($range['min'], 2, ',', '.') . '–' . number_format($range['max'], 2, ',', '.') . ' € pro Gast' }}</td></tr>
            @endif
            @if($stand ?? null)<tr><td class="k">Stand</td><td>{{ $stand->format('d.m.Y') }}</td></tr>@endif
        </table>
        @if($story)<div class="cover-story">{{ $story }}</div>@endif
        @if($hero)<div class="cover-photo"><img src="{{ $hero }}" alt=""></div>@endif
        @if($istIntern)<div class="badge-intern">INTERN · Projektleitung / Vertrieb</div>@endif
    </div>

    {{-- ── POSITIONEN (Editionen wie Foodbook-Kapitel + Struktur-Blöcke) ── --}}
    @forelse($positionen as $pos)
        @if($pos['kind'] === 'edition')
            <div class="kapitel">
                <h3>
                    @if(($pos['preis_pp'] ?? null) !== null && $pos['preis_pp'] > 0)
                        <span class="kpreis">{{ number_format($pos['preis_pp'], 2, ',', '.') }} €/Gast</span>
                    @endif
                    <span class="pipe">|</span> {{ $pos['title'] }}
                </h3>
                @if($pos['claim'] ?? null)<div class="ctag">„{{ $pos['claim'] }}"</div>@endif
                @if(! empty($pos['text']))<div class="kaptext">{!! nl2br(e($pos['text'])) !!}</div>@endif

                <table class="cblock">
                    <tr>
                        <td class="cprice">
                            @if(($pos['preis_pp'] ?? null) !== null && $pos['preis_pp'] > 0)
                                <div class="val">{{ number_format($pos['preis_pp'], 2, ',', '.') }} €</div><div class="basis">pro Gast</div>
                            @endif
                        </td>
                        <td class="cbody">
                            @forelse($pos['gerichte'] ?? [] as $g)
                                @if(($g['type'] ?? '') === 'paket')
                                    <div class="dish paket" style="margin-left: {{ ($g['einrueckung'] ?? 0) * 12 }}px">{{ $g['text'] }}@if(($g['preis'] ?? null) !== null && $g['preis'] > 0)<span class="kpreis">{{ number_format($g['preis'], 2, ',', '.') }} €/Gast</span>@endif</div>
                                @elseif(($g['type'] ?? '') === 'header')
                                    <div class="dish paket" style="margin-left: {{ ($g['einrueckung'] ?? 0) * 12 }}px">{{ $g['text'] }}</div>
                                @else
                                    <div class="dish" style="margin-left: {{ ($g['einrueckung'] ?? 0) * 12 }}px"><span class="pipe">|</span>{{ $g['text'] }}</div>
                                @endif
                            @empty
                                <div class="dish leer">— noch keine Gerichte —</div>
                            @endforelse
                        </td>
                    </tr>
                </table>
            </div>
        @elseif($pos['kind'] === 'header')
            <div class="pos header">{{ $pos['text'] }}</div>
        @elseif($pos['kind'] === 'text')
            <div class="pos text">{{ $pos['text'] }}</div>
        @elseif($pos['kind'] === 'spacer')
            <div class="pos spacer {{ $pos['height'] ?? 'mittel' }}"></div>
        @endif
    @empty
        <p class="leer">Noch kein Aufbau — im Format-Editor Editionen einfügen.</p>
    @endforelse

    {{-- ── Preis-Range gesamt ── --}}
    @if($range['min'] !== null)
        <div class="price">
            <table>
                <tr>
                    <td class="total">Preisspanne pro Gast</td>
                    <td class="right total">{{ $range['min'] === $range['max']
                        ? number_format($range['min'], 2, ',', '.') . ' €'
                        : number_format($range['min'], 2, ',', '.') . '–' . number_format($range['max'], 2, ',', '.') . ' €' }}</td>
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
