@php
    // Die Route reicht `$data + ['istPdf' => true]` durch — `$data` traegt aber schon
    // `istPdf => false`, und `+` behaelt den linken Wert. Ohne den Request-Blick stand die
    // dunkle Filterleiste im PDF und die Fotos kamen als URL statt als Dateipfad.
    $pdf = ($istPdf ?? false) || request()->boolean('pdf');
    $opt = $optionen ?? [
        'profil' => 'voll',
        'rezepte' => true,
        'zutaten' => true,
        'anleitung' => true,
        'bilder' => $mitFotos ?? true,
        'darreichung' => true,
        'notizen' => true,
        'posten' => '',
    ];
    $profile = [
        'kurz' => 'Kurzblatt',
        'produktion' => 'Produktion',
    ];
    $filter = [
        'rezepte' => 'Rezepte',
        'zutaten' => 'Zutaten',
        'anleitung' => 'Anleitung',
        'regeneration' => 'Regeneration',
        'anrichten' => 'Anrichten',
        'bilder' => 'Bilder',
        'darreichung' => 'Darreichung',
        'notizen' => 'Notizen',
    ];
    $profilReset = array_merge(array_fill_keys(array_keys($filter), null), ['posten' => null]);
    $posten = collect($dok['zeilen'])
        ->filter(fn ($z) => ($z['station_id'] ?? null) !== null)
        ->mapWithKeys(fn ($z) => [(string) $z['station_id'] => $z['station'] ?? ('Posten #' . $z['station_id'])])
        ->sort();
    $hatOhnePosten = collect($dok['zeilen'])->contains(fn ($z) => ($z['station_id'] ?? null) === null);
    $zeilen = collect($dok['zeilen'])
        ->when(($opt['posten'] ?? '') === 'ohne', fn ($z) => $z->whereNull('station_id'))
        ->when(($opt['posten'] ?? '') !== '' && ($opt['posten'] ?? '') !== 'ohne', fn ($z) => $z->where('station_id', (int) $opt['posten']))
        ->values();

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
    $darreichungLabel = [
        'vehikel' => 'Vehikel', 'geschirr' => 'Geschirr', 'geraet' => 'Gerät',
        'behaelter_warm' => 'Behälter warm', 'behaelter_kalt' => 'Behälter kalt',
        'arbeitszeit_zuschlag_min' => 'Zuschlag Ausgabe (min)',
    ];
@endphp
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Produktionsschein {{ $dok['production_date'] }}</title>
    <style>
        /* fa-pass Druck-Muster (2026-10-05) wie report.blade.php: feste Bänder oben/unten,
           Tabellen statt Flex/Grid, keine CSS-Variablen, kein font-weight 600 (DomPDF fällt
           sonst auf die Serifenschrift zurück). Band-Breite im PDF 18.2cm statt 21cm: DomPDF
           rechnet das Padding (2 × 1.4cm) zur Breite dazu, sonst läuft die Dokumentart rechts
           aus dem Blatt. */
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
        .action-row + .action-row { margin-top: 6px; }
        .action-label { display: inline-block; min-width: 70px; color: #b6c0d2; font-weight: bold; margin-right: 4px; }
        .btn { display: inline-block; color: #fff; text-decoration: none; border: 1px solid rgba(182,192,210,.28); border-radius: 999px; padding: 5px 10px; margin: 2px; font-size: 11px; }
        .btn.active, .btn.primary { background: {{ $brand }}; border-color: {{ $brand }}; }

        header { margin-bottom: 6px; }
        .kicker { font-size: 9.5px; letter-spacing: .02em; color: {{ $c['ink3'] }}; }
        h1 { font-size: 20px; margin: 2px 0 2px; letter-spacing: -.02em; color: {{ $c['ink'] }}; }
        .rule { height: 3px; width: 3.6cm; background: {{ $brand }}; margin: 5px 0 6px; }
        .muted { color: {{ $c['ink3'] }}; font-weight: normal; }
        .sub { color: {{ $c['ink2'] }}; margin: 0 0 2px; }
        .notiz { margin: 4px 0 0; padding: 3px 7px; border-left: 3px solid {{ $c['warnLine'] }}; background: {{ $c['warnSoft'] }}; color: {{ $c['warn'] }}; }

        /* Ein Rezept = ein Block. Kopf + Kennzahlen bleiben zusammen, die Menge steht groß. */
        .rezept { margin: 0 0 4px; padding: 0; }
        .rezept h2 { font-size: 13px; margin: 12px 0 3px; border-top: 1px solid {{ $c['line'] }}; padding-top: 6px; color: {{ $c['ink'] }}; page-break-after: avoid; }
        .chip { display: inline-block; border: 1px solid {{ $brand }}; color: {{ $brand }}; background: #fff; font-size: 8.5px; font-weight: normal; letter-spacing: .02em; padding: 1px 6px; vertical-align: middle; }
        table.kennzahlen { width: 100%; border-collapse: collapse; table-layout: fixed; margin: 3px 0 5px; page-break-inside: avoid; page-break-after: avoid; }
        table.kennzahlen td { border: 1px solid {{ $c['line'] }}; padding: 2px 6px; vertical-align: top; overflow-wrap: anywhere; }
        table.kennzahlen td span { display: block; color: {{ $c['ink3'] }}; font-size: 8.5px; letter-spacing: .02em; }
        table.kennzahlen td.gross { background: {{ $c['accentSoft'] }}; border-color: {{ $c['accentSoft'] }}; color: {{ $brand }}; font-size: 13px; font-weight: bold; }
        table.kennzahlen td.gross span { color: {{ $c['ink2'] }}; font-weight: normal; }
        h3 { font-size: 10.5px; margin: 7px 0 3px; color: {{ $brand }}; letter-spacing: .01em; page-break-after: avoid; }

        table.liste { width: 100%; border-collapse: collapse; table-layout: fixed; margin: 3px 0 6px; page-break-inside: auto; }
        table.liste thead { display: table-header-group; }
        table.liste tr { page-break-inside: avoid; }
        table.liste th, table.liste td { border: 1px solid {{ $c['line'] }}; padding: 2px 5px; text-align: left; vertical-align: top; overflow-wrap: anywhere; }
        table.liste th { background: {{ $c['soft'] }}; color: {{ $c['ink2'] }}; font-size: 8.5px; font-weight: bold; letter-spacing: .02em; }
        table.liste .right { text-align: right; white-space: nowrap; }
        table.liste td.menge { font-size: 11px; font-weight: bold; color: {{ $c['ink'] }}; }
        table.liste th.c-menge { width: 16%; }
        table.liste th.c-kurz { width: 8%; }
        .darreichung { margin: 3px 0 4px; color: {{ $c['ink2'] }}; }
        .darreichung strong { color: {{ $c['ink'] }}; }

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
    <span class="bt-label">Produktionsschein</span>
</div>
<main class="doc">
    @unless($pdf)
        <div class="actions">
            <div class="action-row">
                <span class="action-label">Profil:</span>
                @foreach($profile as $key => $label)
                    <a class="btn {{ ($opt['profil'] ?? '') === $key ? 'active' : '' }}" href="{{ request()->fullUrlWithQuery(array_merge($profilReset, ['profil' => $key, 'pdf' => null, 'csv' => null])) }}">{{ $label }}</a>
                @endforeach
                <a class="btn primary" href="{{ request()->fullUrlWithQuery(['pdf' => 1, 'csv' => null]) }}">PDF herunterladen</a>
                <a class="btn" href="javascript:window.print()">Drucken</a>
                <a class="btn" href="{{ request()->fullUrlWithQuery(['csv' => 1, 'pdf' => null]) }}">CSV</a>
            </div>
            <div class="action-row">
                <span class="action-label">Inhalte:</span>
                @foreach($filter as $key => $label)
                    <a class="btn {{ ($opt[$key] ?? false) ? 'active' : '' }}" href="{{ request()->fullUrlWithQuery([$key => ($opt[$key] ?? false) ? 0 : 1, 'pdf' => null, 'csv' => null]) }}">{{ $label }}</a>
                @endforeach
            </div>
            @if($posten->isNotEmpty() || $hatOhnePosten)
                <div class="action-row">
                    <span class="action-label">Posten:</span>
                    <a class="btn {{ ($opt['posten'] ?? '') === '' ? 'active' : '' }}" href="{{ request()->fullUrlWithQuery(['posten' => null, 'pdf' => null, 'csv' => null]) }}">Alle</a>
                    @foreach($posten as $id => $name)
                        <a class="btn {{ (string) ($opt['posten'] ?? '') === (string) $id ? 'active' : '' }}" href="{{ request()->fullUrlWithQuery(['posten' => $id, 'rezepte' => 1, 'pdf' => null, 'csv' => null]) }}">{{ $name }}</a>
                    @endforeach
                    @if($hatOhnePosten)
                        <a class="btn {{ ($opt['posten'] ?? '') === 'ohne' ? 'active' : '' }}" href="{{ request()->fullUrlWithQuery(['posten' => 'ohne', 'rezepte' => 1, 'pdf' => null, 'csv' => null]) }}">Nicht zugeteilt</a>
                    @endif
                </div>
            @endif
        </div>
    @endunless

    <header>
        <div class="kicker">Produktionsschein · {{ \Illuminate\Support\Carbon::parse($dok['production_date'])->format('d.m.Y') }}</div>
        <h1>Produktionsschein{{ !empty($dok['name']) ? ': ' . $dok['name'] : '' }}</h1>
        <div class="rule"></div>
        <div class="sub">
            {{ \Illuminate\Support\Carbon::parse($dok['production_date'])->format('d.m.Y') }} · {{ $dok['status_label'] }}
            @if($dok['reference']) · {{ $dok['reference'] }}@endif
            @if(($opt['posten'] ?? '') !== '') · Posten: {{ ($opt['posten'] ?? '') === 'ohne' ? 'Nicht zugeteilt' : ($posten[(string) $opt['posten']] ?? '—') }}@endif
        </div>
        @if(count($dok['ziele']) > 0)
            <div class="muted">{{ implode(' · ', $dok['ziele']) }}</div>
        @endif
        @if(($opt['notizen'] ?? false) && $dok['note'])<div class="notiz">Notiz: {{ $dok['note'] }}</div>@endif
    </header>

    @if($opt['rezepte'] ?? false)
    @forelse($zeilen as $z)
        <div class="rezept">
            <h2>{{ $z['name'] }}@if($z['ist_basisrezept']) <span class="chip">Basisrezept</span>@endif</h2>
            {{-- Spec 51: gedruckt wird EIN Wert. Gewaehlt wird im Editor, nicht auf dem Zettel. --}}
            @php($behaelter = ($opt['darreichung'] ?? false) ? \Platform\FoodAlchemist\Services\BehaelterBedarfService::kurz($z['darreichung']['behaelter_bedarf'] ?? null) : null)
            <table class="kennzahlen">
                <tr>
                    <td class="gross"><span>Ansätze</span>{{ rtrim(rtrim(number_format($z['ansaetze'], 2, ',', '.'), '0'), ',') }} Ansätze</td>
                    <td class="gross"><span>Menge</span>{{ $z['produzierte_menge_kg'] !== null ? number_format($z['produzierte_menge_kg'], 2, ',', '.') . ' kg' : '—' }}</td>
                    <td><span>Portionen</span>{{ $z['portionen'] !== null ? $z['portionen'] . ' Portionen' : '—' }}</td>
                    <td><span>Arbeitszeit</span>{{ $z['arbeitszeit_min'] !== null ? $z['arbeitszeit_min'] . ' min' : '—' }}</td>
                    <td><span>Posten</span>{{ $z['station'] ?? 'Nicht zugeteilt' }}</td>
                </tr>
                @if($behaelter !== null)
                    <tr><td colspan="5"><span>Behälter</span>{{ $behaelter }}</td></tr>
                @endif
            </table>

            @if(($opt['zutaten'] ?? false) && $z['zutaten'])
                <table class="liste zutaten">
                    <thead><tr><th>Zutat</th><th class="right c-menge">Menge</th></tr></thead>
                    <tbody>
                        @foreach($z['zutaten'] as $zu)
                            <tr><td>{{ $zu['name'] }}@if($zu['note']) <span class="muted">({{ $zu['note'] }})</span>@endif</td><td class="right menge">{{ $menge($zu['menge'], $zu['einheit']) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            @endif

            {{-- §3.2 Regeneration: das eingefrorene Programm je Komponente. Vorher stand es nur
                 als Einzeiler in `darreichung` — aus Skalaren, die kein Schreibpfad füllt. --}}
            @if(($opt['regeneration'] ?? false) && count($z['regenerationen'] ?? []))
                <h3>Regeneration</h3>
                <table class="liste">
                    <thead><tr><th>Komponente</th><th>Gerät</th><th class="right c-kurz">°C</th><th class="right c-kurz">min</th><th class="right c-kurz">Kern °C</th><th>Hinweis</th></tr></thead>
                    <tbody>
                    @foreach($z['regenerationen'] as $reg)
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

            @if($opt['anleitung'] ?? false)
                @if(!empty($z['schritte']) || !empty($z['zubereitung']))<h3>Anleitung</h3>@endif
                @include('foodalchemist::dokumente.partials.schritt-karten', [
                    'schritte' => $z['schritte'] ?? [],
                    'zubereitung' => $z['zubereitung'] ?? null,
                    'mitFotos' => $opt['bilder'] ?? false,
                    'istPdf' => $pdf,
                ])
            @endif

            {{-- §3.3 Anrichten: eingefrorene Schritte samt Fotos (Adressat ist der Pass). --}}
            @if(($opt['anrichten'] ?? false) && count($z['anrichte_schritte'] ?? []))
                <h3>Anrichten</h3>
                @include('foodalchemist::dokumente.partials.schritt-karten', [
                    'schritte' => $z['anrichte_schritte'],
                    'zubereitung' => null,
                    'mitFotos' => $opt['bilder'] ?? false,
                    'istPdf' => $pdf,
                ])
            @endif

            {{-- Der Behälter-Bedarf (Array) steht schon in den Kennzahlen; hier nur die Skalare
                 der Darreichung mit Küchen-Bezeichnung statt Feldname. --}}
            @if(($opt['darreichung'] ?? false) && $z['darreichung'])
                @php($darTeile = collect($z['darreichung'])->filter(fn ($v) => is_scalar($v) && $v !== ''))
                @if($darTeile->isNotEmpty())
                    <div class="darreichung">
                        <strong>Ausgabe:</strong>
                        {{ $darTeile->map(fn ($v, $k) => ($darreichungLabel[$k] ?? ucfirst(str_replace('_', ' ', (string) $k))) . ': ' . $v)->implode(' · ') }}
                    </div>
                @endif
            @endif
        </div>
    @empty
        <p class="muted">Keine Rezepte für den gewählten Posten.</p>
    @endforelse
    @endif
</main>
<div class="band-bottom">
    <span class="bb-foot">Erstellt mit Food.Alchemist · Produktionsschein</span>
</div>
</body>
</html>
