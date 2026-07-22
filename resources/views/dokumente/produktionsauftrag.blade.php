<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Produktionsschein {{ $dok['production_date'] }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: "DejaVu Sans", Arial, sans-serif; color: #1f2937; font-size: 12px; line-height: 1.5; margin: 0; padding: 32px; }
        .doc { max-width: 760px; margin: 0 auto; }
        .head { border-bottom: 2px solid #6d28d9; padding-bottom: 12px; margin-bottom: 16px; }
        h1 { font-size: 20px; margin: 0 0 4px; color: #111827; }
        .sub { color: #6b7280; }
        .muted { color: #9ca3af; }
        .rezept { margin: 16px 0; padding-bottom: 12px; border-bottom: 1px solid #ececec; }
        .rezept h2 { font-size: 14px; margin: 0 0 4px; color: #111827; }
        .rezept .meta { color: #6b7280; font-size: 11px; margin-bottom: 6px; }
        .rezept .zubereitung { white-space: pre-line; }
        .rezept .darreichung { margin-top: 6px; font-size: 11px; color: #6b7280; }
        table.zutaten { width: 100%; border-collapse: collapse; margin-top: 6px; }
        table.zutaten th { text-align: left; font-size: 10px; text-transform: uppercase; letter-spacing: .04em; color: #6b7280; padding: 3px 6px; border-bottom: 1px solid #ececec; }
        table.zutaten td { padding: 2px 6px; border-bottom: 1px solid #f3f4f6; }
        .right { text-align: right; white-space: nowrap; }
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
        <h1>Produktionsschein</h1>
        <div class="sub">
            {{ \Illuminate\Support\Carbon::parse($dok['production_date'])->format('d.m.Y') }} · {{ $dok['status_label'] }}
            @if($dok['reference']) · {{ $dok['reference'] }}@endif
        </div>
        @if(count($dok['ziele']) > 0)
            <div class="muted">{{ implode(' · ', $dok['ziele']) }}</div>
        @endif
        @if($dok['note'])<div class="muted">Notiz: {{ $dok['note'] }}</div>@endif
    </div>

    @forelse($dok['zeilen'] as $z)
        <div class="rezept">
            <h2>{{ $z['name'] }}@if($z['ist_basisrezept']) <span class="muted">(Basisrezept)</span>@endif</h2>
            <div class="meta">
                {{ rtrim(rtrim(number_format($z['ansaetze'], 2, ',', '.'), '0'), ',') }} Ansätze
                @if($z['portionen'] !== null) · {{ $z['portionen'] }} Portionen @endif
                @if($z['produzierte_menge_kg'] !== null) · {{ number_format($z['produzierte_menge_kg'], 2, ',', '.') }} kg @endif
                @if($z['arbeitszeit_min'] !== null) · {{ $z['arbeitszeit_min'] }} min Arbeitszeit @endif
            </div>

            @if($z['zutaten'])
                <table class="zutaten">
                    <thead><tr><th>Zutat</th><th class="right">Menge</th></tr></thead>
                    <tbody>
                        @foreach($z['zutaten'] as $zu)
                            <tr><td>{{ $zu['name'] }}@if($zu['note']) <span class="muted">({{ $zu['note'] }})</span>@endif</td><td class="right">{{ rtrim(rtrim(number_format($zu['menge'], 2, ',', '.'), '0'), ',') }} {{ $zu['einheit'] }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            @endif

            @if($z['zubereitung'])<div class="zubereitung">{{ $z['zubereitung'] }}</div>@endif

            @if($z['darreichung'])
                <div class="darreichung">
                    @foreach($z['darreichung'] as $k => $v)<span>{{ $k }}: {{ $v }} · </span>@endforeach
                </div>
            @endif
        </div>
    @empty
        <p class="muted">Keine Rezepte.</p>
    @endforelse

    <div class="foot">Food Alchemist · Produktionsschein · {{ $dok['id'] }}</div>
</div>
</body>
</html>
