<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>{{ $titel }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: "DejaVu Sans", Arial, sans-serif; color: #1f2937; font-size: 12px; line-height: 1.5; margin: 0; padding: 32px; }
        .doc { max-width: 760px; margin: 0 auto; }
        .head { border-bottom: 2px solid #6d28d9; padding-bottom: 12px; margin-bottom: 16px; }
        h1 { font-size: 20px; margin: 0 0 4px; color: #111827; }
        .sub { color: #6b7280; }
        .rez { margin-bottom: 16px; page-break-inside: avoid; }
        .rez h2 { font-size: 14px; color: #6d28d9; margin: 12px 0 2px; }
        .rez .meta { color: #6b7280; font-size: 11px; margin-bottom: 4px; }
        .tag { display: inline-block; font-size: 9px; padding: 1px 5px; border-radius: 8px; background: #ede9fe; color: #6d28d9; vertical-align: middle; }
        .tag.warn { background: #fef3c7; color: #92400e; }
        .tag.info { background: #e0f2fe; color: #0369a1; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; font-size: 10px; text-transform: uppercase; letter-spacing: .04em; color: #6b7280; padding: 3px 6px; border-bottom: 1px solid #ececec; }
        td { padding: 2px 6px; border-bottom: 1px solid #f3f4f6; vertical-align: top; }
        .right { text-align: right; white-space: nowrap; }
        .lief { margin-bottom: 14px; page-break-inside: avoid; }
        .lief h2 { font-size: 13px; color: #111827; margin: 10px 0 2px; }
        .lief .sum { float: right; color: #111827; font-weight: bold; }
        .warn-box { background: #fffbeb; border: 1px solid #fde68a; border-radius: 6px; padding: 8px 10px; margin: 12px 0; color: #92400e; font-size: 11px; }
        .warn-box ul { margin: 4px 0 0; padding-left: 16px; }
        .grand { margin-top: 14px; border-top: 2px solid #6d28d9; padding-top: 8px; font-size: 15px; font-weight: bold; }
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
            <a class="btn ghost" href="javascript:window.print()">Drucken</a>
        </div>
    @endunless

    <div class="head">
        <h1>{{ $titel }}</h1>
        <div class="sub">{{ $untertitel }}</div>
    </div>

    @if($blatt['warnungen'] ?? false)
        <div class="warn-box">
            <strong>Hinweise:</strong>
            <ul>@foreach(array_unique($blatt['warnungen']) as $w)<li>{{ $w }}</li>@endforeach</ul>
        </div>
    @endif

    @if($typ === 'produktion')
        @forelse($blatt['rezepte'] as $r)
            <div class="rez">
                <h2>{{ $r['name'] }} @if($r['ist_basisrezept'])<span class="tag">Basisrezept</span>@endif</h2>
                <div class="meta">
                    @if($r['ist_basisrezept'])
                        <strong>{{ $r['ansaetze'] }} Ansatz/Ansätze</strong>@if($r['basis_yield_kg']) à {{ number_format($r['basis_yield_kg'], 3, ',', '.') }} kg (= {{ number_format($r['produzierte_menge_kg'], 2, ',', '.') }} kg)@endif
                        · <span class="muted">Bedarf im Menü: {{ number_format($r['benoetigt_ansaetze'], 2, ',', '.') }} Ansätze</span>
                    @else
                        <strong>{{ $r['portionen'] }} Portionen</strong>@if($r['produzierte_menge_kg']) (gesamt {{ number_format($r['produzierte_menge_kg'], 1, ',', '.') }} kg)@endif
                    @endif
                    @if($r['arbeitszeit_min']) · Arbeitszeit ~{{ $r['arbeitszeit_min'] }} min @endif
                </div>
                <table>
                    <tbody>
                        @foreach($r['zutaten'] as $z)
                            <tr>
                                <td>{{ $z['typ'] === 'sub' ? '↳ ' : '' }}{{ $z['name'] }}@if($z['typ'] === 'sub') <span class="tag info">Sub-Rezept</span>@endif@if($z['typ'] === 'ungemappt') <span class="tag warn">ungemappt</span>@endif@if($z['optional']) <span class="muted">(n. B.)</span>@endif@if($z['note']) <span class="muted">— {{ $z['note'] }}</span>@endif</td>
                                <td class="right">{{ number_format($z['menge'], 1, ',', '.') }} {{ $z['einheit'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                @if($r['darreichung'] ?? null)
                    @php($d = $r['darreichung'])
                    <p class="meta" style="margin-top:4px">
                        <strong>Ausgabe:</strong>
                        @if(($d['regeneration_temp_c'] ?? null) !== null) Regeneration {{ $d['regeneration_temp_c'] }} °C @endif
                        @if(($d['regeneration_duration_min'] ?? null) !== null) / {{ $d['regeneration_duration_min'] }} min @endif
                        @if(($d['regeneration_core_temp_c'] ?? null) !== null) · Kerntemp. {{ $d['regeneration_core_temp_c'] }} °C @endif
                        @if(($d['geraet'] ?? null)) · Gerät {{ $d['geraet'] }} @endif
                        @if(($d['behaelter_warm'] ?? null)) · Behälter warm {{ $d['behaelter_warm'] }} @endif
                        @if(($d['behaelter_kalt'] ?? null)) · Behälter kalt {{ $d['behaelter_kalt'] }} @endif
                        @if(($d['vehikel'] ?? null)) · Vehikel {{ $d['vehikel'] }} @endif
                        @if(($d['arbeitszeit_zuschlag_min'] ?? null) !== null) · +{{ $d['arbeitszeit_zuschlag_min'] }} min Ausgabe @endif
                    </p>
                @endif
                @if($r['zubereitung'] ?? null)
                    <p class="meta" style="margin-top:4px; white-space:pre-line">{{ $r['zubereitung'] }}</p>
                @endif
            </div>
        @empty
            <p class="muted">Keine skalierbaren Positionen.</p>
        @endforelse
    @else
        @php($gesamt = 0.0)
        @forelse($blatt['lieferanten'] as $g)
            @php($gesamt += $g['ek_summe'])
            <div class="lief">
                <h2><span class="sum">{{ number_format($g['ek_summe'], 2, ',', '.') }} €@unless($g['ek_vollstaendig']) <span class="tag warn">EK unvollst.</span>@endunless</span>{{ $g['lieferant'] }}</h2>
                <table>
                    <thead><tr><th>Artikel</th><th class="right">Bestellen</th><th class="right">Bedarf</th><th class="right">EK netto</th></tr></thead>
                    <tbody>
                        @foreach($g['positionen'] as $p)
                            @php($geb = $p['gebinde'])
                            <tr>
                                <td>{{ $p['gp'] }}@if($p['lead_artikel'])<br><span class="muted">@if($geb['article_number']){{ $geb['article_number'] }} · @endif{{ $p['lead_artikel'] }}</span>@endif@if($p['ausweich'])<br><span class="tag info">Ausweich: {{ $p['ausweich']['artikel'] }} ({{ $p['ausweich']['lieferant'] }})</span>@endif</td>
                                <td class="right">@if($geb['berechenbar']){{ $geb['qty_packs'] }}× {{ rtrim(rtrim(number_format($geb['pack_qty'], 3, ',', '.'), '0'), ',') }} {{ $geb['pack_unit_code'] }}@if($geb['packaging_unit']) {{ $geb['packaging_unit'] }}@endif@if($geb['pack_price'] !== null)<br><span class="muted">à {{ number_format($geb['pack_price'], 2, ',', '.') }} €</span>@endif @else<span class="muted">{{ $geb['grund'] }}</span>@endif</td>
                                <td class="right">@if($geb['berechenbar']){{ number_format($geb['needed_base'], $geb['needed_base_unit'] === 'Stk' ? 0 : 2, ',', '.') }} {{ $geb['needed_base_unit'] }}@else {{ number_format($p['menge_kg'], 3, ',', '.') }} kg @endif</td>
                                <td class="right">{{ $p['ek_bekannt'] ? number_format($p['bestell_ek_eur'], 2, ',', '.') . ' €' : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @empty
            <p class="muted">Kein GP-Bedarf.</p>
        @endforelse
        <div class="grand">Wareneinsatz gesamt: {{ number_format($gesamt, 2, ',', '.') }} € <span class="muted" style="font-weight:normal;font-size:11px">(netto)</span></div>
    @endif

    <div class="foot">Erstellt mit Food Alchemist · Planungs-Blatt · rein rechnend (kein Bestellvorgang)</div>
</div>
</body>
</html>
