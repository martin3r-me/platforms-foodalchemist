<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Bestellung {{ $dok['lieferant']['name'] ?? '' }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: "DejaVu Sans", Arial, sans-serif; color: #1f2937; font-size: 12px; line-height: 1.5; margin: 0; padding: 32px; }
        .doc { max-width: 760px; margin: 0 auto; }
        .head { border-bottom: 2px solid #6d28d9; padding-bottom: 12px; margin-bottom: 16px; }
        h1 { font-size: 20px; margin: 0 0 4px; color: #111827; }
        .sub { color: #6b7280; }
        .lief-box { margin: 12px 0 16px; }
        .lief-box strong { color: #111827; }
        table { width: 100%; border-collapse: collapse; margin-top: 6px; }
        th { text-align: left; font-size: 10px; text-transform: uppercase; letter-spacing: .04em; color: #6b7280; padding: 4px 6px; border-bottom: 1px solid #ececec; }
        td { padding: 3px 6px; border-bottom: 1px solid #f3f4f6; vertical-align: top; }
        .right { text-align: right; white-space: nowrap; }
        .art { color: #9ca3af; font-size: 10px; }
        .grand { margin-top: 14px; border-top: 2px solid #6d28d9; padding-top: 8px; font-size: 15px; font-weight: bold; text-align: right; }
        .moq { margin-top: 8px; font-size: 11px; }
        .moq .warn { color: #92400e; }
        .moq .ok { color: #047857; }
        .muted { color: #9ca3af; }
        .foot { margin-top: 28px; color: #9ca3af; font-size: 10px; border-top: 1px solid #ececec; padding-top: 10px; }
        .actions { margin-bottom: 18px; }
        .btn { display: inline-block; padding: 6px 12px; background: #6d28d9; color: #fff; text-decoration: none; border-radius: 6px; margin-right: 6px; }
        .btn.ghost { background: #eee; color: #374151; }
        @media print { .actions { display: none; } body { padding: 0; } }
    </style>
</head>
<body>
<div class="doc">
    @unless($istPdf ?? false)
        <div class="actions">
            <a class="btn" href="{{ request()->fullUrlWithQuery(['pdf' => 1]) }}">PDF herunterladen</a>
            <a class="btn ghost" href="{{ request()->fullUrlWithQuery(['csv' => 1]) }}">CSV</a>
            <a class="btn ghost" href="javascript:window.print()">Drucken</a>
        </div>
    @endunless

    <div class="head">
        <h1>Bestellung</h1>
        <div class="sub">{{ $dok['status_label'] }} · Nr. {{ $dok['id'] }}@if($dok['reference']) · {{ $dok['reference'] }}@endif @if($dok['created_at']) · angelegt {{ $dok['created_at'] }}@endif</div>
    </div>

    <div class="lief-box">
        <strong>{{ $dok['lieferant']['name'] ?? '—' }}</strong><br>
        @if($dok['lieferant']['address']){{ $dok['lieferant']['address'] }}<br>@endif
        @if($dok['lieferant']['postal_code'] || $dok['lieferant']['city']){{ trim(($dok['lieferant']['postal_code'] ?? '') . ' ' . ($dok['lieferant']['city'] ?? '')) }}<br>@endif
        @if($dok['lieferant']['email_order'])<span class="muted">{{ $dok['lieferant']['email_order'] }}</span><br>@endif
        @if($dok['desired_delivery_date'])<span>Wunsch-Liefertermin: <strong>{{ $dok['desired_delivery_date'] }}</strong></span>@endif
    </div>

    <table>
        <thead><tr>
            <th>Artikel</th>
            <th class="right">Gebinde</th>
            <th class="right">Anzahl</th>
            <th class="right">Preis/Geb.</th>
            <th class="right">Summe</th>
        </tr></thead>
        <tbody>
            @forelse($dok['zeilen'] as $z)
                <tr>
                    <td>
                        {{ $z['designation'] ?: '—' }}
                        @if($z['article_number'])<br><span class="art">Art. {{ $z['article_number'] }}</span>@endif
                        @unless($z['bestellbar'])<br><span class="art" style="color:#b45309">Preis/Gebinde fehlt — bitte prüfen</span>@endunless
                    </td>
                    <td class="right">{{ $z['packaging_unit'] ?: '—' }}@if($z['pack_qty'])<br><span class="art">{{ rtrim(rtrim(number_format($z['pack_qty'], 3, ',', '.'), '0'), ',') }} {{ $z['unit_code'] }}</span>@endif</td>
                    <td class="right"><strong>{{ rtrim(rtrim(number_format($z['qty_packs'], 2, ',', '.'), '0'), ',') }}</strong></td>
                    <td class="right">{{ $z['pack_price'] !== null ? number_format($z['pack_price'], 2, ',', '.') . ' €' : '—' }}</td>
                    <td class="right">{{ number_format($z['line_total'], 2, ',', '.') }} €</td>
                </tr>
            @empty
                <tr><td colspan="5" class="muted">Keine Positionen.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="grand">Wareneinsatz netto: {{ number_format($dok['total_net'], 2, ',', '.') }} €</div>

    @php($moq = $dok['moq'])
    <div class="moq">
        @if($moq['unter_mindestbestellwert'])
            <span class="warn">⚠ Unter Mindestbestellwert ({{ number_format($moq['min_order_value'], 2, ',', '.') }} €) — es fehlen {{ number_format($moq['fehlt_bis_min'], 2, ',', '.') }} €.</span>
        @elseif($moq['min_order_value'] !== null)
            <span class="ok">✓ Mindestbestellwert erreicht.</span>
        @endif
        @if($moq['frei_haus'])<span class="ok"> · frei Haus.</span>@elseif($moq['free_shipping_threshold'] !== null)<span class="muted"> · noch {{ number_format($moq['fehlt_bis_frei_haus'], 2, ',', '.') }} € bis frei Haus.</span>@endif
    </div>

    <div class="foot">Food Alchemist · Bestellung Nr. {{ $dok['id'] }}@if($dok['sent_at']) · versendet {{ $dok['sent_at'] }}@endif · Preise netto</div>
</div>
</body>
</html>
