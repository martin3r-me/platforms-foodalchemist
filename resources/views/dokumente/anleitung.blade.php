{{--
    Spec 27 Phase 4 — Postenzettel: NUR die Anleitung eines Rezepts, groß gesetzt
    zum Aufhängen am Posten. Kein Wareneinsatz, kein Einkauf, keine Kalkulation —
    das steht im Produktionsblatt. `?fotos=0` druckt die Textfassung.
--}}
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Anleitung: {{ $rezept->name }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: "DejaVu Sans", Arial, sans-serif; color: #1f2937; font-size: 14px; line-height: 1.55; margin: 0; padding: 32px; }
        .doc { max-width: 760px; margin: 0 auto; }
        .head { border-bottom: 2px solid #6d28d9; padding-bottom: 12px; margin-bottom: 18px; }
        h1 { font-size: 22px; margin: 0 0 4px; color: #111827; }
        .sub { color: #6b7280; font-size: 12px; }
        .muted { color: #9ca3af; }
        .zutaten-kurz { margin: 0 0 18px; font-size: 12px; color: #374151; }
        .foot { margin-top: 32px; color: #9ca3af; font-size: 10px; border-top: 1px solid #ececec; padding-top: 10px; }
        .actions { margin-bottom: 18px; }
        .btn { display: inline-block; padding: 6px 12px; background: #6d28d9; color: #fff; text-decoration: none; border-radius: 6px; margin-right: 6px; }
        .btn.ghost { background: #eee; color: #374151; }
        @media print { .actions { display: none; } body { padding: 0; } }
@include('foodalchemist::dokumente.partials.schritt-karten-css')
        /* Postenzettel: bewusst größer als im Produktionsblatt — es wird im Stehen gelesen. */
        .anleitung .schritt { margin-bottom: 12px; }
        .anleitung .schritt-nr { width: 24px; height: 24px; line-height: 24px; font-size: 13px; border-radius: 12px; }
        .anleitung .schritt-body { margin-left: 34px; }
        .anleitung .schritt-text { font-size: 15px; }
        .anleitung .schritt-foto img { width: 180px; height: 126px; }
        .anleitung .schritt-foto .cap { max-width: 180px; font-size: 10px; }
        .anleitung-phase { font-size: 12px; margin: 16px 0 4px; }
        /* Endprodukt-Bild: der Koch soll erst sehen, wo er hin will. */
        .endprodukt { margin: 0 0 18px; page-break-inside: avoid; }
        .endprodukt:after { content: ""; display: block; clear: both; }
        .endprodukt img { float: left; width: 220px; height: 154px; object-fit: cover; border: 1px solid #e5e7eb; border-radius: 6px; margin-right: 12px; }
        .endprodukt .label { font-size: 10px; text-transform: uppercase; letter-spacing: .06em; color: #b45309; font-weight: bold; }
        .endprodukt .cap { font-size: 12px; color: #374151; margin-top: 2px; }
    </style>
</head>
<body>
<div class="doc">
    @unless($istPdf ?? false)
        <div class="actions">
            <a class="btn" href="{{ request()->fullUrlWithQuery(['pdf' => 1]) }}">PDF herunterladen</a>
            <a class="btn ghost" href="javascript:window.print()">Drucken</a>
            @if($mitFotos)
                <a class="btn ghost" href="{{ request()->fullUrlWithQuery(['fotos' => 0]) }}">nur Text</a>
            @else
                <a class="btn ghost" href="{{ request()->fullUrlWithQuery(['fotos' => 1]) }}">mit Fotos</a>
            @endif
            {{-- Die beiden Nachbar-Ebenen (§3.2/§3.3) sind zuschaltbar: der Posten braucht die
                 Regeneration, der Pass das Anrichten — selten beide auf einem Zettel. --}}
            @if(count($regenerationen ?? []))
                <a class="btn ghost" href="{{ request()->fullUrlWithQuery(['regen' => 0]) }}">ohne Regeneration</a>
            @else
                <a class="btn ghost" href="{{ request()->fullUrlWithQuery(['regen' => 1]) }}">mit Regeneration</a>
            @endif
            @if(count($anrichteSchritte ?? []) || ($plating ?? null))
                <a class="btn ghost" href="{{ request()->fullUrlWithQuery(['anrichten' => 0]) }}">ohne Anrichten</a>
            @else
                <a class="btn ghost" href="{{ request()->fullUrlWithQuery(['anrichten' => 1]) }}">mit Anrichten</a>
            @endif
        </div>
    @endunless

    <div class="head">
        <h1>{{ $rezept->name }}</h1>
        <div class="sub">
            Anleitung{{ $mitFotos ? ' mit Fotos' : ' (Textfassung)' }}
            @if($rezept->yield_kg !== null) · Ansatz {{ rtrim(rtrim(number_format((float) $rezept->yield_kg, 3, ',', '.'), '0'), ',') }} kg @endif
            @if($rezept->work_time_min !== null) · {{ (int) $rezept->work_time_min }} min Arbeitszeit @endif
        </div>
    </div>

    {{-- Endprodukt-Bild zuerst — nur wenn Fotos gedruckt werden --}}
    @if($mitFotos && ($endprodukt['quelle'] ?? null))
        <div class="endprodukt">
            <img src="{{ $endprodukt['quelle'] }}" alt="{{ $endprodukt['caption'] ?? 'Endprodukt' }}" />
            <p class="label">So soll es fertig aussehen</p>
            @if($endprodukt['caption'] ?? null)
                <p class="cap">{{ $endprodukt['caption'] }}</p>
            @endif
        </div>
    @endif

    @if($zutaten->isNotEmpty())
        <p class="zutaten-kurz">
            <strong>Zutaten:</strong>
            {{ $zutaten->map(fn ($z) => trim(($z['menge'] ?? '') . ' ' . ($z['einheit'] ?? '') . ' ' . $z['name']))->implode(' · ') }}
        </p>
    @endif

    @if(count($regenerationen ?? []))
        <p class="zutaten-kurz"><strong>Regeneration:</strong>
            {{ collect($regenerationen)->map(fn ($r) => trim(
                ($r['komponente'] ?? '') . ' — ' .
                collect([$r['geraet'] ?? null,
                         ($r['temp_c'] ?? null) !== null ? $r['temp_c'] . ' °C' : null,
                         ($r['duration_min'] ?? null) !== null ? $r['duration_min'] . ' min' : null,
                         ($r['core_temp_c'] ?? null) !== null ? 'KT ' . $r['core_temp_c'] . ' °C' : null,
                         $r['note'] ?? null,
                ])->filter()->implode(' · ')))->implode(' | ') }}
        </p>
    @endif

    @include('foodalchemist::dokumente.partials.schritt-karten', [
        'schritte' => $schritte,
        'zubereitung' => $rezept->preparation,
        'mitFotos' => $mitFotos,
        'istPdf' => $istPdf ?? false,
    ])

    @if(count($anrichteSchritte ?? []))
        <p class="zutaten-kurz"><strong>Anrichten &amp; Ausgabe</strong></p>
        @include('foodalchemist::dokumente.partials.schritt-karten', [
            'schritte' => $anrichteSchritte,
            'zubereitung' => null,
            'mitFotos' => $mitFotos,
            'istPdf' => $istPdf ?? false,
        ])
    @elseif($plating ?? null)
        <p class="zutaten-kurz"><strong>Anrichten:</strong> {{ $plating }}</p>
    @endif

    @if(empty($schritte) && trim((string) $rezept->preparation) === '')
        <p class="muted">Für dieses Rezept ist noch keine Zubereitung erfasst.</p>
    @endif

    <div class="foot">Food Alchemist · {{ \Illuminate\Support\Carbon::now()->format('d.m.Y H:i') }}</div>
</div>
</body>
</html>
