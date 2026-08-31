<!DOCTYPE html>
<html lang="de">
@php($pdf = $istPdf ?? false)
{{-- #380 Composer: Angebots-„Karte" — schöne Kundenausgabe im Foodbook-Look (Kapitel + Format-Editionen
     als Gäste-Menü + Preis-Footer netto/MwSt/brutto). Fixe Marken-Violett; DomPDF-safe. --}}
@php($brand = '#6d28d9')
@php($band = $brand)
@php($footerText = 'Erstellt mit Food Alchemist')
@php($brandRgb = [0.427, 0.157, 0.851])
@php($kapitelListe = $komposition['kapitel'] ?? [])
@php($nf = fn ($v) => number_format((float) $v, 2, ',', '.'))
<head>
    <meta charset="utf-8">
    <title>Angebot — {{ $titel }}</title>
    <style>
        @page { margin: {{ $pdf ? '2.4cm 1.5cm 1.7cm 1.5cm' : '0' }}; }
        * { box-sizing: border-box; }
        body { font-family: "DejaVu Sans", Arial, sans-serif; color: #1f2937; font-size: 12px; line-height: 1.55; margin: 0; padding: 0; }
        .doc { max-width: {{ $pdf ? 'none' : '680px' }}; margin: 0 auto; padding: {{ $pdf ? '0' : '2.4cm 1.5cm 1.7cm 1.5cm' }}; }

        .band-top { {{ $pdf ? 'position: fixed; top: -2.4cm; left: -1.5cm; width: 21cm;' : '' }} height: 1.4cm; background: {{ $band }}; color: #fff; padding: 0 1.5cm; }
        .band-top .bt-label { {{ $pdf ? 'display: block; padding-top: 0.52cm;' : 'line-height: 1.4cm;' }} font-size: 10px; letter-spacing: .08em; text-transform: uppercase; opacity: .92; }
        .band-bottom { {{ $pdf ? 'position: fixed; bottom: -1.7cm; left: -1.5cm; width: 21cm;' : '' }} height: 1.0cm; border-top: 2px solid {{ $brand }}; color: #9ca3af; font-size: 9px; padding: 0 1.5cm; }
        .band-bottom .bb-foot { {{ $pdf ? 'display: block;' : '' }} line-height: 1.0cm; }

        .cover-kicker { font-size: 11px; letter-spacing: .16em; text-transform: uppercase; color: #6b7280; }
        .cover-title { font-size: 30px; font-weight: bold; color: #111827; margin: 4px 0 2px; }
        .cover-rule { height: 4px; width: 4.5cm; background: {{ $brand }}; margin: 14px 0 12px; border-radius: 2px; }
        .meta { color: #4b5563; font-size: 11px; margin: 0 0 4px; }
        .meta strong { color: #111827; }

        .kap { margin: 16px 0 0; }
        .kap-title { font-size: 15px; font-weight: bold; color: #111827; border-bottom: 1px solid #e5e7eb; padding-bottom: 3px; }
        .kap-title .kp { float: right; font-weight: normal; color: #6b7280; font-size: 12px; }
        .kap-text { color: #6b7280; font-size: 11px; font-style: italic; margin: 3px 0; }
        .edition-title { font-weight: bold; color: #111827; margin: 8px 0 2px; font-size: 13px; }
        .edition-title .kp { float: right; font-weight: normal; color: #6b7280; font-size: 12px; }
        .subheader { font-weight: bold; color: #111827; margin: 8px 0 1px; font-size: 12px; text-transform: uppercase; letter-spacing: .04em; }
        .dish { color: #374151; padding: 1px 0; font-size: 13px; }
        .dish .pipe { color: {{ $brand }}; font-weight: bold; margin-right: 5px; }
        .dish .dp { float: right; font-weight: normal; color: #6b7280; }
        .dish.paket { font-weight: bold; color: #111827; margin-top: 5px; }
        .free-text { color: #6b7280; font-size: 12px; font-style: italic; margin: 3px 0; }

        .price { margin-top: 24px; border-top: 2px solid {{ $brand }}; padding-top: 10px; }
        .price table { width: 100%; border-collapse: collapse; }
        .price td { padding: 3px 0; }
        .price .lbl { color: #4b5563; }
        .price .total { font-size: 18px; font-weight: bold; color: #111827; }
        .right { text-align: right; }
        .alt { color: #6b7280; font-size: 10px; margin-top: 6px; }

        .actions { margin-bottom: 18px; }
        .btn { display: inline-block; padding: 6px 12px; background: {{ $brand }}; color: #fff; text-decoration: none; border-radius: 6px; margin-right: 6px; }
        .btn.ghost { background: #eee; color: #374151; }
        @media print { .actions { display: none; } }
    </style>
</head>
<body>

<div class="band-top"><span class="bt-label">Angebot — {{ $titel }}</span></div>
<div class="band-bottom"><span class="bb-foot">{{ $footerText }}</span></div>

<div class="doc">

    @unless($pdf)
        <div class="actions">
            <a class="btn" href="{{ request()->fullUrlWithQuery(['pdf' => 1]) }}">PDF herunterladen</a>
            <a class="btn ghost" href="javascript:window.print()">Drucken</a>
        </div>
    @endunless

    <div class="cover-kicker">{{ $angebot->occasion ?: 'Angebot' }}</div>
    <div class="cover-title">{{ $titel }}</div>
    <div class="cover-rule"></div>
    @if($customer)<div class="meta"><strong>Kunde:</strong> {{ $customer }}@if($kontakt) · {{ $kontakt }}@endif</div>@endif
    <div class="meta">
        @if($angebot->location)<strong>Ort:</strong> {{ $angebot->location }} · @endif
        @if($angebot->event_date)<strong>Datum:</strong> {{ $angebot->event_date->format('d.m.Y') }} · @endif
        @if($pax > 0)<strong>Gäste:</strong> {{ $pax }} · @endif
        @if($angebot->valid_until)<strong>Gültig bis:</strong> {{ $angebot->valid_until->format('d.m.Y') }}@endif
    </div>

    @forelse($kapitelListe as $kap)
        <div class="kap">
            <div class="kap-title">{{ $kap['title'] }}
                @if($kap['ist_format'] && $kap['format_price_mode'] === 'alternativen' && $kap['preis_range'])
                    <span class="kp">{{ $kap['preis_range']['min'] !== null ? $nf($kap['preis_range']['min']) : '—' }}–{{ $kap['preis_range']['max'] !== null ? $nf($kap['preis_range']['max']) : '—' }} €/Gast</span>
                @elseif($kap['vk_pro_person'] !== null && $kap['vk_pro_person'] > 0)
                    <span class="kp">{{ $nf($kap['vk_pro_person']) }} €/Gast</span>
                @endif
            </div>
            @if($kap['text'])<div class="kap-text">{{ $kap['text'] }}</div>@endif

            @if($kap['ist_format'])
                @foreach($kap['editionen'] as $ed)
                    @if(in_array($ed['typ'] ?? '', ['header', 'spacer'], true))
                        @if(($ed['name'] ?? '') !== '')<div class="subheader">{{ $ed['name'] }}</div>@endif
                    @elseif(($ed['typ'] ?? '') === 'text')
                        @if(($ed['text'] ?? '') !== '')<div class="free-text">{{ $ed['text'] }}</div>@endif
                    @else
                        <div class="edition-title">{{ $ed['name'] }}@if(($ed['preis_pp'] ?? null) !== null && $ed['preis_pp'] > 0)<span class="kp">{{ $nf($ed['preis_pp']) }} €/Gast</span>@endif</div>
                        @foreach(($ed['gerichte'] ?? []) as $z)
                            @include('foodalchemist::dokumente.partials.angebot-karte-zeile', ['z' => $z, 'einzel' => $ed['einzelpreise'] ?? false, 'nf' => $nf])
                        @endforeach
                    @endif
                @endforeach
            @else
                @foreach($kap['bloecke'] as $b)
                    @if($b['ist_header'])
                        <div class="subheader">{{ $b['label'] }}</div>
                    @elseif($b['type'] === 'concept_ref')
                        @foreach(($b['gerichte'] ?? []) as $z)
                            @include('foodalchemist::dokumente.partials.angebot-karte-zeile', ['z' => $z, 'einzel' => $b['einzelpreise'] ?? false, 'nf' => $nf])
                        @endforeach
                    @elseif($b['type'] === 'text')
                        @if(($b['label'] ?? '') !== '')<div class="free-text">{{ $b['label'] }}</div>@endif
                    @endif
                @endforeach
            @endif
        </div>
    @empty
        <div class="kap-text">— noch kein Menü —</div>
    @endforelse

    @if($vk_pro_person > 0 || $netto_gesamt > 0)
        <div class="price">
            <table>
                <tr><td class="lbl">Preis pro Gast (netto)</td><td class="right">{{ $nf($vk_pro_person) }} €</td></tr>
                @if($pax > 0)<tr><td class="lbl">Gäste</td><td class="right">× {{ $pax }}</td></tr>@endif
                <tr><td class="lbl">Gesamt netto</td><td class="right">{{ $nf($netto_gesamt) }} €</td></tr>
                <tr><td class="lbl">zzgl. MwSt ({{ rtrim(rtrim(number_format($mwstSatz, 1, ',', '.'), '0'), ',') }} %)</td><td class="right">{{ $nf($mwst_betrag) }} €</td></tr>
                <tr><td class="total">Gesamt brutto</td><td class="right total">{{ $nf($brutto_gesamt) }} €</td></tr>
            </table>
            @if(count($kalk['alternativen'] ?? []))
                <div class="alt">Wahl-Optionen (nicht im Gesamtpreis):
                    @foreach($kalk['alternativen'] as $alt){{ $alt['name'] }} ({{ $alt['min'] !== null ? $nf($alt['min']) : '—' }}–{{ $alt['max'] !== null ? $nf($alt['max']) : '—' }} €/Gast)@if(! $loop->last) · @endif @endforeach
                </div>
            @endif
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
