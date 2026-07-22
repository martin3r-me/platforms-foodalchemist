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
        .einkauf-head { margin-top: 28px; border-top: 2px solid #6d28d9; padding-top: 12px; }
        .lieferant { margin: 14px 0; }
        .lieferant h2 { font-size: 13px; margin: 0 0 4px; color: #111827; display: flex; justify-content: space-between; align-items: baseline; }
        .lieferant h2 .right-sum { font-weight: bold; }
        table.einkauf-tbl { width: 100%; border-collapse: collapse; margin-top: 4px; }
        table.einkauf-tbl th { text-align: left; font-size: 10px; text-transform: uppercase; letter-spacing: .04em; color: #6b7280; padding: 3px 6px; border-bottom: 1px solid #ececec; }
        table.einkauf-tbl td { padding: 3px 6px; border-bottom: 1px solid #f3f4f6; vertical-align: top; }
        .grand { margin-top: 14px; border-top: 2px solid #6d28d9; padding-top: 8px; font-size: 15px; font-weight: bold; text-align: right; }
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

    {{-- Einkauf/Bestellvorschlag — interne Ops-Sektion (Lieferant, Gebinde, EK). Wie der
         alte Planungsblatt-Bundle, jetzt im gebündelten Produktionsschein. --}}
    @if(($dok['einkauf'] ?? null) !== null)
        <div class="einkauf-head">
            <h1 style="font-size:16px;margin:0 0 4px">Einkauf / Bestellvorschlag</h1>
            <div class="muted">GP-Bedarf nach Lieferant, in ganzen Gebinden · interne Ops-Angabe (EK netto)</div>
        </div>

        @foreach($dok['einkauf']['lieferanten'] as $g)
            <div class="lieferant">
                <h2>{{ $g['lieferant'] }}
                    <span class="right-sum">{{ number_format($g['ek_summe'], 2, ',', '.') }} €@unless($g['ek_vollstaendig']) <span class="muted">(EK unvollst.)</span>@endunless</span>
                </h2>
                <table class="einkauf-tbl">
                    <thead><tr><th>Artikel</th><th class="right">Bestellen</th><th class="right">Bedarf</th><th class="right">EK</th></tr></thead>
                    <tbody>
                        @foreach($g['positionen'] as $p)
                            @php($geb = $p['gebinde'])
                            <tr>
                                <td>{{ $p['gp'] }}@if($p['lead_artikel'])<br><span class="muted">@if($geb['article_number']){{ $geb['article_number'] }} · @endif{{ $p['lead_artikel'] }}</span>@endif</td>
                                <td class="right">@if($geb['berechenbar']){{ $geb['qty_packs'] }}× {{ rtrim(rtrim(number_format($geb['pack_qty'], 3, ',', '.'), '0'), ',') }} {{ $geb['pack_unit_code'] }}@if($geb['packaging_unit']) {{ $geb['packaging_unit'] }}@endif @else<span class="muted">{{ $geb['grund'] }}</span>@endif</td>
                                <td class="right muted">{{ rtrim(rtrim(number_format($p['menge_kg'], 3, ',', '.'), '0'), ',') }} kg</td>
                                <td class="right">{{ $p['ek_bekannt'] ? number_format($p['bestell_ek_eur'], 2, ',', '.') . ' €' : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endforeach

        <div class="grand">Wareneinsatz gesamt: {{ number_format($dok['einkauf']['ek_gesamt'], 2, ',', '.') }} € <span class="muted" style="font-weight:normal;font-size:11px">(netto)</span></div>
    @endif

    <div class="foot">Food Alchemist · {{ ($dok['einkauf'] ?? null) !== null ? 'Produktionsschein + Einkauf (intern)' : 'Produktionsschein' }} · {{ $dok['id'] }}</div>
</div>
</body>
</html>
