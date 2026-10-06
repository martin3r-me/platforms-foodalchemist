@php
    $pdf = $istPdf ?? false;
    /* fa-pass Druck-Muster (2026-10-05): Palette wie report.blade.php (feste Werte, DomPDF
       kann keine CSS-Variablen). */
    $brand = '#0a3dd6';
    $c = [
        'accent' => $brand, 'ink' => '#131a26', 'ink2' => '#4a5466', 'ink3' => '#657084',
        'line' => '#dde2ea', 'line2' => '#c5ccd8', 'soft' => '#f3f5f8', 'accentSoft' => '#e6edfe',
        'rail' => '#0d1424', 'warn' => '#8a5200', 'warnSoft' => '#fbefd9', 'warnLine' => '#f0d49a',
    ];
    $logoPfad = dirname((new \ReflectionClass(\Platform\FoodAlchemist\FoodAlchemistServiceProvider::class))->getFileName(), 2) . '/resources/brand/fa-wordmark-900.png';
    $logo = is_file($logoPfad) ? 'data:image/png;base64,' . base64_encode((string) file_get_contents($logoPfad)) : null;

    // Küchen-Mengen: kleine kg/l als g/ml, große g/ml als kg/l — nichts gerundet, nur lesbar.
    $zahl = fn ($v, $dec = 3) => rtrim(rtrim(number_format((float) $v, $dec, ',', '.'), '0'), ',');
    $menge = function ($wert, $einheit) use ($zahl) {
        if ($wert === null || $wert === '') {
            return '—';
        }
        $w = (float) $wert;
        $e = trim((string) $einheit);
        $el = mb_strtolower($e);

        return match (true) {
            $el === 'kg' && $w != 0.0 && abs($w) < 1 => $zahl($w * 1000, 1) . ' g',
            $el === 'g' && abs($w) >= 1000 => $zahl($w / 1000) . ' kg',
            $el === 'l' && $w != 0.0 && abs($w) < 1 => $zahl($w * 1000, 1) . ' ml',
            $el === 'ml' && abs($w) >= 1000 => $zahl($w / 1000) . ' l',
            $el === 'stk' => $zahl($w, 2) . ' Stk',
            $el === 'portion' => $zahl($w, 2) . ' Port.',
            default => $zahl($w, in_array($el, ['g', 'ml'], true) ? 1 : 3) . ($e !== '' ? ' ' . $e : ''),
        };
    };
@endphp
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>{{ $titel }}</title>
    <style>
        /* fa-pass Druck-Muster (2026-10-05) wie report.blade.php: feste Bänder oben/unten,
           Tabellen statt Flex/Grid, keine CSS-Variablen, kein font-weight 600. Band-Breite im
           PDF 18.2cm: DomPDF rechnet das Padding zur Breite, sonst läuft das Label aus dem Blatt. */
        @page { size: A4 portrait; margin: {{ $pdf ? '2.15cm 1.4cm 1.5cm 1.4cm' : '1.5cm 1.3cm' }}; }
        * { box-sizing: border-box; }
        body { font-family: "DejaVu Sans", Arial, sans-serif; color: {{ $c['ink'] }}; background: {{ $pdf ? '#fff' : $c['soft'] }}; margin: 0; padding: 0; font-size: 10px; line-height: 1.3; }
        .doc { max-width: {{ $pdf ? 'none' : '960px' }}; margin: 0 auto; background: #fff; padding: {{ $pdf ? '0' : '2.15cm 1.4cm 1.5cm 1.4cm' }}; }
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

        header { margin-bottom: 6px; }
        .kicker { font-size: 9.5px; letter-spacing: .02em; color: {{ $c['ink3'] }}; }
        h1 { font-size: 20px; margin: 2px 0 2px; letter-spacing: -.02em; color: {{ $c['ink'] }}; }
        .rule { height: 3px; width: 3.6cm; background: {{ $brand }}; margin: 5px 0 6px; }
        .sub { color: {{ $c['ink2'] }}; }
        .muted { color: {{ $c['ink3'] }}; font-weight: normal; }
        h3 { font-size: 10.5px; margin: 7px 0 3px; color: {{ $brand }}; letter-spacing: .01em; page-break-after: avoid; }

        .tag { display: inline-block; border: 1px solid {{ $c['line2'] }}; background: {{ $c['soft'] }}; color: {{ $c['ink2'] }}; font-size: 8.5px; font-weight: normal; letter-spacing: .02em; padding: 0 5px; vertical-align: middle; }
        .tag.basis { background: #fff; border-color: {{ $brand }}; color: {{ $brand }}; }
        .tag.warn { background: {{ $c['warnSoft'] }}; border-color: {{ $c['warnLine'] }}; color: {{ $c['warn'] }}; }
        .tag.info { background: {{ $c['accentSoft'] }}; border-color: {{ $c['accentSoft'] }}; color: {{ $brand }}; }

        .rez { margin: 0 0 4px; }
        .rez h2, .lief h2 { font-size: 13px; margin: 12px 0 3px; border-top: 1px solid {{ $c['line'] }}; padding-top: 6px; color: {{ $c['ink'] }}; page-break-after: avoid; }
        .rez .meta { color: {{ $c['ink2'] }}; margin: 0 0 4px; }
        .rez .meta strong.gross { font-size: 13px; color: {{ $brand }}; }
        .ausgabe { color: {{ $c['ink2'] }}; margin: 3px 0 4px; }
        .ausgabe strong { color: {{ $c['ink'] }}; }

        table.liste { width: 100%; border-collapse: collapse; table-layout: fixed; margin: 3px 0 6px; page-break-inside: auto; }
        table.liste thead { display: table-header-group; }
        table.liste tr { page-break-inside: avoid; }
        table.liste th, table.liste td { border: 1px solid {{ $c['line'] }}; padding: 2px 5px; text-align: left; vertical-align: top; overflow-wrap: anywhere; }
        table.liste th { background: {{ $c['soft'] }}; color: {{ $c['ink2'] }}; font-size: 8.5px; font-weight: bold; letter-spacing: .02em; }
        table.liste .right { text-align: right; }
        table.liste td.menge { font-size: 11px; font-weight: bold; white-space: nowrap; }
        table.liste th.c-menge { width: 16%; }
        table.liste th.c-kurz { width: 8%; }
        table.liste th.c-bestellen { width: 22%; }
        table.liste th.c-bedarf { width: 12%; }
        table.liste th.c-ek { width: 12%; }

        /* Lieferanten-Kopf: Name links, Summe rechts — als Tabelle, weil DomPDF kein Flex kann. */
        table.lief-kopf { width: 100%; border-collapse: collapse; margin: 12px 0 2px; border-top: 1px solid {{ $c['line'] }}; page-break-after: avoid; }
        table.lief-kopf td { padding: 6px 0 0; vertical-align: bottom; font-size: 13px; font-weight: bold; color: {{ $c['ink'] }}; }
        table.lief-kopf td.sum { text-align: right; white-space: nowrap; }
        .lief { margin-bottom: 4px; }

        .warn-box { color: {{ $c['warn'] }}; background: {{ $c['warnSoft'] }}; border: 1px solid {{ $c['warnLine'] }}; padding: 5px 7px; margin: 6px 0 8px; }
        .warn-box ul { margin: 2px 0 0; padding-left: 14px; }
        table.grand { width: 100%; border-collapse: collapse; margin-top: 8px; page-break-inside: avoid; }
        table.grand td { border-top: 2px solid {{ $brand }}; padding: 5px 0 0; font-size: 13px; font-weight: bold; }
        table.grand td.sum { text-align: right; white-space: nowrap; }
        table.grand .muted { font-size: 9.5px; }

        @media print {
            .actions { display: none; }
            body { background: #fff; }
            .doc { max-width: none; margin: 0; padding: 0; }
            .band-top { margin-bottom: 12px; }
            .band-bottom { margin-top: 14px; }
        }
@include('foodalchemist::dokumente.partials.schritt-karten-css')
    </style>
</head>
<body>
<div class="band-top">
    @if($logo)<span class="bt-logo"><img src="{{ $logo }}" alt="Food.Alchemist"></span>@endif
    <span class="bt-label">{{ $titel }}</span>
</div>
<main class="doc">
    @unless($pdf)
        <div class="actions">
            <a class="btn primary" href="{{ request()->fullUrlWithQuery(['pdf' => 1]) }}">PDF herunterladen</a>
            <a class="btn" href="javascript:window.print()">Drucken</a>
            @if($mitFotos ?? true)
                <a class="btn" href="{{ request()->fullUrlWithQuery(['fotos' => 0]) }}">Anleitung ohne Fotos</a>
            @else
                <a class="btn" href="{{ request()->fullUrlWithQuery(['fotos' => 1]) }}">Anleitung mit Fotos</a>
            @endif
        </div>
    @endunless

    <header>
        <div class="kicker">{{ $titel }} · {{ now()->format('d.m.Y H:i') }}</div>
        <h1>{{ $titel }}</h1>
        <div class="rule"></div>
        <div class="sub">{{ $untertitel }}</div>
    </header>

    @if($blatt['warnungen'] ?? false)
        <div class="warn-box">
            <strong>Hinweise:</strong>
            <ul>@foreach(array_unique($blatt['warnungen']) as $w)<li>{{ $w }}</li>@endforeach</ul>
        </div>
    @endif

    @if($typ === 'produktion')
        @forelse($blatt['rezepte'] as $r)
            <div class="rez">
                <h2>{{ $r['name'] }} @if($r['ist_basisrezept'])<span class="tag basis">Basisrezept</span>@endif</h2>
                <div class="meta">
                    @if($r['ist_basisrezept'])
                        <strong class="gross">{{ $r['ansaetze'] }} {{ (float) $r['ansaetze'] == 1.0 ? 'Ansatz' : 'Ansätze' }}</strong>@if($r['basis_yield_kg']) à {{ number_format($r['basis_yield_kg'], 3, ',', '.') }} kg = <strong class="gross">{{ number_format($r['produzierte_menge_kg'], 2, ',', '.') }} kg</strong>@endif
                        · <span class="muted">Bedarf im Menü: {{ number_format($r['benoetigt_ansaetze'], 2, ',', '.') }} Ansätze</span>
                    @else
                        <strong class="gross">{{ $r['portionen'] }} Portionen</strong>@if($r['produzierte_menge_kg']) · gesamt <strong class="gross">{{ number_format($r['produzierte_menge_kg'], 1, ',', '.') }} kg</strong>@endif
                    @endif
                    @if($r['arbeitszeit_min']) · Arbeitszeit ca. {{ $r['arbeitszeit_min'] }} min @endif
                </div>
                <table class="liste">
                    <thead><tr><th>Zutat</th><th class="right c-menge">Menge</th></tr></thead>
                    <tbody>
                        @foreach($r['zutaten'] as $z)
                            <tr>
                                <td>{{ $z['typ'] === 'sub' ? '↳ ' : '' }}{{ $z['name'] }}@if($z['typ'] === 'sub') <span class="tag info">Basisrezept</span>@endif@if($z['typ'] === 'ungemappt') <span class="tag warn">ohne Grundprodukt</span>@endif@if($z['optional']) <span class="muted">(n. B.)</span>@endif@if($z['note']) <span class="muted">— {{ $z['note'] }}</span>@endif</td>
                                <td class="right menge">{{ $menge($z['menge'], $z['einheit']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                {{-- §3.2 Regenerations-Programm je Komponente (V-19). Steht bewusst VOR der
                     Schrittfolge: am Einsatztag wird zuerst regeneriert, dann fertiggestellt. --}}
                @if(count($r['regenerationen'] ?? []))
                    <h3>Regeneration</h3>
                    <table class="liste">
                        <thead><tr><th>Komponente</th><th>Gerät</th><th class="right c-kurz">°C</th><th class="right c-kurz">min</th><th class="right c-kurz">Kern °C</th><th>Hinweis</th></tr></thead>
                        <tbody>
                        @foreach($r['regenerationen'] as $reg)
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
                @if($r['darreichung'] ?? null)
                    @php($d = $r['darreichung'])
                    <p class="ausgabe">
                        <strong>Ausgabe:</strong>
                        {{-- Die Regenerations-Skalare der Standard-Darreichung bleiben als Fallback
                             stehen (Alt-Daten aus dem WaWi-Import); die gepflegte Wahrheit ist die
                             Tabelle oben. Kein Schreibpfad füllt die Skalare heute noch. --}}
                        @if(! count($r['regenerationen'] ?? []))
                            {{-- Spec 51: die Regeneration kommt aus `regen_snapshot` (je Komponente),
                                 nicht mehr aus den Darreichungs-Skalaren — die waren die zweite Quelle. --}}
                            @if(($d['geraet'] ?? null)) · Gerät {{ $d['geraet'] }} @endif
                        @endif
                        @if(($d['behaelter_warm'] ?? null)) · Behälter warm {{ $d['behaelter_warm'] }} @endif
                        @if(($d['behaelter_kalt'] ?? null)) · Behälter kalt {{ $d['behaelter_kalt'] }} @endif
                        @if(($d['vehikel'] ?? null)) · Vehikel {{ $d['vehikel'] }} @endif
                        @if(($d['arbeitszeit_zuschlag_min'] ?? null) !== null) · +{{ $d['arbeitszeit_zuschlag_min'] }} min Ausgabe @endif
                    </p>
                @endif
                @if(!empty($r['schritte']) || !empty($r['zubereitung']))<h3>Anleitung</h3>@endif
                @include('foodalchemist::dokumente.partials.schritt-karten', [
                    'schritte' => $r['schritte'] ?? [],
                    'zubereitung' => $r['zubereitung'] ?? null,
                    'mitFotos' => $mitFotos ?? true,
                    'istPdf' => $pdf,
                ])
            </div>
        @empty
            <p class="muted">Keine skalierbaren Positionen.</p>
        @endforelse
    @else
        @php($gesamt = 0.0)
        @forelse($blatt['lieferanten'] as $g)
            @php($gesamt += $g['ek_summe'])
            <div class="lief">
                <table class="lief-kopf">
                    <tr>
                        <td>{{ $g['lieferant'] }}</td>
                        <td class="sum">{{ number_format($g['ek_summe'], 2, ',', '.') }} €@unless($g['ek_vollstaendig']) <span class="tag warn">Einkaufspreis unvollständig</span>@endunless</td>
                    </tr>
                </table>
                <table class="liste">
                    <thead><tr><th>Artikel</th><th class="right c-bestellen">Bestellen</th><th class="right c-bedarf">Bedarf</th><th class="right c-ek">EK netto</th></tr></thead>
                    <tbody>
                        @foreach($g['positionen'] as $p)
                            @php($geb = $p['gebinde'])
                            <tr>
                                <td>{{ $p['gp'] }}@if($p['lead_artikel'])<br><span class="muted">@if($geb['article_number']){{ $geb['article_number'] }} · @endif{{ $p['lead_artikel'] }}</span>@endif@if($p['ausweich'])<br><span class="tag info">Ausweich: {{ $p['ausweich']['artikel'] }} ({{ $p['ausweich']['lieferant'] }})</span>@endif</td>
                                <td class="right">@if($geb['berechenbar'])<strong>{{ $geb['qty_packs'] }}×</strong> {{ rtrim(rtrim(number_format($geb['pack_qty'], 3, ',', '.'), '0'), ',') }} {{ $geb['pack_unit_code'] }}@if($geb['packaging_unit']) {{ $geb['packaging_unit'] }}@endif@if($geb['pack_price'] !== null)<br><span class="muted">à {{ number_format($geb['pack_price'], 2, ',', '.') }} €</span>@endif @else<span class="muted">{{ $geb['grund'] }}</span>@endif</td>
                                <td class="right">@if($geb['berechenbar']){{ number_format($geb['needed_base'], $geb['needed_base_unit'] === 'Stk' ? 0 : 2, ',', '.') }} {{ $geb['needed_base_unit'] }}@else {{ number_format($p['menge_kg'], 3, ',', '.') }} kg @endif</td>
                                <td class="right">{{ $p['ek_bekannt'] ? number_format($p['bestell_ek_eur'], 2, ',', '.') . ' €' : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @empty
            <p class="muted">Kein Bedarf an Grundprodukten.</p>
        @endforelse
        <table class="grand">
            <tr>
                <td>Einkaufswert gesamt <span class="muted">(netto)</span></td>
                <td class="sum">{{ number_format($gesamt, 2, ',', '.') }} €</td>
            </tr>
        </table>
    @endif
</main>
<div class="band-bottom">
    <span class="bb-foot">Erstellt mit Food.Alchemist · {{ $titel }} · rein rechnend (kein Bestellvorgang)</span>
</div>
</body>
</html>
