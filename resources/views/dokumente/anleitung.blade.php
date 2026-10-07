{{--
    Spec 27 Phase 4 — Postenzettel: NUR die Anleitung eines Rezepts, groß gesetzt
    zum Aufhängen am Posten. Kein Wareneinsatz, kein Einkauf, keine Kalkulation —
    das steht im Produktionsblatt. `?fotos=0` druckt die Textfassung.
    fa-pass Druck-Muster (2026-10-05): Kopf/Fuß/Palette wie report.blade.php; Zutaten als
    zweispaltige Liste (Menge vorn, fett) statt Fließtext, Regeneration als Tabelle.
--}}
@php
    $pdf = $istPdf ?? false;
    $brand = '#0a3dd6';
    $c = [
        'accent' => $brand, 'ink' => '#131a26', 'ink2' => '#4a5466', 'ink3' => '#657084',
        'line' => '#dde2ea', 'soft' => '#f3f5f8', 'accentSoft' => '#e6edfe', 'rail' => '#0d1424',
        'warn' => '#8a5200', 'warnSoft' => '#fbefd9', 'warnLine' => '#f0d49a',
    ];
    $logoPfad = dirname((new \ReflectionClass(\Platform\FoodAlchemist\FoodAlchemistServiceProvider::class))->getFileName(), 2) . '/resources/brand/fa-wordmark-900.png';
    $logo = is_file($logoPfad) ? 'data:image/png;base64,' . base64_encode((string) file_get_contents($logoPfad)) : null;

    // Küchen-Mengen: die Route liefert deutsch formatierte Zahlen („1.000", „0,25") + Einheit.
    // Nur eindeutige kg/g/l/ml werden umgerechnet (kleine kg/l → g/ml, große g/ml → kg/l).
    $zahl = fn ($v, $dec = 3) => rtrim(rtrim(number_format((float) $v, $dec, ',', '.'), '0'), ',');
    $menge = function ($roh, $einheit) use ($zahl) {
        $roh = trim((string) $roh);
        $e = trim((string) $einheit);
        if ($roh === '') {
            return $e;
        }
        if (! preg_match('/^\d{1,3}(?:\.\d{3})*(?:,\d+)?$|^\d+(?:,\d+)?$/', $roh)) {
            return trim($roh . ' ' . $e);
        }
        $w = (float) str_replace(['.', ','], ['', '.'], $roh);
        $el = mb_strtolower($e);

        return match (true) {
            $el === 'kg' && $w != 0.0 && $w < 1 => $zahl($w * 1000, 1) . ' g',
            $el === 'g' && $w >= 1000 => $zahl($w / 1000) . ' kg',
            $el === 'l' && $w != 0.0 && $w < 1 => $zahl($w * 1000, 1) . ' ml',
            $el === 'ml' && $w >= 1000 => $zahl($w / 1000) . ' l',
            $el === 'stk' => $roh . ' Stk',
            $el === 'portion' => $roh . ' Port.',
            default => trim($roh . ' ' . $e),
        };
    };
@endphp
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Anleitung: {{ $rezept->name }}</title>
    <style>
        @page { size: A4 portrait; margin: {{ $pdf ? '2.15cm 1.4cm 1.5cm 1.4cm' : '1.5cm 1.3cm' }}; }
        * { box-sizing: border-box; }
        body { font-family: "DejaVu Sans", Arial, sans-serif; color: {{ $c['ink'] }}; background: {{ $pdf ? '#fff' : $c['soft'] }}; margin: 0; padding: 0; font-size: 10px; line-height: 1.3; }
        .doc { max-width: {{ $pdf ? 'none' : '960px' }}; margin: 0 auto; background: #fff; padding: {{ $pdf ? '0' : '2.15cm 1.4cm 1.5cm 1.4cm' }}; }
        /* Band-Breite im PDF 18.2cm: DomPDF rechnet das Padding zur Breite, sonst läuft das Label aus dem Blatt. */
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
        .actions { background: {{ $c['rail'] }}; color: #fff; padding: 12px 16px; margin: {{ $pdf ? '0' : '-2.15cm -1.4cm 20px' }}; }
        .btn { display: inline-block; color: #fff; text-decoration: none; border: 1px solid rgba(182,192,210,.28); border-radius: 999px; padding: 5px 10px; margin: 2px; font-size: 11px; }
        .btn.primary { background: {{ $brand }}; border-color: {{ $brand }}; }

        header { margin-bottom: 8px; }
        .kicker { font-size: 9.5px; letter-spacing: .02em; color: {{ $c['ink3'] }}; }
        h1 { font-size: 20px; margin: 2px 0 2px; letter-spacing: -.02em; color: {{ $c['ink'] }}; }
        .rule { height: 3px; width: 3.6cm; background: {{ $brand }}; margin: 5px 0 6px; }
        .sub { color: {{ $c['ink2'] }}; }
        .muted { color: {{ $c['ink3'] }}; }
        h2 { font-size: 13px; margin: 12px 0 4px; border-top: 1px solid {{ $c['line'] }}; padding-top: 6px; color: {{ $c['ink'] }}; page-break-after: avoid; }

        /* Zutaten: zwei Paare je Zeile, Menge vorn und fett — am Posten wird zuerst die Menge gesucht. */
        table.liste { width: 100%; border-collapse: collapse; table-layout: fixed; margin: 3px 0 6px; }
        table.liste tr { page-break-inside: avoid; }
        table.liste th, table.liste td { border: 1px solid {{ $c['line'] }}; padding: 3px 6px; text-align: left; vertical-align: top; overflow-wrap: anywhere; font-size: 11px; }
        table.liste th { background: {{ $c['soft'] }}; color: {{ $c['ink2'] }}; font-size: 8.5px; font-weight: bold; letter-spacing: .02em; }
        table.liste td.menge { text-align: right; font-weight: bold; white-space: nowrap; }
        table.liste .right { text-align: right; }
        table.liste th.c-menge { width: 13%; }
        table.liste th.c-kurz { width: 8%; }
        p.anrichten { font-size: 12px; white-space: pre-line; margin: 3px 0 6px; }

@include('foodalchemist::dokumente.partials.schritt-karten-css')
        /* Postenzettel: bewusst größer als im Produktionsblatt — es wird im Stehen gelesen. */
        table.schritte td.schritt-nr { width: 7%; font-size: 15px; padding-top: 4px; }
        table.schritte td.schritt-text { font-size: 13px; line-height: 1.4; padding-top: 5px; padding-bottom: 5px; }
        table.schritte td.anleitung-phase { font-size: 10.5px; padding: 3px 6px; }
        .schritt-foto img { height: 3.4cm; max-width: 7cm; }
        .schritt-foto .cap { max-width: 5cm; font-size: 9px; }
        .zubereitung-fallback { font-size: 12px; line-height: 1.45; color: {{ $c['ink'] }}; }

        /* Endprodukt-Bild: der Koch soll erst sehen, wo er hin will. Tabelle statt float. */
        table.endprodukt { width: 100%; border-collapse: collapse; margin: 0 0 8px; page-break-inside: avoid; }
        table.endprodukt td { vertical-align: top; padding: 0; }
        table.endprodukt td.bild { width: 40%; padding-right: 10px; }
        table.endprodukt img { display: block; height: 4.6cm; width: auto; max-width: 6.8cm; border: 1px solid {{ $c['line'] }}; }
        .endprodukt .label { font-size: 11px; color: {{ $brand }}; font-weight: bold; margin: 0 0 2px; }
        .endprodukt .cap { font-size: 11px; color: {{ $c['ink2'] }}; margin: 0; }

        @media print {
            .actions { display: none; }
            body { background: #fff; }
            .doc { max-width: none; margin: 0; padding: 0; }
            .band-top { margin-bottom: 12px; }
            .band-bottom { margin-top: 14px; }
        }
    </style>
</head>
<body>
<div class="band-top">
    @if($logo)<span class="bt-logo"><img src="{{ $logo }}" alt="Food.Alchemist"></span>@endif
    <span class="bt-label">Anleitung</span>
</div>
<main class="doc">
    @unless($pdf)
        <div class="actions">
            <a class="btn primary" href="{{ request()->fullUrlWithQuery(['pdf' => 1]) }}">PDF herunterladen</a>
            <a class="btn" href="javascript:window.print()">Drucken</a>
            @if($mitFotos)
                <a class="btn" href="{{ request()->fullUrlWithQuery(['fotos' => 0]) }}">nur Text</a>
            @else
                <a class="btn" href="{{ request()->fullUrlWithQuery(['fotos' => 1]) }}">mit Fotos</a>
            @endif
            {{-- Die beiden Nachbar-Ebenen (§3.2/§3.3) sind zuschaltbar: der Posten braucht die
                 Regeneration, der Pass das Anrichten — selten beide auf einem Zettel. --}}
            @if(count($regenerationen ?? []))
                <a class="btn" href="{{ request()->fullUrlWithQuery(['regen' => 0]) }}">ohne Regeneration</a>
            @else
                <a class="btn" href="{{ request()->fullUrlWithQuery(['regen' => 1]) }}">mit Regeneration</a>
            @endif
            @if(count($anrichteSchritte ?? []) || ($plating ?? null))
                <a class="btn" href="{{ request()->fullUrlWithQuery(['anrichten' => 0]) }}">ohne Anrichten</a>
            @else
                <a class="btn" href="{{ request()->fullUrlWithQuery(['anrichten' => 1]) }}">mit Anrichten</a>
            @endif
        </div>
    @endunless

    <header>
        <div class="kicker">Anleitung · {{ \Illuminate\Support\Carbon::now()->format('d.m.Y H:i') }}</div>
        <h1>{{ $rezept->name }}</h1>
        <div class="rule"></div>
        <div class="sub">
            Anleitung{{ $mitFotos ? ' mit Fotos' : ' (Textfassung)' }}
            @if($rezept->yield_kg !== null) · Ansatz {{ rtrim(rtrim(number_format((float) $rezept->yield_kg, 3, ',', '.'), '0'), ',') }} kg @endif
            @if($rezept->work_time_min !== null) · {{ (int) $rezept->work_time_min }} min Arbeitszeit @endif
        </div>
    </header>

    {{-- Endprodukt-Bild zuerst — nur wenn Fotos gedruckt werden --}}
    @if($mitFotos && ($endprodukt['quelle'] ?? null))
        <table class="endprodukt">
            <tr>
                <td class="bild"><img src="{{ $endprodukt['quelle'] }}" alt="{{ $endprodukt['caption'] ?? 'Endprodukt' }}" /></td>
                <td>
                    <p class="label">So soll es fertig aussehen</p>
                    @if($endprodukt['caption'] ?? null)
                        <p class="cap">{{ $endprodukt['caption'] }}</p>
                    @endif
                </td>
            </tr>
        </table>
    @endif

    @if($zutaten->isNotEmpty())
        <h2>Zutaten</h2>
        <table class="liste zutaten-kurz">
            <thead><tr><th class="c-menge right">Menge</th><th>Zutat</th><th class="c-menge right">Menge</th><th>Zutat</th></tr></thead>
            <tbody>
            @foreach($zutaten->values()->chunk(2) as $paar)
                <tr>
                    @foreach($paar as $z)
                        <td class="menge">{{ $menge($z['menge'] ?? '', $z['einheit'] ?? '') }}</td>
                        <td>{{ $z['name'] }}</td>
                    @endforeach
                    @if($paar->count() < 2)<td></td><td></td>@endif
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif

    @if(count($regenerationen ?? []))
        <h2>Regeneration</h2>
        <table class="liste">
            <thead><tr><th>Komponente</th><th>Gerät</th><th class="right c-kurz">°C</th><th class="right c-kurz">min</th><th class="right c-kurz">Kern °C</th><th>Hinweis</th></tr></thead>
            <tbody>
            @foreach($regenerationen as $reg)
                <tr>
                    <td>{{ $reg['komponente'] ?? '—' }}</td>
                    <td>{{ $reg['geraet'] ?? '—' }}</td>
                    <td class="right">{{ $reg['temp_c'] ?? '—' }}</td>
                    <td class="right">{{ $reg['duration_min'] ?? '—' }}</td>
                    <td class="right">{{ $reg['core_temp_c'] ?? '—' }}</td>
                    <td>{{ $reg['note'] ?? '' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif

    @if(!empty($schritte) || trim((string) $rezept->preparation) !== '')
        <h2>Zubereitung</h2>
    @endif
    @include('foodalchemist::dokumente.partials.schritt-karten', [
        'schritte' => $schritte,
        'zubereitung' => $rezept->preparation,
        'mitFotos' => $mitFotos,
        'istPdf' => $pdf,
    ])

    @if(count($anrichteSchritte ?? []))
        <h2>Anrichten &amp; Ausgabe</h2>
        @include('foodalchemist::dokumente.partials.schritt-karten', [
            'schritte' => $anrichteSchritte,
            'zubereitung' => null,
            'mitFotos' => $mitFotos,
            'istPdf' => $pdf,
        ])
    @elseif($plating ?? null)
        <h2>Anrichten</h2>
        <p class="anrichten">{{ $plating }}</p>
    @endif

    @if(empty($schritte) && trim((string) $rezept->preparation) === '')
        <p class="muted">Für dieses Rezept ist noch keine Zubereitung erfasst.</p>
    @endif
</main>
<div class="band-bottom">
    <span class="bb-foot">Erstellt mit Food.Alchemist · Anleitung</span>
</div>
</body>
</html>
