<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Versandprotokoll Bestellungen</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 28px; color: #1f2937; font: 12px/1.45 "DejaVu Sans", Arial, sans-serif; }
        .actions { margin: 0 auto 20px; max-width: 820px; }
        .btn { display: inline-block; margin-right: 6px; padding: 7px 12px; border-radius: 6px; background: #6d28d9; color: white; text-decoration: none; }
        .btn.secondary { background: #e5e7eb; color: #374151; }
        .order { max-width: 820px; margin: 0 auto; page-break-after: always; }
        .order:last-child { page-break-after: auto; }
        .head { display: flex; justify-content: space-between; gap: 20px; border-bottom: 2px solid #6d28d9; padding-bottom: 12px; margin-bottom: 14px; }
        h1 { margin: 0 0 3px; color: #111827; font-size: 20px; }
        .muted { color: #6b7280; }
        .status { font-weight: bold; text-align: right; }
        table { width: 100%; border-collapse: collapse; margin-top: 14px; }
        th { padding: 5px 6px; border-bottom: 1px solid #d1d5db; color: #6b7280; font-size: 10px; text-align: left; text-transform: uppercase; }
        td { padding: 5px 6px; border-bottom: 1px solid #eceff3; vertical-align: top; }
        .right { text-align: right; white-space: nowrap; }
        .total { margin-top: 12px; padding-top: 8px; border-top: 2px solid #6d28d9; font-size: 15px; font-weight: bold; text-align: right; }
        .foot { margin-top: 22px; color: #9ca3af; font-size: 10px; }
        @media print { body { padding: 0; } .actions { display: none; } }
    </style>
</head>
<body>
@unless($istPdf ?? false)
    <div class="actions">
        <a class="btn" href="javascript:window.print()">Gebündelt drucken</a>
        <a class="btn secondary" href="{{ request()->fullUrlWithQuery(['pdf' => 1]) }}">PDF herunterladen</a>
    </div>
@endunless

@foreach($dokumente as $dok)
    <section class="order">
        <div class="head">
            <div>
                <h1>Bestellung an {{ $dok['lieferant']['name'] ?? '—' }}</h1>
                <div class="muted">Beleg ord-{{ $dok['id'] }}@if($dok['reference']) · {{ $dok['reference'] }}@endif</div>
                @if($dok['desired_delivery_date'])<div>Wunsch-Liefertermin: <strong>{{ \Carbon\Carbon::parse($dok['desired_delivery_date'])->format('d.m.Y') }}</strong></div>@endif
            </div>
            <div class="status">
                {{ $dok['status_label'] }}<br>
                <span class="muted">{{ $dok['sent_at'] ?: $erstelltAm }}</span>
            </div>
        </div>

        <table>
            <thead><tr><th>Artikel</th><th>Gebinde</th><th class="right">Anzahl</th><th class="right">Preis</th><th class="right">Summe</th></tr></thead>
            <tbody>
            @foreach($dok['zeilen'] as $zeile)
                <tr>
                    <td>{{ $zeile['designation'] ?: '—' }}@if($zeile['article_number'])<br><span class="muted">Art. {{ $zeile['article_number'] }}</span>@endif</td>
                    <td>{{ $zeile['packaging_unit'] ?: '—' }}</td>
                    <td class="right">{{ rtrim(rtrim(number_format($zeile['qty_packs'], 2, ',', '.'), '0'), ',') }}</td>
                    <td class="right">{{ $zeile['pack_price'] !== null ? number_format($zeile['pack_price'], 2, ',', '.') . ' €' : '—' }}</td>
                    <td class="right">{{ number_format($zeile['line_total'], 2, ',', '.') }} €</td>
                </tr>
            @endforeach
            </tbody>
        </table>
        <div class="total">Netto {{ number_format($dok['total_net'], 2, ',', '.') }} €</div>
        <div class="foot">Food Alchemist · Versandprotokoll erstellt {{ $erstelltAm }} · Bestellung {{ $dok['id'] }}</div>
    </section>
@endforeach
</body>
</html>
