{{-- Spec 57 · Paket 6: Druck-Formate neben dem Wochenaushang.
     format = tag (Tischaufsteller: Zeltkarte A4 quer, mittig gefalzt, obere Hälfte kopfstehend) ·
     schild (je Linie ein Schild, A5 quer) · liste (Allergen- und Komponentenliste für den Ordner).
     Daten aus SpeiseplanService::ausgabeFormat — Kennzeichnung immer aus den Rezepten.
     Gäste-Drucke (tag, schild) tragen Logo + Farben aus Branding/Präsentations-Design ($optik). --}}
@php
    $diaetText = ['vegan' => 'vegan', 'vegetarisch' => 'vegetarisch', 'schwein' => 'mit Schwein', 'rind' => 'mit Rind', 'fisch' => 'mit Fisch', 'fleisch' => 'mit Fleisch'];
    $optik = ($optik ?? []) + ['logo' => null, 'footer' => null, 'akzent' => '#6d28d9', 'band' => '#6d28d9', 'text' => '#1a1712', 'muted' => '#5f5850', 'serif' => true, 'schrift_kopf' => 'Georgia, serif', 'schrift_text' => '"DejaVu Sans", Arial, sans-serif'];
    $zelt = $format === 'tag';
    // Zeltkarte: alle Einträge des Tages; ab 9 Einträgen eine weitere Zeltkarte statt Abschneiden.
    $zeltSeiten = [];
    if ($zelt) {
        foreach ($bloecke as $b) {
            $flach = [];
            foreach ($b['zeilen'] as $z) {
                foreach ($z['eintraege'] as $e) {
                    $flach[] = ['linie' => $z['linie'], 'color' => $z['color'], 'e' => $e];
                }
            }
            foreach ($flach === [] ? [[]] : array_chunk($flach, 8) as $teil) {
                $zeltSeiten[] = ['label' => $b['label'], 'eintraege' => $teil];
            }
        }
    }
@endphp
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>{{ ['tag' => 'Tischaufsteller', 'schild' => 'Linienschilder', 'liste' => 'Allergen- und Komponentenliste'][$format] ?? 'Speiseplan' }} — {{ $plan->name }}</title>
    @include('foodalchemist::dokumente.partials.speiseplan-druck-schrift')
    <style>
        * { box-sizing: border-box; }
        body { font-family: "DejaVu Sans", Arial, sans-serif; color: #1f2937; font-size: 11px; line-height: 1.45; margin: 0; padding: {{ $zelt && ($istPdf ?? false) ? '0' : '24px' }}; }
        .doc { max-width: {{ $zelt ? '297mm' : '820px' }}; margin: 0 auto; }
        @if($zelt && ! ($istPdf ?? false))
        @media screen { body { background: #e5e7eb; } .zelt { margin: 0 auto 24px; box-shadow: 0 2px 12px rgba(0,0,0,.15); } }
        /* Bildschirm-Vorschau: das Blatt ist 297 mm breit — in schmalen Fenstern verkleinert zeigen statt quer scrollen. */
        @media screen and (max-width: 1160px) { .zelt, .actions { zoom: .72; } }
        @media screen and (max-width: 860px) { .zelt, .actions { zoom: .5; } }
        @endif
        @media print { .zelt { margin: 0; box-shadow: none; } }
        .actions { margin-bottom: 16px; }
        .btn { display: inline-block; padding: 6px 12px; background: #6d28d9; color: #fff; text-decoration: none; border-radius: 6px; margin-right: 6px; }
        .btn.ghost { background: #eee; color: #374151; }
        @media print { .actions { display: none; } body { padding: 0; } }
        .codes { color: {{ $optik['akzent'] }}; font-size: 9px; font-weight: bold; }
        .muted { color: #6b7280; }
        .legende { margin-top: 14px; border-top: 1px solid #ececec; padding-top: 8px; font-size: 9px; color: #4b5563; }
        .legende .lg { margin-right: 10px; white-space: nowrap; line-height: 1.8; }
        .legende .c { color: {{ $optik['akzent'] }}; font-weight: bold; }
        .foot { margin-top: 14px; color: #9ca3af; font-size: 9px; }
        /* Tischaufsteller = Zeltkarte A4 quer, Falz bei 105 mm, obere Hälfte gedreht (steht dann richtig herum). */
        @if($zelt)
        @page { size: A4 landscape; margin: 0; }
        @endif
        .zelt { position: relative; width: 297mm; height: 210mm; overflow: hidden; background: #fff; page-break-after: always; }
        .zelt.letzte { page-break-after: auto; }
        /* Die gedrehte Hälfte trägt KEIN Padding: DomPDF dreht sonst um eine verschobene Mitte.
           Abstände sitzen im inneren .innen bzw. als absolute Offsets (.fuss). */
        .zelt .haelfte { position: absolute; left: 0; width: 297mm; height: 105mm; }
        .zelt .innen { padding: 9mm 14mm 0; }
        .zelt .oben { top: 0; transform: rotate(180deg); }
        .zelt .unten { top: 105mm; }
        .zelt .falz { position: absolute; left: 0; top: 105mm; width: 297mm; border-top: 0.3mm dashed #b8b8b8; }
        .zelt .kopf { height: 15mm; border-bottom: 0.6mm solid {{ $optik['band'] }}; margin-bottom: 4mm; }
        .zelt .kopf .logo { float: left; height: 12mm; }
        .zelt .kopf .logo img { max-height: 12mm; max-width: 60mm; }
        .zelt .kopf .tag { float: right; text-align: right; }
        .zelt .kopf .tag .t { font-family: {!! $optik['schrift_kopf'] !!}; font-size: 20pt; font-weight: bold; color: {{ $optik['text'] }}; line-height: 1.1; }
        .zelt .kopf .tag .m { font-size: 8pt; letter-spacing: .14em; text-transform: uppercase; color: {{ $optik['muted'] }}; }
        .zelt table.gerichte { width: 100%; border-collapse: collapse; }
        .zelt table.gerichte td { vertical-align: top; padding: 0 4mm 2.6mm 0; width: 50%; }
        .zelt .linie-name { font-size: 7pt; letter-spacing: .14em; text-transform: uppercase; font-weight: bold; }
        .zelt .gericht { font-family: {!! $optik['schrift_kopf'] !!}; font-weight: bold; color: {{ $optik['text'] }}; line-height: 1.15; }
        .zelt .wording { color: {{ $optik['muted'] }}; line-height: 1.25; }
        .zelt .merk { font-size: 7pt; color: {{ $optik['muted'] }}; margin-top: 0.6mm; }
        .zelt .merk .d { color: {{ $optik['akzent'] }}; font-weight: bold; }
        .zelt .preis { font-weight: bold; color: {{ $optik['akzent'] }}; }
        .zelt .fuss { position: absolute; left: 14mm; right: 14mm; bottom: 5mm; font-size: 6.5pt; color: {{ $optik['muted'] }}; line-height: 1.35; }
        .zelt .fuss .c { color: {{ $optik['akzent'] }}; font-weight: bold; }
        /* Linienschild (A5 quer) */
        @if($format === 'schild')
        @page { size: A5 landscape; margin: 10mm; }
        @endif
        .schild { border-top: 10px solid {{ $optik['band'] }}; padding: 18px 18px 10px; text-align: center; min-height: 300px; }
        .schild.umbruch { page-break-after: always; }
        .schild .logo img { max-height: 40px; max-width: 180px; }
        .schild .kopf { font-size: 11px; letter-spacing: .12em; text-transform: uppercase; color: {{ $optik['muted'] }}; margin-top: 6px; }
        .schild .gericht { font-family: {!! $optik['schrift_kopf'] !!}; font-size: 28px; font-weight: bold; line-height: 1.15; margin: 14px 0 6px; color: {{ $optik['text'] }}; }
        .schild .wording { font-size: 14px; color: {{ $optik['muted'] }}; }
        .schild .merkmale { margin-top: 12px; font-size: 11px; }
        .schild .merkmale span { border: 1px solid #9ca3af; border-radius: 4px; padding: 1px 6px; margin: 0 2px; line-height: 2; }
        .schild .preis { font-size: 30px; font-weight: bold; margin-top: 14px; color: {{ $optik['akzent'] }}; }
        .schild .leer { color: #9ca3af; font-size: 16px; margin-top: 40px; }
        .schild .legende { text-align: left; }
        /* Liste */
        h1 { font-size: 16px; margin: 0 0 2px; }
        h2 { font-size: 13px; margin: 16px 0 6px; color: #4c1d95; border-bottom: 2px solid #6d28d9; padding-bottom: 2px; }
        table.liste { width: 100%; border-collapse: collapse; }
        table.liste th, table.liste td { border-bottom: 1px solid #e5e7eb; padding: 4px 5px; text-align: left; vertical-align: top; }
        table.liste th { color: #6b7280; font-weight: normal; font-size: 10px; }
        td.komp { font-size: 10px; color: #4b5563; }
    </style>
</head>
<body>
<div class="doc">
    @unless($istPdf ?? false)
        <div class="actions">
            <a class="btn" href="?{{ http_build_query(array_merge(request()->query(), ['pdf' => 1])) }}">PDF herunterladen</a>
            <a class="btn ghost" href="javascript:window.print()">Drucken</a>
            @if($mitPreis)
                <a class="btn ghost" href="{{ request()->fullUrlWithQuery(['preise' => null, 'pdf' => null]) }}">→ ohne Preise</a>
            @else
                <a class="btn ghost" href="{{ request()->fullUrlWithQuery(['preise' => 1, 'pdf' => null]) }}">→ mit Preisen</a>
            @endif
        </div>
    @endunless

    @if($format === 'tag')
        @foreach($zeltSeiten as $seite)
            @php
                $n = count($seite['eintraege']);
                $zweispaltig = $n > 2;
                $gr = match (true) { $n <= 2 => 22, $n <= 4 => 17, $n <= 6 => 14, default => 12 };   // Platz je Hälfte ≈ 60 mm
                $mitWording = $n <= 6;
                $reihen = $zweispaltig ? array_chunk($seite['eintraege'], 2) : array_map(fn ($x) => [$x], $seite['eintraege']);
                $tagLegende = ['allergene' => [], 'zusatzstoffe' => []];
                $codesSeite = collect($seite['eintraege'])->flatMap(fn ($x) => $x['e']['codes'])->map(fn ($c) => rtrim($c, '*'))->unique()->all();
                foreach (['allergene', 'zusatzstoffe'] as $art) {
                    $tagLegende[$art] = array_values(array_filter($legende[$art] ?? [], fn ($l) => in_array($l['code'], $codesSeite, true)));
                }
            @endphp
            <div class="zelt {{ $loop->last ? 'letzte' : '' }}">
                @foreach(['oben', 'unten'] as $haelfte)
                    <div class="haelfte {{ $haelfte }}"><div class="innen">
                        <div class="kopf">
                            @if($optik['logo'])<div class="logo"><img src="{{ $optik['logo'] }}" alt=""></div>@endif
                            <div class="tag"><div class="m">{{ $mahlzeitLabel }}</div><div class="t">{{ $seite['label'] }}</div></div>
                        </div>
                        @if($n === 0)
                            <div class="wording" style="font-size: 14pt; text-align: center; margin-top: 12mm;">Für diesen Tag ist nichts geplant.</div>
                        @else
                            <table class="gerichte">
                                @foreach($reihen as $reihe)
                                    <tr>
                                        @foreach($reihe as $x)
                                            <td @unless($zweispaltig) style="width: 100%" @endunless>
                                                <div class="linie-name" style="color: {{ $x['color'] ?: $optik['akzent'] }}">{{ $x['linie'] }}</div>
                                                <div class="gericht" style="font-size: {{ $gr }}pt">{{ $x['e']['titel'] }}</div>
                                                @if($mitWording && $x['e']['untertitel'])<div class="wording" style="font-size: {{ max(8, (int) round($gr * 0.55)) }}pt">{{ $x['e']['untertitel'] }}</div>@endif
                                                <div class="merk">
                                                    @foreach($x['e']['diaet'] as $d)<span class="d">{{ $diaetText[$d] ?? $d }}</span> · @endforeach
                                                    {{ implode(', ', $x['e']['codes']) }}
                                                    @if($x['e']['vk'] !== null) · <span class="preis">{{ number_format((float) $x['e']['vk'], 2, ',', '.') }} €</span>@endif
                                                </div>
                                            </td>
                                        @endforeach
                                        @if($zweispaltig && count($reihe) === 1)<td></td>@endif
                                    </tr>
                                @endforeach
                            </table>
                        @endif
                        </div>
                        <div class="fuss">
                            @foreach($tagLegende['allergene'] as $a)<span class="c">{{ $a['code'] }}</span> {{ $a['label'] }} &nbsp;@endforeach
                            @foreach($tagLegende['zusatzstoffe'] as $z)<span class="c">{{ $z['code'] }}</span> {{ $z['label'] }} &nbsp;@endforeach
                            @if($tagLegende['allergene'] !== [] || $tagLegende['zusatzstoffe'] !== [])<br>* = Spuren möglich · @endif
                            Ohne Angabe = nicht bewertet, nicht „frei“. Fragen Sie gern unser Personal.@if($optik['footer']) · {{ $optik['footer'] }}@endif
                        </div>
                    </div>
                @endforeach
                <div class="falz"></div>
            </div>
        @endforeach
    @elseif($format === 'schild')
        @php
            $schilder = collect($bloecke)->flatMap(fn ($b) => collect($b['zeilen'])->map(fn ($z) => ['b' => $b, 'z' => $z]))->values();
        @endphp
        @foreach($schilder as $s)
            @php
                [$b, $z] = [$s['b'], $s['z']];
                $schildCodes = collect($z['eintraege'])->flatMap(fn ($e) => $e['codes'])->map(fn ($c) => rtrim($c, '*'))->unique()->all();
            @endphp
            <div class="schild {{ $loop->last ? '' : 'umbruch' }}" style="border-top-color: {{ $z['color'] ?: $optik['band'] }}">
                @if($optik['logo'])<div class="logo"><img src="{{ $optik['logo'] }}" alt=""></div>@endif
                <div class="kopf">{{ $z['linie'] }}{{ $z['plu'] ? ' · Kasse ' . $z['plu'] : '' }} · {{ $b['label'] }}</div>
                @forelse($z['eintraege'] as $e)
                    <div class="gericht">{{ $e['titel'] }}</div>
                    @if($e['untertitel'])<div class="wording">{{ $e['untertitel'] }}</div>@endif
                    <div class="merkmale">
                        @foreach($e['diaet'] as $d)<span>{{ $diaetText[$d] ?? $d }}</span>@endforeach
                        @foreach($e['codes'] as $c)<span>{{ $c }}</span>@endforeach
                    </div>
                    @if($e['vk'] !== null)<div class="preis">{{ number_format((float) $e['vk'], 2, ',', '.') }} €</div>@endif
                @empty
                    <div class="leer">Heute an dieser Ausgabe nichts geplant.</div>
                @endforelse
                @if($schildCodes !== [])
                    <div class="legende">
                        @foreach(array_merge($legende['allergene'] ?? [], $legende['zusatzstoffe'] ?? []) as $lg)
                            @if(in_array($lg['code'], $schildCodes, true))
                                <span class="lg"><span class="c">{{ $lg['code'] }}</span> = {{ $lg['label'] }}</span>
                            @endif
                        @endforeach
                        <span class="muted">· * = Spuren möglich</span>
                    </div>
                @endif
                @if($optik['footer'])<div class="foot">{{ $optik['footer'] }}</div>@endif
            </div>
        @endforeach
    @else
        <h1>Allergen- und Komponentenliste — {{ $plan->name }}</h1>
        <div class="muted">{{ $kwLabel }} · {{ $mahlzeitLabel }}</div>
        @foreach($bloecke as $b)
            <h2>{{ $b['label'] }}</h2>
            @if($b['zeilen'] === [])
                <div class="muted">Nichts geplant.</div>
            @else
                <table class="liste">
                    <thead><tr><th style="width:18%">Linie</th><th style="width:34%">Gericht</th><th>Komponenten</th><th style="width:16%">Kennzeichnung</th></tr></thead>
                    <tbody>
                        @foreach($b['zeilen'] as $z)
                            @foreach($z['eintraege'] as $e)
                                <tr>
                                    <td>{{ $z['linie'] }}</td>
                                    <td><strong>{{ $e['titel'] }}</strong>@if($e['untertitel'])<br><span class="muted">{{ $e['untertitel'] }}</span>@endif</td>
                                    <td class="komp">{{ collect($e['komponenten'])->map(fn ($k) => trim($k['name'] . ' ' . ($k['menge'] ?? '')))->implode(' · ') ?: '—' }}</td>
                                    <td><span class="codes">{{ implode(', ', $e['codes']) ?: '—' }}</span></td>
                                </tr>
                            @endforeach
                        @endforeach
                    </tbody>
                </table>
            @endif
        @endforeach
    @endif

    @if($format === 'liste' && (!empty($legende['allergene']) || !empty($legende['zusatzstoffe'])))
        <div class="legende">
            @foreach($legende['allergene'] ?? [] as $a)<span class="lg"><span class="c">{{ $a['code'] }}</span> = {{ $a['label'] }}</span>@endforeach
            @foreach($legende['zusatzstoffe'] ?? [] as $z)<span class="lg"><span class="c">{{ $z['code'] }}</span> = {{ $z['label'] }}</span>@endforeach
            <div class="muted" style="margin-top:4px;">* = Spuren möglich. Angaben aus den hinterlegten Rezepturen; ohne Angabe = nicht bewertet, nicht „frei“.</div>
        </div>
    @endif
    @if($format === 'liste')<div class="foot">Erstellt mit Food Alchemist · {{ $erzeugt }}</div>@endif
</div>
</body>
</html>
