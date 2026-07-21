<!DOCTYPE html>
<html lang="de">
@php($istIntern = $intern ?? false)
@php($pdf = $istPdf ?? false)
{{-- Marken-Tokens (pro Foodbook) mit Defaults; DomPDF: kein var(), Farben per Blade-Echo, Bilder base64 --}}
@php($b = ($branding ?? []) + ['color' => '#6d28d9', 'band' => '#6d28d9', 'logo' => null, 'cover' => null, 'footer' => null])
@php($brand = $b['color'] ?: '#6d28d9')
@php($band = $b['band'] ?: $brand)
@php($footerText = $b['footer'] ?: 'Erstellt mit Food Alchemist')
@php($bcHex = ltrim($brand, '#'))
@php($brandRgb = strlen($bcHex) === 6
    ? [round(hexdec(substr($bcHex, 0, 2)) / 255, 3), round(hexdec(substr($bcHex, 2, 2)) / 255, 3), round(hexdec(substr($bcHex, 4, 2)) / 255, 3)]
    : [0.427, 0.157, 0.851])
<head>
    <meta charset="utf-8">
    <title>Foodbook — {{ $fb->label }}{{ $istIntern ? ' · INTERN' : '' }}</title>
    <style>
        /* DomPDF-Leitplanken: keine CSS-Variablen, kein Flex/Grid, Bilder base64, Bänder fixed, Seitenzahl via counter() */
        @page { margin: {{ $pdf ? '2.4cm 1.5cm 1.7cm 1.5cm' : '0' }}; }
        * { box-sizing: border-box; }
        body { font-family: "DejaVu Sans", Arial, sans-serif; color: #1f2937; font-size: 12px; line-height: 1.55; margin: 0; padding: 0; }
        .doc { max-width: {{ $pdf ? 'none' : '760px' }}; margin: 0 auto; padding: {{ $pdf ? '0' : '2.4cm 1.5cm 1.7cm 1.5cm' }}; }

        /* ── Wiederkehrende Bänder ── DomPDF: fixed ist relativ zur Content-Box, darum per
           Negativ-Offset (= @page-Rand) in den Seitenrand heben + full-bleed via left/width. */
        .band-top {
            {{ $pdf ? 'position: fixed; top: -2.4cm; left: -1.5cm; width: 21cm;' : '' }}
            height: 1.4cm; background: {{ $band }}; color: #fff; padding: 0 1.5cm;
        }
        .band-top .bt-label { float: left; font-size: 10px; letter-spacing: .08em; text-transform: uppercase; line-height: 1; padding-top: 0.52cm; opacity: .92; }
        .band-top .bt-logo { float: right; }
        .band-top .bt-logo img { max-height: 0.85cm; max-width: 5cm; margin-top: 0.28cm; }
        .band-bottom {
            {{ $pdf ? 'position: fixed; bottom: -1.7cm; left: -1.5cm; width: 21cm;' : '' }}
            height: 1.0cm; border-top: 2px solid {{ $brand }};
            color: #9ca3af; font-size: 9px; padding: 0 1.5cm;
        }
        .band-bottom .bb-foot { float: left; line-height: 1.0cm; }

        /* ── Cover ── */
        .cover { {{ $pdf ? 'page-break-after: always;' : 'border-bottom: 2px dashed #e5e7eb; margin-bottom: 28px;' }} padding-top: {{ $pdf ? '1.4cm' : '0' }}; }
        .cover-logo { max-height: 1.8cm; max-width: 8cm; margin-bottom: .8cm; }
        .cover-kicker { font-size: 11px; letter-spacing: .16em; text-transform: uppercase; color: #6b7280; }
        .cover-title { font-size: 34px; font-weight: bold; color: #111827; margin: 4px 0 2px; }
        .cover-year { font-size: 20px; color: #6b7280; }
        .cover-rule { height: 4px; width: 4.5cm; background: {{ $brand }}; margin: 14px 0 16px; border-radius: 2px; }
        table.cover-meta { border-collapse: collapse; margin-bottom: 18px; }
        table.cover-meta td { padding: 2px 10px 2px 0; vertical-align: top; }
        table.cover-meta .k { color: #6b7280; width: 3.2cm; }
        .cover-photo { margin-top: 10px; }
        .cover-photo img { max-width: 100%; max-height: 11cm; border-radius: 6px; }
        .badge-intern { display: inline-block; font-size: 10px; font-weight: bold; letter-spacing: .06em; background: {{ $brand }}; color: #fff; padding: 3px 10px; border-radius: 10px; margin-top: 10px; }

        /* ── Inhaltsverzeichnis ── */
        .toc { {{ $pdf ? 'page-break-after: always;' : 'margin-bottom: 24px;' }} }
        .toc h2, .section-title { font-size: 13px; font-weight: bold; letter-spacing: .06em; text-transform: uppercase; color: #111827; border-bottom: 2px solid {{ $brand }}; padding-bottom: 6px; margin: 0 0 12px; }
        .toc a { color: #374151; text-decoration: none; display: block; padding: 3px 0; border-bottom: 1px dotted #e5e7eb; }
        .toc a .np { float: right; color: #6b7280; }
        .toc a .pipe { color: {{ $brand }}; font-weight: bold; }

        /* ── Kapitel / Inhalt ── */
        .kapitel { margin-bottom: 16px; }
        .kapitel h2 { font-size: 15px; color: #111827; margin: 22px 0 6px; border-bottom: 1px solid #e5e7eb; padding-bottom: 4px; }
        .kapitel h2 .pipe { color: {{ $brand }}; font-weight: bold; }
        .kapitel:first-of-type h2 { margin-top: 4px; }
        .kapitel .kpreis { float: right; color: #6b7280; font-size: 11px; font-weight: normal; }
        .kapitel .kpreis .ek { color: #9333ea; }
        .kapitel .kpreis .wpz { color: #059669; }

        /* Konzept-Block: Preis links, Inhalt rechts (Referenz-Layout) */
        table.cblock { width: 100%; border-collapse: collapse; margin: 12px 0; page-break-inside: avoid; }
        table.cblock td { vertical-align: top; padding: 0; }
        td.cprice { width: 3.6cm; padding-right: 10px; }
        td.cprice .val { font-weight: bold; color: #111827; font-size: 13px; }
        td.cprice .basis { color: #6b7280; font-style: italic; font-size: 11px; }
        .cname { font-weight: bold; font-size: 13px; color: #111827; }
        .ctag { color: #6b7280; font-size: 10px; letter-spacing: .10em; text-transform: uppercase; margin: 1px 0 3px; }
        .dish { color: #374151; padding: 1px 0; }
        .dish .pipe { color: {{ $brand }}; font-weight: bold; margin-right: 5px; }
        .dish.paket { font-weight: bold; color: #111827; }
        .pos.header { font-weight: bold; color: #111827; margin: 10px 0 2px; }
        .pos.text { color: #374151; margin: 4px 0; }
        .leer { color: #cbd5e1; }

        /* ── Preise gesamt ── */
        .price { margin-top: 22px; border-top: 2px solid {{ $brand }}; padding-top: 10px; page-break-inside: avoid; }
        .price table { width: 100%; border-collapse: collapse; }
        .price td { padding: 4px 0; }
        .price .total { font-size: 18px; font-weight: bold; color: #111827; }
        .right { text-align: right; }
        .subtable { margin-top: 20px; page-break-inside: avoid; }
        .subtable table { width: 100%; border-collapse: collapse; }
        .subtable td { padding: 3px 0; }

        .actions { margin-bottom: 18px; }
        .btn { display: inline-block; padding: 6px 12px; background: {{ $brand }}; color: #fff; text-decoration: none; border-radius: 6px; margin-right: 6px; }
        .btn.ghost { background: #eee; color: #374151; }
        @media print { .actions { display: none; } }
    </style>
</head>
<body>

{{-- Wiederkehrendes Kopf-Band (Logo + Buchtitel) --}}
<div class="band-top">
    <span class="bt-label">{{ $fb->label }}{{ $istIntern ? ' · INTERN' : '' }}</span>
    @if($b['logo'])<span class="bt-logo"><img src="{{ $b['logo'] }}" alt=""></span>@endif
</div>

{{-- Wiederkehrendes Fuß-Band (Footer-Text links, Seitenzahl rechts via CSS-counter — greift auf jeder Seite) --}}
<div class="band-bottom">
    <span class="bb-foot">{{ $footerText }}@if($istIntern) · Interne Fassung — nicht an Kunden weitergeben @endif</span>
</div>

<div class="doc">

    @unless($pdf)
        <div class="actions">
            <a class="btn" href="{{ request()->fullUrlWithQuery(['pdf' => 1]) }}">PDF herunterladen</a>
            <a class="btn ghost" href="javascript:window.print()">Drucken</a>
            @if($istIntern)
                <a class="btn ghost" href="{{ request()->fullUrlWithQuery(['intern' => null, 'pdf' => null]) }}">→ Kundensicht</a>
            @else
                <a class="btn ghost" href="{{ request()->fullUrlWithQuery(['intern' => 1, 'pdf' => null]) }}">→ Interne Sicht (Marge)</a>
            @endif
        </div>
    @endunless

    {{-- ── COVER ── --}}
    <div class="cover">
        @if($b['logo'])<img class="cover-logo" src="{{ $b['logo'] }}" alt="">@endif
        <div class="cover-kicker">Foodbook / Portfolio</div>
        <div class="cover-title">{{ $fb->label }}</div>
        @if($fb->jahr)<div class="cover-year">{{ $fb->jahr }}</div>@endif
        <div class="cover-rule"></div>
        <table class="cover-meta">
            @if($customer)<tr><td class="k">Kunde</td><td>{{ $customer }}@if(($kontakt ?? null) && $kontakt !== $customer) · {{ $kontakt }} @endif</td></tr>@endif
            @if($gesamt['personen'])<tr><td class="k">Personen</td><td>{{ $gesamt['personen'] }}</td></tr>@endif
            @if($stand ?? null)<tr><td class="k">Stand</td><td>{{ $stand->format('d.m.Y') }}</td></tr>@endif
        </table>
        @if($b['cover'])<div class="cover-photo"><img src="{{ $b['cover'] }}" alt=""></div>@endif
        @if($istIntern)<div class="badge-intern">INTERN · Projektleitung / Vertrieb</div>@endif
    </div>

    {{-- ── INHALTSVERZEICHNIS (klickbare Anker; keine PDF-Outline) ── --}}
    @if(count($kapitel) > 0)
        <div class="toc">
            <div class="section-title">Inhaltsverzeichnis</div>
            @foreach($kapitel as $k)
                <a href="#{{ $k['anker'] }}" style="padding-left: {{ $k['depth'] * 16 }}px">
                    @if($k['vk_pro_person'] > 0)<span class="np">{{ number_format($k['vk_pro_person'], 2, ',', '.') }} €/P</span>@endif
                    <span class="pipe">|</span> {{ $istIntern ? ($k['title_intern'] ?: $k['title']) : $k['title'] }}
                </a>
            @endforeach
        </div>
    @endif

    {{-- ── INHALT ── --}}
    @forelse($kapitel as $k)
        <div class="kapitel" style="margin-left: {{ $k['depth'] * 14 }}px">
            <h2 id="{{ $k['anker'] }}">
                @if($k['vk_pro_person'] > 0)
                    <span class="kpreis">
                        {{ number_format($k['vk_pro_person'], 2, ',', '.') }} €/P
                        @if($istIntern && ($k['ek_pro_person'] ?? null) !== null)
                            · <span class="ek">EK {{ number_format($k['ek_pro_person'], 2, ',', '.') }} €/P</span>
                            @if(($k['food_cost_percent'] ?? null) !== null) · <span class="wpz">W {{ number_format($k['food_cost_percent'], 1, ',', '.') }} %</span> @endif
                        @endif
                    </span>
                @endif
                <span class="pipe">|</span> {{ $istIntern ? ($k['title_intern'] ?: $k['title']) : $k['title'] }}
            </h2>

            @forelse($k['bloecke'] as $blk)
                @php($istHeader = $blk['ist_header'] ?? false)
                @php($istText = ($blk['type'] ?? '') === 'text')
                @php($preisPP = (float) ($blk['preis_pp'] ?? 0))
                @php($pauschal = (float) ($blk['pauschal'] ?? 0))

                @if($istHeader)
                    <div class="pos header">{{ $blk['label'] }}</div>
                    @if($blk['untertitel'] ?? null)<div class="ctag">{{ $blk['untertitel'] }}</div>@endif
                @elseif($istText)
                    <div class="pos text">{{ $blk['label'] }}</div>
                @else
                    {{-- Konzept-/Gericht-Block: Preis links, Inhalt rechts --}}
                    <table class="cblock">
                        <tr>
                            <td class="cprice">
                                @if($preisPP > 0)
                                    <div class="val">{{ number_format($preisPP, 2, ',', '.') }} €</div><div class="basis">pro Person</div>
                                @elseif($pauschal > 0)
                                    <div class="val">{{ number_format($pauschal, 2, ',', '.') }} €</div><div class="basis">pauschal</div>
                                @endif
                            </td>
                            <td class="cbody">
                                <div class="cname">{{ $blk['label'] }}</div>
                                @if($blk['untertitel'] ?? null)<div class="ctag">{{ $blk['untertitel'] }}</div>@endif
                                @foreach($blk['gerichte'] ?? [] as $g)
                                    @if(($g['type'] ?? '') === 'paket' || ($g['type'] ?? '') === 'header')
                                        <div class="dish paket" style="margin-left: {{ ($g['einrueckung'] ?? 0) * 12 }}px">{{ $g['text'] }}</div>
                                    @else
                                        <div class="dish" style="margin-left: {{ ($g['einrueckung'] ?? 0) * 12 }}px"><span class="pipe">|</span>{{ $g['text'] }}</div>
                                    @endif
                                @endforeach
                            </td>
                        </tr>
                    </table>
                @endif
            @empty
                <div class="pos leer">—</div>
            @endforelse
        </div>
    @empty
        <p class="leer">Noch keine Kapitel angelegt.</p>
    @endforelse

    {{-- Intern: Wareneinsatz je Konzept (Top-Kapitel) --}}
    @if($istIntern && count($kapitel) > 0)
        <div class="subtable">
            <div class="section-title">Wareneinsatz je Konzept</div>
            <table>
                <tr style="color:#6b7280; font-size:10px; text-transform:uppercase; letter-spacing:.04em">
                    <td>Konzept</td><td class="right">€/P</td><td class="right" style="color:#9333ea">EK/P</td><td class="right" style="color:#059669">W %</td>
                </tr>
                @foreach($kapitel as $k)
                    @if(($k['depth'] ?? 0) === 0)
                        <tr>
                            <td>{{ $k['title_intern'] ?: $k['title'] }}</td>
                            <td class="right">{{ number_format($k['vk_pro_person'] ?? 0, 2, ',', '.') }} €</td>
                            <td class="right" style="color:#9333ea">{{ ($k['ek_pro_person'] ?? null) !== null ? number_format($k['ek_pro_person'], 2, ',', '.') . ' €' : '—' }}</td>
                            <td class="right" style="color:#059669">{{ ($k['food_cost_percent'] ?? null) !== null ? number_format($k['food_cost_percent'], 1, ',', '.') . ' %' : '—' }}</td>
                        </tr>
                    @endif
                @endforeach
            </table>
        </div>
    @endif

    {{-- Preise gesamt --}}
    <div class="price">
        <table>
            <tr><td>Preis pro Person</td><td class="right">{{ number_format($gesamt['vk_pro_person'], 2, ',', '.') }} €</td></tr>
            @if($istIntern)
                <tr><td style="color:#9333ea">Wareneinsatz pro Person</td><td class="right" style="color:#9333ea">{{ number_format($gesamt['ek_per_person'], 2, ',', '.') }} €</td></tr>
                @if($gesamt['vk_pro_person'] > 0)
                    <tr><td style="color:#059669">Wareneinsatz %</td><td class="right" style="color:#059669">{{ number_format($gesamt['ek_per_person'] / $gesamt['vk_pro_person'] * 100, 1, ',', '.') }} %</td></tr>
                @endif
            @endif
            @if($gesamt['personen'])<tr><td>Personen</td><td class="right">{{ $gesamt['personen'] }}</td></tr>@endif
            @if($gesamt['gesamt_vk'] !== null)<tr><td class="total">Gesamt</td><td class="right total">{{ number_format($gesamt['gesamt_vk'], 2, ',', '.') }} €</td></tr>@endif
            @if($istIntern && ($gesamt['gesamt_ek'] ?? null) !== null)<tr><td style="color:#9333ea">Gesamt-Wareneinsatz</td><td class="right" style="color:#9333ea">{{ number_format($gesamt['gesamt_ek'], 2, ',', '.') }} €</td></tr>@endif
        </table>
        @php($mwstSatz = ($mwst ?? null) ? (($mwst['default_satz'] ?? 'ermaessigt') === 'regulaer' ? ($mwst['regulaer'] ?? 19) : ($mwst['ermaessigt'] ?? 7)) : null)
        @php($mwstText = 'Alle Preise netto' . ($mwstSatz !== null ? ' zzgl. gesetzl. MwSt (' . rtrim(rtrim(number_format((float) $mwstSatz, 1, ',', '.'), '0'), ',') . ' %)' : '') . '.')
        <div style="color:#9ca3af; font-size:10px; margin-top:6px">{{ $mwstText }}</div>
    </div>

    @if($fb->description)
        <div style="margin-top:16px">{!! nl2br(e($fb->description)) !!}</div>
    @endif

</div>

@if($pdf)
    {{-- Seitenzahl auf jeder Seite: page_text am Body-Ende (nach vollständiger Paginierung) --}}
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
