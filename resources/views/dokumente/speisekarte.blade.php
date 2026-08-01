<!DOCTYPE html>
<html lang="de">
@php($pdf = $istPdf ?? false)
{{-- Marken-Tokens (pro Karte) mit Defaults; DomPDF: kein var(), Farben per Blade-Echo, Bilder base64 --}}
@php($b = ($branding ?? []) + ['color' => '#6d28d9', 'band' => '#6d28d9', 'logo' => null, 'cover' => null, 'footer' => null])
@php($brand = $b['color'] ?: '#6d28d9')
@php($band = $b['band'] ?: $brand)
@php($footerText = $b['footer'] ?: 'Erstellt mit Food Alchemist')
@php($typLabel = ['alacarte' => 'À la carte', 'tageskarte' => 'Tageskarte', 'saisonkarte' => 'Saisonkarte', 'getraenkekarte' => 'Getränkekarte', 'weinkarte' => 'Weinkarte'])
<head>
    <meta charset="utf-8">
    <title>Speisekarte — {{ $karte->name }}</title>
    <style>
        /* DomPDF-Leitplanken: keine CSS-Variablen, kein Flex/Grid, Bilder base64, Bänder fixed, Seitenzahl via counter() */
        @page { margin: {{ $pdf ? '2.4cm 1.5cm 1.7cm 1.5cm' : '0' }}; }
        * { box-sizing: border-box; }
        body { font-family: "DejaVu Sans", Arial, sans-serif; color: #1f2937; font-size: 12px; line-height: 1.55; margin: 0; padding: 0; }
        .doc { max-width: {{ $pdf ? 'none' : '720px' }}; margin: 0 auto; padding: {{ $pdf ? '0' : '2.4cm 1.5cm 1.7cm 1.5cm' }}; }

        .band-top {
            {{ $pdf ? 'position: fixed; top: -2.4cm; left: -1.5cm; width: 21cm;' : '' }}
            height: 1.4cm; background: {{ $band }}; color: #fff; padding: 0 1.5cm;
        }
        .band-top .bt-label { {{ $pdf ? 'display: block; padding-top: 0.52cm;' : 'float: left; line-height: 1.4cm;' }} font-size: 10px; letter-spacing: .08em; text-transform: uppercase; opacity: .92; }
        .band-top .bt-logo { {{ $pdf ? 'position: absolute; right: 1.5cm; top: 0.28cm;' : 'float: right;' }} }
        .band-top .bt-logo img { max-height: 0.85cm; max-width: 5cm; {{ $pdf ? '' : 'margin-top: 0.28cm;' }} }
        .cover-photo { margin: 4px 0 14px; }
        .cover-photo img { max-width: 100%; max-height: 8cm; border-radius: 6px; }
        .band-bottom {
            {{ $pdf ? 'position: fixed; bottom: -1.7cm; left: -1.5cm; width: 21cm;' : '' }}
            height: 1.0cm; border-top: 2px solid {{ $brand }};
            color: #9ca3af; font-size: 9px; padding: 0 1.5cm;
        }
        .band-bottom .bb-foot { {{ $pdf ? 'display: block;' : '' }} line-height: 1.0cm; }

        /* ── Kopf ── */
        .kicker { font-size: 11px; letter-spacing: .16em; text-transform: uppercase; color: #6b7280; }
        .title { font-size: 30px; font-weight: bold; color: #111827; margin: 4px 0 2px; }
        .rule { height: 4px; width: 4.5cm; background: {{ $brand }}; margin: 12px 0 14px; border-radius: 2px; }
        .gueltig { color: #6b7280; font-size: 11px; margin-bottom: 6px; }

        /* ── Rubriken / Positionen ── */
        .rubrik { margin-bottom: 14px; }
        .rubrik h3, .rubrik h4, .rubrik h5 { color: #111827; margin: 20px 0 6px; border-bottom: 2px solid {{ $brand }}; padding-bottom: 5px; font-weight: bold; letter-spacing: .04em; text-transform: uppercase; }
        .rubrik h3 { font-size: 14px; }
        .rubrik h4 { font-size: 12px; }
        .rubrik h5 { font-size: 11px; }
        .rubrik .claim { color: #6b7280; font-size: 11px; font-style: italic; margin: 0 0 8px; }

        table.pos { width: 100%; border-collapse: collapse; }
        table.pos td { vertical-align: top; padding: 3px 0; }
        td.pname { color: #111827; }
        td.pname .codes { color: #9ca3af; font-size: 9px; vertical-align: super; }
        td.pname .sub { display: block; color: #6b7280; font-size: 10px; font-style: italic; }
        td.pprice { width: 2.8cm; text-align: right; color: #111827; white-space: nowrap; }
        .pos-header { font-weight: bold; color: #111827; margin: 8px 0 2px; text-transform: uppercase; font-size: 10px; letter-spacing: .06em; }
        .pos-text { color: #6b7280; font-style: italic; margin: 3px 0; }

        /* ── Legende ── */
        .preis-note { margin-top: 20px; color: #6b7280; font-size: 10px; }
        .legende { margin-top: 14px; border-top: 1px solid #e5e7eb; padding-top: 10px; }
        .legende h4 { font-size: 11px; font-weight: bold; letter-spacing: .06em; text-transform: uppercase; color: #374151; margin: 0 0 6px; }
        .legende .grp { color: #4b5563; font-size: 10px; line-height: 1.7; }
        .legende .code { color: {{ $brand }}; font-weight: bold; }
        .legende .disclaimer { color: #9ca3af; font-size: 9px; margin-top: 8px; }

        .actions { margin-bottom: 18px; }
        .btn { display: inline-block; padding: 6px 12px; background: {{ $brand }}; color: #fff; text-decoration: none; border-radius: 6px; margin-right: 6px; }
        .btn.ghost { background: #eee; color: #374151; }
        @media print { .actions { display: none; } }
    </style>
</head>
<body>

<div class="band-top">
    <span class="bt-label">{{ $karte->name }}</span>
    @if($b['logo'])<span class="bt-logo"><img src="{{ $b['logo'] }}" alt=""></span>@endif
</div>
<div class="band-bottom">
    <span class="bb-foot">{{ $footerText }}</span>
</div>

<div class="doc">

    @unless($pdf)
        <div class="actions">
            <a class="btn" href="?pdf=1">Als PDF laden</a>
            <a class="btn ghost" href="javascript:window.print()">Drucken</a>
        </div>
    @endunless

    <div class="kicker">{{ $typLabel[$karte->karten_typ] ?? 'Speisekarte' }}</div>
    <div class="title">{{ $karte->name }}</div>
    <div class="rule"></div>
    @if($b['cover'])<div class="cover-photo"><img src="{{ $b['cover'] }}" alt=""></div>@endif
    @if($karte->gueltig_von || $karte->gueltig_bis)
        <div class="gueltig">
            Gültig
            @if($karte->gueltig_von) ab {{ $karte->gueltig_von->format('d.m.Y') }} @endif
            @if($karte->gueltig_bis) bis {{ $karte->gueltig_bis->format('d.m.Y') }} @endif
        </div>
    @endif

    @forelse($rubriken as $rubrik)
        @php($tag = $rubrik['depth'] === 0 ? 'h3' : ($rubrik['depth'] === 1 ? 'h4' : 'h5'))
        <div class="rubrik">
            <{{ $tag }}>{{ $rubrik['title'] }}</{{ $tag }}>
            @if($rubrik['claim'])<div class="claim">{{ $rubrik['claim'] }}</div>@endif

            <table class="pos">
                @foreach($rubrik['positionen'] as $pos)
                    @if($pos['typ'] === 'header')
                        <tr><td colspan="2" class="pos-header">{{ $pos['name'] }}</td></tr>
                    @elseif($pos['typ'] === 'text')
                        <tr><td colspan="2" class="pos-text">{{ $pos['consumer_text'] ?: $pos['name'] }}</td></tr>
                    @elseif($pos['typ'] === 'spacer')
                        <tr><td colspan="2" style="height:6px"></td></tr>
                    @else
                        <tr>
                            <td class="pname">
                                {{ $pos['name'] }}@if(!empty($pos['codes']))<span class="codes">{{ implode(',', $pos['codes']) }}</span>@endif
                                @if($pos['consumer_text'])<span class="sub">{{ $pos['consumer_text'] }}</span>@endif
                            </td>
                            <td class="pprice">
                                @php($wert = $brutto ? $pos['vk_brutto'] : $pos['vk_netto'])
                                @if($wert !== null){{ number_format((float) $wert, 2, ',', '.') }} €@endif
                            </td>
                        </tr>
                    @endif
                @endforeach
            </table>
        </div>
    @empty
        <p class="pos-text">Diese Speisekarte hat noch keine Rubriken.</p>
    @endforelse

    <div class="preis-note">
        Alle Preise in Euro{{ $brutto ? ', inkl. ' . rtrim(rtrim(number_format($mwstSatz, 1, ',', '.'), '0'), ',') . ' % MwSt.' : ' (netto)' }}
    </div>

    @if(count($legende['allergene']) || count($legende['zusatzstoffe']))
        <div class="legende">
            @if(count($legende['allergene']))
                <h4>Allergene</h4>
                <div class="grp">
                    @foreach($legende['allergene'] as $a)<span class="code">{{ $a['code'] }}</span> {{ $a['label'] }}@if(!$loop->last) &nbsp;·&nbsp; @endif @endforeach
                </div>
            @endif
            @if(count($legende['zusatzstoffe']))
                <h4 style="margin-top:8px">Zusatzstoffe</h4>
                <div class="grp">
                    @foreach($legende['zusatzstoffe'] as $z)<span class="code">{{ $z['code'] }}</span> {{ $z['label'] }}@if(!$loop->last) &nbsp;·&nbsp; @endif @endforeach
                </div>
            @endif
            <div class="disclaimer">
                Kennzeichnung nach LMIV (Allergene A–{{ chr(64 + count(\Platform\FoodAlchemist\Models\FoodAlchemistItemAllergen::ALLERGENE)) }}) und ZZulV (Zusatzstoffe).
                Angaben nach dem Vorsorgeprinzip (ALL-MAXIMAL); <span class="code">*</span> = Spuren möglich. Stand: {{ $erzeugt }}.
            </div>
        </div>
    @endif

</div>
</body>
</html>
