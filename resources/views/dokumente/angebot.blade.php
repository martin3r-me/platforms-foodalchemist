<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Angebot — {{ $angebot->name }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: "DejaVu Sans", Arial, sans-serif; color: #1f2937; font-size: 12px; line-height: 1.55; margin: 0; padding: 32px; }
        .doc { max-width: 720px; margin: 0 auto; }
        .head { border-bottom: 2px solid #6d28d9; padding-bottom: 12px; margin-bottom: 16px; }
        h1 { font-size: 22px; margin: 0 0 4px; color: #111827; }
        .sub { color: #6b7280; }
        table.meta { width: 100%; border-collapse: collapse; margin: 12px 0 20px; }
        table.meta td { padding: 2px 8px 2px 0; vertical-align: top; }
        table.meta .k { color: #6b7280; width: 130px; }
        .menue { margin-bottom: 14px; }
        .menue h2 { font-size: 14px; color: #6d28d9; margin: 0 0 4px; border-bottom: 1px solid #ececec; padding-bottom: 3px; }
        .pos { padding: 1px 0; }
        .pos .role { color: #9ca3af; font-size: 10px; text-transform: uppercase; letter-spacing: .04em; }
        .preis { margin-top: 20px; border-top: 2px solid #6d28d9; padding-top: 10px; }
        .preis table { width: 100%; border-collapse: collapse; }
        .preis td { padding: 4px 0; }
        .preis .total { font-size: 18px; font-weight: bold; color: #111827; }
        .right { text-align: right; }
        .note { margin-top: 16px; }
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
            <a class="btn" href="?pdf=1">PDF herunterladen</a>
            <a class="btn ghost" href="javascript:window.print()">Drucken</a>
        </div>
    @endunless

    <div class="head">
        <h1>Angebot</h1>
        <div class="sub">{{ $angebot->name }}</div>
    </div>

    <table class="meta">
        @if($kunde)<tr><td class="k">Kunde</td><td>{{ $kunde }}@if($kontakt && $kontakt !== $kunde) · {{ $kontakt }}@endif</td></tr>@endif
        @if($angebot->anlass)<tr><td class="k">Anlass</td><td>{{ $angebot->anlass }}</td></tr>@endif
        @if($angebot->event_date)<tr><td class="k">Datum</td><td>{{ $angebot->event_date->format('d.m.Y') }}</td></tr>@endif
        @if($angebot->location)<tr><td class="k">Location</td><td>{{ $angebot->location }}</td></tr>@endif
        <tr><td class="k">Personen</td><td>{{ $kalk['pax'] ?: '—' }}</td></tr>
        @if($angebot->diaet_vorgabe)<tr><td class="k">Diät / Allergien</td><td>{{ $angebot->diaet_vorgabe }}</td></tr>@endif
        @if($angebot->valid_until)<tr><td class="k">Gültig bis</td><td>{{ $angebot->valid_until->format('d.m.Y') }}</td></tr>@endif
    </table>

    @forelse($menues as $menue)
        <div class="menue">
            <h2>{{ $menue['name'] }}</h2>
            @forelse($menue['positionen'] as $p)
                <div class="pos">@if($p['role'])<span class="role">{{ $p['role'] }}:</span> @endif{{ $p['label'] }}</div>
            @empty
                <div class="pos" style="color:#9ca3af">— noch keine Positionen —</div>
            @endforelse
        </div>
    @empty
        <p style="color:#9ca3af">Noch kein Menü zusammengestellt.</p>
    @endforelse

    <div class="preis">
        <table>
            <tr><td>Preis pro Person</td><td class="right">{{ number_format($kalk['vk_pro_person'], 2, ',', '.') }} €</td></tr>
            <tr><td>Personen</td><td class="right">{{ $kalk['pax'] ?: '—' }}</td></tr>
            <tr><td class="total">Gesamt</td><td class="right total">{{ number_format($kalk['gesamt_vk'], 2, ',', '.') }} €</td></tr>
        </table>
    </div>

    @if($angebot->description)
        <div class="note">{!! nl2br(e($angebot->description)) !!}</div>
    @endif

    <div class="foot">
        Erstellt mit Food Alchemist
        @if($angebot->valid_until) · gültig bis {{ $angebot->valid_until->format('d.m.Y') }}@endif
    </div>
</div>
</body>
</html>
