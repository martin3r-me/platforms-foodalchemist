<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Foodbook — {{ $fb->label }}</title>
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
        .kapitel { margin-bottom: 12px; }
        .kapitel h2 { font-size: 14px; color: #6d28d9; margin: 10px 0 4px; border-bottom: 1px solid #ececec; padding-bottom: 3px; }
        .kapitel .kpreis { float: right; color: #6b7280; font-size: 11px; font-weight: normal; }
        .pos { padding: 1px 0; }
        .pos.header { font-weight: bold; color: #374151; margin-top: 6px; }
        .preis { margin-top: 20px; border-top: 2px solid #6d28d9; padding-top: 10px; }
        .preis table { width: 100%; border-collapse: collapse; }
        .preis td { padding: 4px 0; }
        .preis .total { font-size: 18px; font-weight: bold; color: #111827; }
        .right { text-align: right; }
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
        <h1>{{ $fb->label }}</h1>
        <div class="sub">Foodbook / Portfolio</div>
    </div>

    <table class="meta">
        @if($kunde)<tr><td class="k">Kunde</td><td>{{ $kunde }}@if(($kontakt ?? null) && $kontakt !== $kunde) · {{ $kontakt }}@endif</td></tr>@endif
        @if($fb->jahr)<tr><td class="k">Jahr</td><td>{{ $fb->jahr }}</td></tr>@endif
        @if($gesamt['personen'])<tr><td class="k">Personen</td><td>{{ $gesamt['personen'] }}</td></tr>@endif
        @if($stand ?? null)<tr><td class="k">Stand</td><td>{{ $stand->format('d.m.Y') }}</td></tr>@endif
    </table>

    @forelse($kapitel as $k)
        <div class="kapitel" style="margin-left: {{ $k['depth'] * 16 }}px">
            <h2>
                @if($k['vk_pro_person'] > 0)<span class="kpreis">{{ number_format($k['vk_pro_person'], 2, ',', '.') }} €/P</span>@endif
                {{ $k['titel'] }}
            </h2>
            @forelse($k['bloecke'] as $b)
                <div class="pos {{ $b['ist_header'] ? 'header' : '' }}">{{ $b['label'] }}</div>
                @if($b['untertitel'] ?? null)
                    <div class="pos" style="color:#6b7280; font-size:11px">{{ $b['untertitel'] }}</div>
                @endif
                {{-- Wording-Kette: Gerichte eines Concepts als Kundenzeilen (Foodbook-Override → Konzept → Standard → Name) --}}
                @foreach($b['gerichte'] ?? [] as $g)
                    @if($g['type'] === 'paket' || $g['type'] === 'header')
                        <div class="pos" style="margin-left:12px; font-weight:bold; color:#374151">{{ $g['text'] }}</div>
                    @else
                        <div class="pos" style="margin-left:{{ 12 + $g['einrueckung'] * 12 }}px">{{ $g['text'] }}</div>
                    @endif
                @endforeach
            @empty
                <div class="pos" style="color:#9ca3af">—</div>
            @endforelse
        </div>
    @empty
        <p style="color:#9ca3af">Noch keine Kapitel angelegt.</p>
    @endforelse

    <div class="preis">
        <table>
            <tr><td>Preis pro Person</td><td class="right">{{ number_format($gesamt['vk_pro_person'], 2, ',', '.') }} €</td></tr>
            @if($gesamt['personen'])<tr><td>Personen</td><td class="right">{{ $gesamt['personen'] }}</td></tr>@endif
            @if($gesamt['gesamt_vk'] !== null)<tr><td class="total">Gesamt</td><td class="right total">{{ number_format($gesamt['gesamt_vk'], 2, ',', '.') }} €</td></tr>@endif
        </table>
        @php($mwstSatz = ($mwst ?? null) ? (($mwst['default_satz'] ?? 'ermaessigt') === 'regulaer' ? ($mwst['regulaer'] ?? 19) : ($mwst['ermaessigt'] ?? 7)) : null)
        @php($mwstText = 'Alle Preise netto' . ($mwstSatz !== null ? ' zzgl. gesetzl. MwSt (' . rtrim(rtrim(number_format((float) $mwstSatz, 1, ',', '.'), '0'), ',') . ' %)' : '') . '.')
        <div style="color:#9ca3af; font-size:10px; margin-top:6px">{{ $mwstText }}</div>
    </div>

    @if($fb->description)
        <div style="margin-top:16px">{!! nl2br(e($fb->description)) !!}</div>
    @endif

    <div class="foot">Erstellt mit Food Alchemist</div>
</div>
</body>
</html>
