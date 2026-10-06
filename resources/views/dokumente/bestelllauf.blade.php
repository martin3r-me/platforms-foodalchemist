@php
    $pdf = $istPdf ?? false;
    /* fa-pass Druck-Muster (2026-10-05, wie dokumente/report): gebündeltes Versandprotokoll,
       ein Lieferantenbeleg je Seite. Feste Werte statt CSS-Variablen (DomPDF kann keine). */
    $brand = '#0a3dd6';
    $c = [
        'ink' => '#131a26', 'ink2' => '#4a5466', 'ink3' => '#657084',
        'line' => '#dde2ea', 'soft' => '#f3f5f8', 'rail' => '#0d1424', 'railText' => '#b6c0d2',
    ];
    $logoPfad = dirname((new \ReflectionClass(\Platform\FoodAlchemist\FoodAlchemistServiceProvider::class))->getFileName(), 2) . '/resources/brand/fa-wordmark-900.png';
    $logo = is_file($logoPfad) ? 'data:image/png;base64,' . base64_encode((string) file_get_contents($logoPfad)) : null;
    $geld = fn ($v) => number_format((float) $v, 2, ',', '.') . ' €';
    $zahl = fn ($v, $dec = 2) => rtrim(rtrim(number_format((float) $v, $dec, ',', '.'), '0'), ',');
@endphp
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Versandprotokoll Bestellungen</title>
    <style>
        @page { size: A4 portrait; margin: {{ $pdf ? '2.15cm 1.4cm 1.5cm 1.4cm' : '1.5cm 1.3cm' }}; }
        * { box-sizing: border-box; }
        body { font-family: "DejaVu Sans", Arial, sans-serif; color: {{ $c['ink'] }}; background: {{ $pdf ? '#fff' : $c['soft'] }}; margin: 0; padding: 0; font-size: 10px; line-height: 1.3; }
        .doc { max-width: {{ $pdf ? 'none' : '960px' }}; margin: 0 auto; background: #fff; padding: {{ $pdf ? '0' : '2.15cm 1.4cm 1.5cm 1.4cm' }}; }
        .band-top {
            {{ $pdf ? 'position: fixed; top: -2.15cm; left: -1.4cm; width: 18.2cm;' : '' }}
            height: 1.25cm; background: #fff; color: {{ $c['ink3'] }}; padding: 0 1.4cm; border-bottom: 2px solid {{ $brand }};{{ $pdf ? '' : ' max-width: 960px; margin: 0 auto;' }}
        }
        .band-top .bt-label { {{ $pdf ? 'display: block; padding-top: 0.44cm;' : 'display: block; line-height: 1.25cm;' }} font-size: 9.5px; letter-spacing: .02em; text-align: right; }
        .band-top img { height: {{ $pdf ? '0.78cm' : '0.8cm' }}; vertical-align: middle; }
        .band-top .bt-logo { {{ $pdf ? 'position: absolute; top: 0.22cm; left: 1.4cm;' : 'float: left; padding-top: 0.22cm;' }} }
        .band-bottom {
            {{ $pdf ? 'position: fixed; bottom: -1.5cm; left: -1.4cm; width: 18.2cm;' : '' }}
            height: 0.95cm; border-top: 1px solid {{ $c['line'] }}; color: {{ $c['ink3'] }};{{ $pdf ? '' : ' max-width: 960px; margin: 0 auto; background: #fff;' }} font-size: 8.5px; padding: 0 1.4cm;
        }
        .band-bottom .bb-foot { display: block; line-height: 0.95cm; }
        .actions { background: {{ $c['rail'] }}; color: #fff; padding: 12px 16px; margin: -2.15cm -1.4cm 20px; }
        .actions a { display: inline-block; color: #fff; text-decoration: none; border: 1px solid rgba(182,192,210,.28); border-radius: 999px; padding: 5px 10px; margin: 2px; font-size: 11px; }
        .actions a.active { background: {{ $brand }}; border-color: {{ $brand }}; }
        .actions strong { color: {{ $c['railText'] }}; font-weight: bold; margin-right: 4px; }

        .order { page-break-after: always; }
        .order.letzte { page-break-after: auto; }
        .order + .order { {{ $pdf ? '' : 'margin-top: 28px; padding-top: 18px; border-top: 1px dashed ' . $c['line'] . ';' }} }
        header { margin-bottom: 6px; }
        .kicker { font-size: 9.5px; letter-spacing: .02em; color: {{ $c['ink3'] }}; }
        h1 { font-size: 20px; margin: 2px 0 2px; letter-spacing: -.02em; color: {{ $c['ink'] }}; }
        .rule { height: 3px; width: 3.6cm; background: {{ $brand }}; margin: 5px 0 6px; }
        .muted { color: {{ $c['ink3'] }}; font-weight: normal; }
        p { margin: 0 0 5px; }

        .grid { width: 100%; margin: 3px 0 6px; font-size: 0; }
        .grid > div { display: inline-block; width: {{ $pdf ? '22.4%' : '24.6%' }}; border: 1px solid {{ $c['line'] }}; padding: 2px 6px; vertical-align: top; font-size: 10px; margin-right: -1px; margin-bottom: -1px; overflow-wrap: anywhere; }
        .grid > div.wide { width: {{ $pdf ? '47.3%' : '49.8%' }}; }
        .grid span { display: block; color: {{ $c['ink3'] }}; font-size: 8.5px; letter-spacing: .02em; margin-bottom: 0; }

        table { width: 100%; border-collapse: collapse; margin: 3px 0 6px; table-layout: fixed; page-break-inside: auto; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; page-break-after: auto; }
        th, td { border: 1px solid {{ $c['line'] }}; padding: 2px 5px; text-align: left; vertical-align: top; overflow-wrap: anywhere; }
        th { background: {{ $c['soft'] }}; color: {{ $c['ink2'] }}; font-size: 8.5px; font-weight: bold; letter-spacing: .02em; }
        td.num, th.num { text-align: right; }
        .c-art { width: 13%; }
        .c-geb { width: 11%; }
        .c-anz { width: 8%; }
        .c-preis { width: 12%; }
        .c-summe { width: 12%; }
        .sum-line td { border-top: 2px solid {{ $c['ink3'] }}; font-weight: bold; background: {{ $c['soft'] }}; font-size: 11px; }
        .schluss { margin-top: 8px; color: {{ $c['ink3'] }}; font-size: 8.5px; }

        @media print {
            .actions { display: none; }
            body { background: #fff; }
            .doc { max-width: none; margin: 0; padding: 0; }
            .order + .order { margin-top: 0; padding-top: 0; border-top: 0; }
            .band-top { margin-bottom: 12px; }
            .band-bottom { margin-top: 14px; }
        }
    </style>
</head>
<body>
<div class="band-top">
    @if($logo)<span class="bt-logo"><img src="{{ $logo }}" alt="Food.Alchemist"></span>@endif
    <span class="bt-label">Versandprotokoll Bestellungen</span>
</div>
<main class="doc">
    @unless($pdf)
        <div class="actions">
            <strong>Versandprotokoll · {{ count($dokumente) }} {{ count($dokumente) === 1 ? 'Beleg' : 'Belege' }}:</strong>
            <a class="active" href="javascript:window.print()">Gebündelt drucken</a>
            <a href="{{ request()->fullUrlWithQuery(['pdf' => 1]) }}">PDF herunterladen</a>
        </div>
    @endunless

    @foreach($dokumente as $dok)
        <section class="order{{ $loop->last ? ' letzte' : '' }}">
            <header>
                <div class="kicker">Versandprotokoll · Bestell-Nr. ord-{{ $dok['id'] }}@if($dok['reference']) · Referenz: {{ $dok['reference'] }}@endif</div>
                <h1>Bestellung an {{ $dok['lieferant']['name'] ?? '—' }}</h1>
                <div class="rule"></div>
            </header>

            <div class="grid">
                @if($dok['created_at'])<div><span>Bestelldatum</span><strong>{{ $dok['created_at'] }}</strong></div>@endif
                @if($dok['desired_delivery_date'])<div><span>Wunsch-Liefertermin</span><strong>{{ \Carbon\Carbon::parse($dok['desired_delivery_date'])->format('d.m.Y') }}</strong></div>@endif
                <div><span>Status</span><strong>{{ $dok['status_label'] }}</strong></div>
                <div><span>{{ $dok['sent_at'] ? 'Versendet' : 'Protokoll erstellt' }}</span>{{ $dok['sent_at'] ?: $erstelltAm }}</div>
            </div>

            <table>
                <thead><tr>
                    <th class="c-art">Art.-Nr.</th>
                    <th>Artikel</th>
                    <th class="c-geb">Gebinde</th>
                    <th class="c-anz num">Anzahl</th>
                    <th class="c-preis num">Preis</th>
                    <th class="c-summe num">Summe</th>
                </tr></thead>
                <tbody>
                @foreach($dok['zeilen'] as $zeile)
                    <tr>
                        <td>{{ $zeile['article_number'] ?: '—' }}</td>
                        <td>{{ $zeile['designation'] ?: '—' }}</td>
                        <td>{{ $zeile['packaging_unit'] ?: '—' }}</td>
                        <td class="num"><strong>{{ $zahl($zeile['qty_packs']) }}</strong></td>
                        <td class="num">{{ $zeile['pack_price'] !== null ? $geld($zeile['pack_price']) : '—' }}</td>
                        <td class="num">{{ $geld($zeile['line_total']) }}</td>
                    </tr>
                @endforeach
                    <tr class="sum-line">
                        <td colspan="4">Netto</td>
                        <td colspan="2" class="num">{{ $geld($dok['total_net']) }}</td>
                    </tr>
                </tbody>
            </table>
            <p class="schluss">Versandprotokoll erstellt {{ $erstelltAm }} · Bestellung ord-{{ $dok['id'] }} · alle Preise netto</p>
        </section>
    @endforeach
</main>
<div class="band-bottom">
    <span class="bb-foot">Erstellt mit Food.Alchemist</span>
</div>
</body>
</html>
