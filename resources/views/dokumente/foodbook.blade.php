<!DOCTYPE html>
<html lang="de">
@php($istIntern = $intern ?? false)
<head>
    <meta charset="utf-8">
    <title>Foodbook — {{ $fb->label }}{{ $istIntern ? ' · INTERN' : '' }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: "DejaVu Sans", Arial, sans-serif; color: #1f2937; font-size: 12px; line-height: 1.55; margin: 0; padding: 32px; }
        .doc { max-width: 720px; margin: 0 auto; }
        .head { border-bottom: 2px solid #6d28d9; padding-bottom: 12px; margin-bottom: 16px; }
        h1 { font-size: 22px; margin: 0 0 4px; color: #111827; }
        .sub { color: #6b7280; }
        .badge-intern { display: inline-block; font-size: 10px; font-weight: bold; letter-spacing: .06em; background: #6d28d9; color: #fff; padding: 2px 8px; border-radius: 10px; vertical-align: middle; }
        table.meta { width: 100%; border-collapse: collapse; margin: 12px 0 16px; }
        table.meta td { padding: 2px 8px 2px 0; vertical-align: top; }
        table.meta .k { color: #6b7280; width: 130px; }
        .nav { background: #f5f3ff; border: 1px solid #ede9fe; border-radius: 8px; padding: 10px 14px; margin-bottom: 20px; }
        .nav .navtitle { font-size: 10px; text-transform: uppercase; letter-spacing: .06em; color: #6d28d9; font-weight: bold; margin-bottom: 6px; }
        .nav a { color: #4c1d95; text-decoration: none; display: block; padding: 1px 0; }
        .nav a .np { float: right; color: #6b7280; }
        .kapitel { margin-bottom: 18px; }
        .kapitel h2 { font-size: 15px; color: #6d28d9; margin: 24px 0 6px; border-bottom: 1px solid #e5e7eb; padding-bottom: 4px; letter-spacing: .01em; }
        .kapitel:first-of-type h2 { margin-top: 8px; }
        .kapitel .kpreis { float: right; color: #6b7280; font-size: 11px; font-weight: normal; }
        .kapitel .kpreis .ek { color: #9333ea; }
        .kapitel .kpreis .wpz { color: #059669; }
        .pos { padding: 1px 0; }
        .pos.header { font-weight: bold; color: #374151; margin-top: 6px; }
        /* concept_ref/recipe_ref: Konzept-Titel als eigene Zwischenüberschrift — fett + Luft zum vorigen Block (sonst geht der „Header" unter, Dominique 2026-07-21) */
        .pos.concept { font-weight: bold; font-size: 13px; color: #111827; margin-top: 14px; margin-bottom: 1px; }
        .kapitel .pos.concept:first-of-type { margin-top: 4px; }
        .pos.sub { color: #6b7280; font-size: 11px; font-style: italic; margin-bottom: 3px; }
        .pos.dish { color: #4b5563; padding-left: 2px; }
        .pos.dish .b { color: #c4b5fd; }
        .price { margin-top: 20px; border-top: 2px solid #6d28d9; padding-top: 10px; }
        .price table { width: 100%; border-collapse: collapse; }
        .price td { padding: 4px 0; }
        .price .total { font-size: 18px; font-weight: bold; color: #111827; }
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
            <a class="btn" href="{{ request()->fullUrlWithQuery(['pdf' => 1]) }}">PDF herunterladen</a>
            <a class="btn ghost" href="javascript:window.print()">Drucken</a>
            @if($istIntern)
                <a class="btn ghost" href="{{ request()->fullUrlWithQuery(['intern' => null, 'pdf' => null]) }}">→ Kundensicht</a>
            @else
                <a class="btn ghost" href="{{ request()->fullUrlWithQuery(['intern' => 1, 'pdf' => null]) }}">→ Interne Sicht (Marge)</a>
            @endif
        </div>
    @endunless

    <div class="head">
        <h1>{{ $fb->label }}</h1>
        <div class="sub">Foodbook / Portfolio @if($istIntern) <span class="badge-intern">INTERN · Projektleitung / Vertrieb</span> @endif</div>
    </div>

    <table class="meta">
        @if($customer)<tr><td class="k">Kunde</td><td>{{ $customer }}@if(($kontakt ?? null) && $kontakt !== $customer) · {{ $kontakt }} @endif</td></tr>@endif
        @if($fb->jahr)<tr><td class="k">Jahr</td><td>{{ $fb->jahr }}</td></tr>@endif
        @if($gesamt['personen'])<tr><td class="k">Personen</td><td>{{ $gesamt['personen'] }}</td></tr>@endif
        @if($stand ?? null)<tr><td class="k">Stand</td><td>{{ $stand->format('d.m.Y') }}</td></tr>@endif
    </table>

    {{-- Navleiste: klickbare Kapitel-Sprungziele (HTML + PDF-Bookmarks) --}}
    @if(count($kapitel) > 0)
        <div class="nav">
            <div class="navtitle">Navigation</div>
            @foreach($kapitel as $k)
                <a href="#{{ $k['anker'] }}" style="margin-left: {{ $k['depth'] * 14 }}px">
                    @if($k['vk_pro_person'] > 0)<span class="np">{{ number_format($k['vk_pro_person'], 2, ',', '.') }} €/P</span>@endif
                    {{ $istIntern ? ($k['title_intern'] ?: $k['title']) : $k['title'] }}
                </a>
            @endforeach
        </div>
    @endif

    @forelse($kapitel as $k)
        <div class="kapitel" style="margin-left: {{ $k['depth'] * 16 }}px">
            <h2 id="{{ $k['anker'] }}">
                @if($k['vk_pro_person'] > 0)
                    <span class="kpreis">
                        {{ number_format($k['vk_pro_person'], 2, ',', '.') }} €/P
                        @if($istIntern && ($k['ek_pro_person'] ?? null) !== null)
                            · <span class="ek">EK {{ number_format($k['ek_pro_person'], 2, ',', '.') }} €/P</span>
                            @if(($k['food_cost_percent'] ?? null) !== null) · <span class="wpz">W {{ number_format($k['food_cost_percent'], 1, ',', '.') }} %</span> @endif
                        @endif
                    </span>
                @endif
                {{ $istIntern ? ($k['title_intern'] ?: $k['title']) : $k['title'] }}
            </h2>
            @forelse($k['bloecke'] as $b)
                @php($istKonzept = in_array($b['type'], ['concept_ref', 'recipe_ref'], true))
                <div class="pos {{ $b['ist_header'] ? 'header' : ($istKonzept ? 'concept' : '') }}">{{ $b['label'] }}</div>
                @if($b['untertitel'] ?? null)
                    <div class="pos sub">{{ $b['untertitel'] }}</div>
                @endif
                {{-- Wording-Kette: Gerichte eines Concepts als Kundenzeilen --}}
                @foreach($b['gerichte'] ?? [] as $g)
                    @if($g['type'] === 'paket' || $g['type'] === 'header')
                        <div class="pos" style="margin-left:12px; font-weight:bold; color:#374151">{{ $g['text'] }}</div>
                    @else
                        <div class="pos dish" style="margin-left:{{ 12 + $g['einrueckung'] * 12 }}px"><span class="b">·</span> {{ $g['text'] }}</div>
                    @endif
                @endforeach
            @empty
                <div class="pos" style="color:#9ca3af">—</div>
            @endforelse
        </div>
    @empty
        <p style="color:#9ca3af">Noch keine Kapitel angelegt.</p>
    @endforelse

    {{-- Intern: Wareneinsatz je Konzept auf einen Blick (Top-Kapitel), plus Gesamt darunter. --}}
    @if($istIntern && count($kapitel) > 0)
        <div class="price" style="border-top:1px solid #ececec; margin-top:24px">
            <div class="nav navtitle" style="background:none; border:none; padding:0; margin-bottom:6px">Wareneinsatz je Konzept</div>
            <table>
                <tr style="color:#6b7280; font-size:10px; text-transform:uppercase; letter-spacing:.04em">
                    <td>Konzept</td><td class="right">€/P</td><td class="right" style="color:#9333ea">EK/P</td><td class="right" style="color:#059669">W %</td>
                </tr>
                @foreach($kapitel as $k)
                    @if(($k['depth'] ?? 0) === 0)
                        <tr>
                            <td>{{ $k['title_intern'] ?: $k['title'] }}</td>
                            <td class="right">{{ number_format($k['vk_pro_person'] ?? 0, 2, ',', '.') }} €</td>
                            <td class="right" style="color:#9333ea">{{ ($k['ek_pro_person'] ?? null) !== null ? number_format($k['ek_pro_person'], 2, ',', '.') . ' €' : '—' }}</td>
                            <td class="right" style="color:#059669">{{ ($k['food_cost_percent'] ?? null) !== null ? number_format($k['food_cost_percent'], 1, ',', '.') . ' %' : '—' }}</td>
                        </tr>
                    @endif
                @endforeach
            </table>
        </div>
    @endif

    <div class="price">
        <table>
            <tr><td>Preis pro Person</td><td class="right">{{ number_format($gesamt['vk_pro_person'], 2, ',', '.') }} €</td></tr>
            @if($istIntern)
                <tr><td style="color:#9333ea">Wareneinsatz pro Person</td><td class="right" style="color:#9333ea">{{ number_format($gesamt['ek_per_person'], 2, ',', '.') }} €</td></tr>
                @if($gesamt['vk_pro_person'] > 0)
                    <tr><td style="color:#059669">Wareneinsatz %</td><td class="right" style="color:#059669">{{ number_format($gesamt['ek_per_person'] / $gesamt['vk_pro_person'] * 100, 1, ',', '.') }} %</td></tr>
                @endif
            @endif
            @if($gesamt['personen'])<tr><td>Personen</td><td class="right">{{ $gesamt['personen'] }}</td></tr>@endif
            @if($gesamt['gesamt_vk'] !== null)<tr><td class="total">Gesamt</td><td class="right total">{{ number_format($gesamt['gesamt_vk'], 2, ',', '.') }} €</td></tr>@endif
            @if($istIntern && ($gesamt['gesamt_ek'] ?? null) !== null)<tr><td style="color:#9333ea">Gesamt-Wareneinsatz</td><td class="right" style="color:#9333ea">{{ number_format($gesamt['gesamt_ek'], 2, ',', '.') }} €</td></tr>@endif
        </table>
        @php($mwstSatz = ($mwst ?? null) ? (($mwst['default_satz'] ?? 'ermaessigt') === 'regulaer' ? ($mwst['regulaer'] ?? 19) : ($mwst['ermaessigt'] ?? 7)) : null)
        @php($mwstText = 'Alle Preise netto' . ($mwstSatz !== null ? ' zzgl. gesetzl. MwSt (' . rtrim(rtrim(number_format((float) $mwstSatz, 1, ',', '.'), '0'), ',') . ' %)' : '') . '.')
        <div style="color:#9ca3af; font-size:10px; margin-top:6px">{{ $mwstText }}</div>
    </div>

    @if($fb->description)
        <div style="margin-top:16px">{!! nl2br(e($fb->description)) !!}</div>
    @endif

    <div class="foot">Erstellt mit Food Alchemist @if($istIntern) · Interne Fassung — nicht an Kunden weitergeben @endif</div>
</div>
</body>
</html>
